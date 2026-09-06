#!/usr/bin/env node
/**
 * render-server.js — خدمة رندر دائمة لإيصالات الطباعة (HTML → PNG عبر Chrome).
 * ──────────────────────────────────────────────────────────────
 * لازم تضل شغّالة دايماً (زي print-bridge.php وqueue worker). بتفتح Chrome
 * headless مرة وحدة عند الإقلاع وتخليه مفتوح، وبعدين كل طلب طباعة بس بيفتح
 * صفحة جديدة بنفس المتصفح (سريع، ~50-150ms) بدل ما يفتح Chrome كامل من
 * الصفر (بطيء، 1-2 ثانية) متل ما كان عم يصير عبر Browsershot بكل مرة.
 *
 * الاستخدام:
 *   node render-server.js
 *   node render-server.js --port=4790
 *   أو عبر متغيرات بيئة: RENDER_SERVER_PORT, RENDER_SERVER_HOST
 *
 * التشغيل الدائم: استخدم start-render-server.bat (بنفس أسلوب
 * start-print-bridge.bat / start-queue-worker.bat).
 *
 * الـAPI:
 *   GET  /health  → { ok: true, chromeReady: true }
 *   POST /render  → body: { html, width?, height?, deviceScaleFactor? }
 *                   response: image/png (binary)
 */
import http from 'http';
import fs from 'fs';
import path from 'path';
import puppeteer from 'puppeteer';

function bridgeArg(name, envKey, fallback) {
    const prefix = `--${name}=`;
    const fromArgv = process.argv.find((a) => a.startsWith(prefix));
    if (fromArgv) return fromArgv.slice(prefix.length);
    const fromEnv = process.env[envKey];
    return fromEnv && fromEnv !== '' ? fromEnv : fallback;
}

/**
 * يقرأ قيمة من ملف .env بجذر المشروع (نفس الملف يلي Laravel عم يستخدمه).
 * مفيدة عشان نعيد استخدام BROWSERSHOT_CHROME_PATH — المسار يلي Browsershot
 * أصلاً مضبوط عليه ومجرّب إنه شغّال — بدل ما نحتاج Puppeteer يحمّل نسخة
 * Chrome خاصة فيه لحاله (يلي غالباً مش موجودة على أجهزة الكاشير).
 */
