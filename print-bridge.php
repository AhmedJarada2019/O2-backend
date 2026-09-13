<?php

/**
 * print-bridge.php — جسر طباعة محلي لطابعة USB
 * ──────────────────────────────────────────────────────────────
 * لازم يشتغل بشكل دائم على نفس جهاز الكاشير (ويندوز) الموصولة فيه الطابعة USB.
 * بيفتح بورت شبكي محلي (127.0.0.1:9100 افتراضيًا — نفس بورت الطابعات الحرارية
 * الشبكية القياسي) وبيمرر أي بيانات وحدة أوامر خام (ESC/POS) توصله مباشرة
 * للطابعة عبر WindowsPrintConnector.
 *
 * الفايدة: النظام الرئيسي (EscPosPrinterDriver -> NetworkPrintConnector) ما
 * بيحتاج يعرف إنه الطابعة USB أصلاً — بس نضبط سجل الطابعة بقاعدة البيانات:
 *   ip_address = 127.0.0.1
 *   port       = 9100 (أو أي بورت حددته بـ--listen)
 * وبهيك منتجنب مشاركة الطابعة عبر الشبكة (smb://) اللي كانت عرضة لمشاكل
 * صلاحيات/بروتوكول SMB. اسم الطابعة نفسه (زي "XP-80C") لازم يكون مثبّت
 * بويندوز (تعريف الطابعة USB عادي، مش لازم تشاركه على الشبكة).
 *
 * الاستخدام:
 *   php print-bridge.php                              → يستخدم القيم الافتراضية
 *   php print-bridge.php --printer="XP-80C"            → اسم طابعة مختلف
 *   php print-bridge.php --printer="XP-80C" --listen=127.0.0.1:9100
 *   أو عبر متغيرات بيئة: PRINT_BRIDGE_PRINTER, PRINT_BRIDGE_LISTEN
 *
 * التشغيل الدائم: استخدم start-print-bridge.bat (بنفس أسلوب start-queue-worker.bat)
 * — يعيد التشغيل تلقائيًا لو وقع، وسجّله Scheduled Task عند بدء تشغيل ويندوز.
 */

require __DIR__ . '/vendor/autoload.php';

use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;

function bridge_arg(array $argv, string $name, string $envKey, string $default): string
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, "--{$name}=")) {
            return substr($arg, strlen("--{$name}="));
        }
    }
    $env = getenv($envKey);
    return $env !== false && $env !== '' ? $env : $default;
}

$printerName = bridge_arg($argv, 'printer', 'PRINT_BRIDGE_PRINTER', 'XP-80C');
$listenAddr  = bridge_arg($argv, 'listen', 'PRINT_BRIDGE_LISTEN', '127.0.0.1:9100');
$logFile     = __DIR__ . '/storage/logs/print-bridge.log';

function bridge_log(string $logFile, string $line): void
{
    $stamped = '[' . date('Y-m-d H:i:s') . '] ' . $line;
    echo $stamped . "\n";
    @file_put_contents($logFile, $stamped . "\n", FILE_APPEND | LOCK_EX);
}

if (!is_dir(dirname($logFile))) {
    @mkdir(dirname($logFile), 0777, true);
}

$server = stream_socket_server("tcp://{$listenAddr}", $errno, $errstr);
if (!$server) {
    bridge_log($logFile, "❌ فشل فتح البورت {$listenAddr}: {$errstr} ({$errno})");
    exit(1);
}

bridge_log($logFile, "✅ الجسر شغّال على {$listenAddr} — بيمرر للطابعة '{$printerName}'");

while (true) {
    $client = @stream_socket_accept($server, -1);
    if (!$client) {
        continue;
    }

    stream_set_timeout($client, 10);

    $data = '';
    while (!feof($client)) {
        $chunk = fread($client, 8192);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $data .= $chunk;
    }
    fclose($client);

    if ($data === '') {
        continue;
    }

    try {
        $connector = new WindowsPrintConnector($printerName);
        $connector->write($data);
        $connector->finalize();
        bridge_log($logFile, "🖨️  طُبعت {$listenAddr} → '{$printerName}' (" . strlen($data) . " بايت)");
    } catch (\Throwable $e) {
        bridge_log($logFile, "❌ فشلت الطباعة على '{$printerName}': " . $e->getMessage());
    }
}