function readEnvValue(key) {
    try {
        const envPath = path.join(process.cwd(), '.env');
        const content = fs.readFileSync(envPath, 'utf8');
        const match = content.match(new RegExp(`^${key}=(.*)$`, 'm'));
        if (!match) return null;
        return match[1].trim().replace(/^["']|["']$/g, '') || null;
    } catch {
        return null;
    }
}

const PORT = parseInt(bridgeArg('port', 'RENDER_SERVER_PORT', '4790'), 10);
const HOST = bridgeArg('host', 'RENDER_SERVER_HOST', '127.0.0.1');
const CHROME_PATH =
    bridgeArg('chrome-path', 'RENDER_SERVER_CHROME_PATH', null) ||
    readEnvValue('BROWSERSHOT_CHROME_PATH');
const MAX_BODY_BYTES = 20 * 1024 * 1024; // 20MB cap على حجم الـHTML المرسل

const CHROMIUM_ARGS = [
    '--no-sandbox',
    '--disable-gpu',
    '--disable-dev-shm-usage',
    '--disable-extensions',
    '--disable-background-networking',
    '--disable-sync',
    '--disable-translate',
    '--mute-audio',
    '--no-first-run',
];

function log(msg) {
    console.log(`[${new Date().toISOString()}] ${msg}`);
}

let browser = null;
let launching = null;

// Serialize renders one-at-a-time. Concurrent Puppeteer renders against a
// single shared browser have been observed to occasionally corrupt/truncate
// screenshots on this environment (first request in isolation always
// succeeds; overlapping rapid requests sometimes don't) - queuing avoids
// that class of race entirely, at the cost of ~0.2-2s of extra wait when
// multiple receipts print back-to-back for the same order.
let renderQueue = Promise.resolve();
function queueRender(fn) {
    const result = renderQueue.then(fn, fn);
    renderQueue = result.then(
        () => {},
        () => {}
    );
    return result;
}

async function getBrowser() {
    if (browser && browser.connected) {
        return browser;
    }
    if (launching) {
        return launching;
    }
    const launchOptions = { headless: true, args: CHROMIUM_ARGS };
    if (CHROME_PATH) {
        launchOptions.executablePath = CHROME_PATH;
    }

    launching = puppeteer
        .launch(launchOptions)
        .then((b) => {
            browser = b;
            launching = null;
            browser.on('disconnected', () => {
                log('⚠️  Chrome انقطع، رح ينفتح من جديد بأول طلب جاي.');
                browser = null;
            });
            return b;
        })
        .catch((err) => {
            launching = null;
            throw err;
        });
    return launching;
}

async function renderHtml(html, width, height, deviceScaleFactor) {
    const b = await getBrowser();
    const page = await b.newPage();

    const pageErrors = [];
    page.on('pageerror', (err) => pageErrors.push(String(err)));
    page.on('console', (msg) => {
        if (msg.type() === 'error') pageErrors.push(`console.error: ${msg.text()}`);
    });

    try {
        await page.setViewport({
            width: width || 550,
            height: height || 850,
            deviceScaleFactor: deviceScaleFactor || 1,
        });
        await page.setContent(html, { waitUntil: ['load', 'networkidle0'] });
        // Local @font-face (Arabic Tahoma) can finish loading slightly after
        // the 'load' event fires — screenshotting too early produces a
        // blank/near-empty PNG. Wait for fonts explicitly (with a safety
        // timeout so a stuck font load can never hang a print job).
        try {
            await Promise.race([
                page.evaluate(() => document.fonts.ready.then(() => true)),
                new Promise((resolve) => setTimeout(resolve, 1000)),
            ]);
        } catch {
            // ignore — fall through and screenshot with whatever rendered
        }

        const bodyMetrics = await page
            .evaluate(() => ({
                scrollHeight: document.body ? document.body.scrollHeight : -1,
                scrollWidth: document.body ? document.body.scrollWidth : -1,
                childCount: document.body ? document.body.childElementCount : -1,
                bodyExists: !!document.body,
            }))
            .catch((e) => ({ evalError: String(e) }));

        // page.screenshot({ fullPage: true }) has a real bug on this Chrome
        // build: when the actual content is SHORTER than the viewport height
        // we set above (e.g. a short receipt with 1-2 items in an 850px
        // viewport), it returns a blank/near-blank image instead of the
        // content — confirmed by direct comparison (fullPage:true -> 3078
        // bytes blank, fullPage:false at the same content -> 41027 bytes,
        // fully correct). Sizing the viewport to the ACTUAL measured content
        // height and taking a normal (non-fullPage) screenshot avoids the
        // bug entirely, for both short AND long receipts.
        const measuredHeight = Math.max(bodyMetrics.scrollHeight || 0, 50);
        await page.setViewport({
            width: width || 550,
            height: measuredHeight,
            deviceScaleFactor: deviceScaleFactor || 1,
        });

        const png = await page.screenshot({ type: 'png', fullPage: false });

        // A receipt should always be at least a few KB (logo/header/border
        // text). A tiny output means the page rendered essentially blank -
        // log everything needed to diagnose it without needing a repro.
        if (png.length < 2000) {
            log(
                `WARNING: suspiciously small render (${png.length} bytes). ` +
                    `bodyMetrics=${JSON.stringify(bodyMetrics)} ` +
                    `pageErrors=${JSON.stringify(pageErrors)} ` +
                    `htmlLength=${html.length} htmlPreview=${JSON.stringify(html.slice(0, 500))}`
            );
        }

        // Chrome/Edge occasionally disconnects mid-screenshot (e.g. Edge
        // auto-updating itself in the background). When that happens the
        // CDP transport can hand back a truncated/corrupt buffer instead of
        // throwing. Verify the PNG signature so we NEVER report success for
        // garbage data — better to fail this render (PHP falls back to
        // Browsershot, or the driver refuses to print) than silently print
        // a corrupt image.
        const PNG_SIGNATURE = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);
        if (png.length < 200 || !png.subarray(0, 8).equals(PNG_SIGNATURE)) {
            throw new Error(
                `Corrupt screenshot (${png.length} bytes, browser likely disconnected mid-render). ` +
                    `chromeConnected=${browser ? browser.connected : false}`
            );
        }

        return png;
    } finally {
        await page.close().catch(() => {});
    }
}

function readBody(req) {
    return new Promise((resolve, reject) => {
        const chunks = [];
        let size = 0;
        req.on('data', (chunk) => {
            size += chunk.length;
            if (size > MAX_BODY_BYTES) {
                reject(new Error('Payload too large'));
                req.destroy();
                return;
            }
            chunks.push(chunk);
        });
        req.on('end', () => resolve(Buffer.concat(chunks)));
        req.on('error', reject);
    });
}

const server = http.createServer(async (req, res) => {
    log(`>>> Incoming request: ${req.method} ${req.url} from ${req.socket.remoteAddress}:${req.socket.remotePort}`);

    if (req.method === 'GET' && req.url === '/health') {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ ok: true, chromeReady: !!browser }));
        return;
    }

    if (req.method === 'POST' && req.url === '/render') {
        try {
            const bodyBuf = await readBody(req);
            const payload = JSON.parse(bodyBuf.toString('utf8'));
            if (!payload || typeof payload.html !== 'string' || payload.html === '') {
                res.writeHead(400, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ error: 'Missing "html" field' }));
                return;
            }
            const png = await queueRender(() =>
                renderHtml(payload.html, payload.width, payload.height, payload.deviceScaleFactor)
            );
            log(`Sending response: ${png.length} bytes, first8=${png.subarray(0, 8).toString('hex')}`);
            res.writeHead(200, { 'Content-Type': 'image/png', 'Content-Length': png.length });
            res.end(png, () => {
                log(`Response fully flushed: ${png.length} bytes, bytesWritten=${res.socket ? res.socket.bytesWritten : 'n/a'}`);
            });
        } catch (err) {
            log(`❌ render failed: ${err.message}`);
            res.writeHead(500, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ error: err.message }));
        }
        return;
    }

    res.writeHead(404, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ error: 'Not found' }));
});

server.listen(PORT, HOST, async () => {
    log(`✅ Render server شغّال على http://${HOST}:${PORT}`);
    log(`   Chrome path: ${CHROME_PATH || '(افتراضي Puppeteer — bundled Chrome)'}`);
    try {
        await getBrowser();
        log('✅ Chrome جاهز ومفتوح.');
    } catch (err) {
        log(`⚠️  فشل تجهيز Chrome مسبقاً (رح يعاد المحاولة بأول طلب): ${err.message}`);
    }
});

async function shutdown() {
    if (browser) {
        await browser.close().catch(() => {});
    }
    process.exit(0);
}
process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);
