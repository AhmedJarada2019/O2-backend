<?php

namespace App\Services\Printing\Renderers;

use App\Models\Order;
use Spatie\Browsershot\Browsershot;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Builds thermal receipt images from Blade templates using Browsershot.
 *
 * This completely replaces the old GD-based rendering approach.
 * Arabic text, RTL layout, and styling are handled by the HTML/CSS/Blade templates
 * and rendered to a high-resolution PNG via Chrome/Puppeteer (Browsershot).
 *
 * Designed for 80mm (550px width) thermal printers via ESC/POS graphics commands.
 */
class ReceiptImageBuilder
{
    /**
     * Render a cashier invoice receipt from the invoice.blade.php template.
     *
     * @param  Order  $order  The order model with items, totals, etc.
     * @return string         Absolute path to the generated PNG image.
     */
    public function buildInvoiceReceipt(Order $order): string
    {
        $html = view('receipts.invoice', compact('order'))->render();

        return $this->renderHtmlToImage($html);
    }

    /**
     * Render a filtered invoice receipt — only specific items for a cashier printer.
     *
     * @param  Order  $order       The order model.
     * @param  string $printerName Name of the destination printer.
     * @param  array  $items       Filtered items: ['item_id','name','quantity','price','total','notes']
     * @return string              Absolute path to the generated PNG image.
     */
    public function buildFilteredInvoiceReceipt(Order $order, string $printerName, array $items): string
    {
        // Convert items to objects so the Blade template can use -> property access
        $filteredItems = array_map(function ($item) {
            if (is_object($item)) {
                return $item;
            }
            return (object) [
                'item_id'     => $item['item_id'] ?? 0,
                'item_name'   => $item['name'] ?? $item['item_name'] ?? 'صنف',
                'item_name_ar'=> $item['name_ar'] ?? $item['item_name_ar'] ?? $item['name'] ?? 'صنف',
                'quantity'    => $item['quantity'] ?? 1,
                'price'       => $item['price'] ?? 0,
                'total'       => $item['total'] ?? ($item['price'] ?? 0) * ($item['quantity'] ?? 1),
                'notes'       => $item['notes'] ?? null,
            ];
        }, $items);

        // Calculate filtered total
        $filteredTotal = array_sum(array_map(fn($i) => $i->total, $filteredItems));

        $viewData = [
            'order'          => $order,
            'filteredItems'  => $filteredItems,
            'filteredTotal'  => $filteredTotal,
            'printerName'    => $printerName,
        ];

        $html = view('receipts.invoice', $viewData)->render();

        return $this->renderHtmlToImage($html);
    }

    /**
     * Render a kitchen order ticket (KOT) from the kot.blade.php template.
     *
     * @param  Order     $order         The order model.
     * @param  string    $printerName   Name of the destination printer (e.g. "مطبخ رئيسي").
     * @param  array|null $sectionItems Optional filtered items for section-specific KOTs.
     *                                  Each item should be an object or array with:
     *                                  - item_name_ar / item_name
     *                                  - quantity
     *                                  - notes
     * @return string                   Absolute path to the generated PNG image.
     */
    public function buildKotReceipt(Order $order, string $printerName, ?array $sectionItems = null, ?array $meta = null): string
    {
        $viewData = [
            'order'    => $order,
            'printJob' => (object) [
                'printer' => (object) ['name' => $printerName],
            ],
            'kotMeta'  => $meta,
        ];

        // If section-specific items are provided (for department-filtered KOTs),
        // pass them so the template can render the filtered list instead of all items.
        // The Blade template checks for $sectionItems ?? $order->items.
        if ($sectionItems !== null) {
            // Convert arrays to stdClass objects so the template's Eloquent-style
            // property access ($item->item_name_ar, $item->quantity, etc.) works.
            $viewData['sectionItems'] = array_map(function ($item) {
                if (is_object($item)) {
                    return $item;
                }
                return (object) [
                    'item_name_ar' => $item['item_name_ar'] ?? $item['item_name'] ?? 'صنف',
                    'item_name'    => $item['item_name'] ?? $item['item_name_ar'] ?? '',
                    'quantity'     => (int) ($item['quantity'] ?? 1),
                    'notes'        => $item['notes'] ?? '',
                    'price'        => (float) ($item['price'] ?? 0),
                ];
            }, $sectionItems);
        }

        $html = view('receipts.kot', $viewData)->render();

        return $this->renderHtmlToImage($html);
    }

    /**
     * Convert HTML string to a high-resolution PNG image using Browsershot,
     * then resize it to match the printer's dot width.
     *
     * Browsershot renders at 2x scale for crisp text, then we downscale
     * to exactly `dots_per_line` pixels wide so the ESC/POS raster data
     * matches the printer's physical head width.
     *
     * @param  string  $html  The full HTML document to render.
     * @return string         Absolute path to the saved PNG file.
     */
    private function renderHtmlToImage(string $html): string
    {
        $this->cleanupOldTempFiles();

        $rawPath     = storage_path('app/receipt_raw_' . uniqid('', true) . '.png');
        $targetWidth = (int) config('printing.dots_per_line', 576);

        try {
            $renderedVia = $this->renderViaServer($html, $rawPath)
                ? 'render-server'
                : ($this->renderViaBrowsershot($html, $rawPath) ? 'browsershot' : null);

            if ($renderedVia === null) {
                throw new \RuntimeException('فشل رندر الإيصال عبر render-server وBrowsershot معاً.');
            }

            // ── Resize + Crop to content ────────────────────────────────
            // The raw image is ~1100px wide (550×2). We resize to
            // $targetWidth (e.g. 576) then crop empty space from the
            // bottom so the paper length matches the actual order content.
            $processedPath = $this->resizeAndCrop($rawPath, $targetWidth);

            if ($processedPath) {
                @unlink($rawPath);
                $actualPath = $processedPath;
            } else {
                $actualPath = $rawPath;
            }

            Log::info('Receipt image rendered', [
                'via'          => $renderedVia,
                'path'         => $actualPath,
                'size'         => file_exists($actualPath) ? filesize($actualPath) : 0,
                'target_width' => $targetWidth,
            ]);

            return $actualPath;

        } catch (\Exception $e) {
            Log::error('Receipt rendering failed', [
                'error' => $e->getMessage(),
            ]);
            @unlink($rawPath);
            throw $e;
        }
    }

    /**
     * Try rendering via the persistent render-server.js (Node/Puppeteer with
     * a warm Chrome instance — ~100-200ms instead of the 1-2s it takes
     * Browsershot to cold-launch Chrome on every single receipt).
     *
     * Returns false (never throws) on any failure, so the caller can fall
     * back to Browsershot transparently — printing must never break just
     * because the render-server isn't running on this machine yet.
     */
    private function renderViaServer(string $html, string $rawPath): bool
    {
        if (! config('printing.render_server_enabled', true)) {
            return false;
        }

        $url = config('printing.render_server_url');
        if (! $url) {
            return false;
        }

        try {
            $response = Http::connectTimeout((float) config('printing.render_server_connect_timeout', 1))
                ->timeout((float) config('printing.render_server_timeout', 8))
                ->post($url, [
                    'html'              => $html,
                    'width'             => 550,
                    'height'            => 850,
                    'deviceScaleFactor' => 1,
                ]);

            $contentType = $response->header('Content-Type');

            if (! $response->successful() || $response->body() === '' || ! str_starts_with((string) $contentType, 'image/')) {
                Log::warning('Render server returned a non-usable response, falling back to Browsershot', [
                    'status'        => $response->status(),
                    'content_type'  => $contentType,
                    'body_length'   => strlen($response->body()),
                    'body_preview'  => substr($response->body(), 0, 200),
                    'html_length'   => strlen($html),
                ]);
                return false;
            }

            file_put_contents($rawPath, $response->body());

            return true;
        } catch (\Throwable $e) {
            // Connection refused / timeout / render-server not started yet — expected on
            // machines where the service hasn't been set up. Fall back silently.
            Log::info('Render server unavailable, falling back to Browsershot', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Render via Browsershot — cold-launches a fresh Chrome process per call.
     * Kept as the reliable fallback when render-server.js isn't running.
     */
    private function renderViaBrowsershot(string $html, string $rawPath): bool
    {
        $browsershot = Browsershot::html($html)
            ->windowSize(550, 850)
            ->deviceScaleFactor(1)
            ->noSandbox();

        if ($chromePath = config('printing.browsershot_chrome_path')) {
            $browsershot->setChromePath($chromePath);
        }
        if ($nodePath = config('printing.browsershot_node_path')) {
            $browsershot->setNodePath($nodePath);
        }
        if ($npmPath = config('printing.browsershot_npm_path')) {
            $browsershot->setNpmPath($npmPath);
        }

        $puppeteerDir = base_path('node_modules/puppeteer');
        if (is_dir($puppeteerDir)) {
            $browsershot->setNodeModulePath(base_path('node_modules'));
        }

        $browsershot->addChromiumArguments([
            'disable-gpu',
            'disable-dev-shm-usage',
            'disable-extensions',
            'disable-background-networking',
            'disable-sync',
            'disable-translate',
            'mute-audio',
            'no-first-run',
        ]);

        $browsershot->save($rawPath);

        return true;
    }

    /**
     * Resize a PNG to the printer width, then crop empty whitespace from the bottom.
     * This ensures the paper length matches the actual order content.
     *
     * @param  string $sourcePath  Path to the source PNG.
     * @param  int    $targetWidth Target width in pixels (e.g. 576 for 80mm).
     * @return string|null         Path to processed file, or null on failure.
     */
    private function resizeAndCrop(string $sourcePath, int $targetWidth): ?string
    {
        if (!function_exists('imagecreatefrompng')) {
            return null;
        }

        $image = @imagecreatefrompng($sourcePath);
        if (!$image) {
            return null;
        }

        $origWidth  = imagesx($image);
        $origHeight = imagesy($image);

        if ($origWidth <= 0 || $origHeight <= 0) {
            imagedestroy($image);
            return null;
        }

        // ── Step 1: Resize to printer width ────────────────────────────
        $targetHeight = (int) round($origHeight * ($targetWidth / $origWidth));

        $resized = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefilledrectangle($resized, 0, 0, $targetWidth, $targetHeight, $transparent);

        imagecopyresampled(
            $resized, $image,
            0, 0, 0, 0,
            $targetWidth, $targetHeight,
            $origWidth, $origHeight
        );
        imagedestroy($image);

        // ── Step 2: Scan from bottom to find last non-white row ────────
        // White = background. We crop everything below the last row that
        // has any visible content (text, borders, lines).
        $cropRow = 0; // will hold the Y of the last content row

        for ($y = $targetHeight - 1; $y >= 0; $y--) {
            $foundContent = false;
            for ($x = 0; $x < $targetWidth; $x++) {
                $rgb = imagecolorat($resized, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                // Anything darker than near-white is content.
                if ($r < 245 || $g < 245 || $b < 245) {
                    $foundContent = true;
                    break;
                }
            }
            if ($foundContent) {
                $cropRow = $y;
                break;
            }
        }

        // ── Step 3: Crop ───────────────────────────────────────────────
        // Add padding (5px) below the last content row. If the scan found
        // no content at all (a genuinely blank render, or a render whose
        // content sits above where we expect), $cropRow stays at 0 - never
        // let that collapse the image to a near-zero height, which the
        // ESC/POS driver correctly refuses to print as "invalid".
        $finalHeight = min(max($cropRow + 5, 100), $targetHeight);

        // Only crop if there's meaningful whitespace to remove (>10 rows).
        if ($targetHeight - $finalHeight > 10) {
            $cropped = imagecreatetruecolor($targetWidth, $finalHeight);
            imagealphablending($cropped, false);
            imagesavealpha($cropped, true);
            $bg = imagecolorallocate($cropped, 255, 255, 255);
            imagefilledrectangle($cropped, 0, 0, $targetWidth, $finalHeight, $bg);

            imagecopy($cropped, $resized, 0, 0, 0, 0, $targetWidth, $finalHeight);
            imagedestroy($resized);
            $resized = $cropped;
        }

        $destPath = storage_path('app/receipt_' . uniqid('', true) . '.png');
        $ok = imagepng($resized, $destPath, 6);
        imagedestroy($resized);

        return $ok ? $destPath : null;
    }

    /**
     * Remove previously generated receipt images to prevent file access conflicts.
     *
     * Windows can throw "Access is denied" if an old file handle is still held
     * by the printer driver. Cleaning up before creating a new file helps avoid this.
     */
    private function cleanupOldTempFiles(): void
    {
        // IMPORTANT: multiple print jobs (queue worker + department tickets
        // within the same order + concurrent orders) can be rendering at
        // the same time, each with its own receipt_*.png in flight. This
        // used to delete EVERY matching file unconditionally on every call,
        // which could delete another job's file between it being rendered
        // and it being read for printing - producing a "0 byte" image out
        // of nowhere with no error at the point it went missing. Only
        // sweep files old enough that nothing could still be using them.
        $pattern = storage_path('app/receipt_*.png');
        $files = glob($pattern);

        if ($files === false) {
            return;
        }

        $maxAgeSeconds = 120;
        $now = time();

        foreach ($files as $file) {
            if (file_exists($file) && ($now - filemtime($file)) > $maxAgeSeconds) {
                @unlink($file);
            }
        }
    }

    /**
     * Render a department production ticket from ticket.blade.php.
     *
     * @param  Order     $order  The order model.
     * @param  object    $ticket The ProductionTicket model with department and ticketItems loaded.
     * @return string            Absolute path to the generated PNG image.
     */
    public function buildTicketReceipt(Order $order, object $ticket): string
    {
        $html = view('receipts.ticket', compact('order', 'ticket'))->render();

        return $this->renderHtmlToImage($html);
    }

    /**
     * Build a compact department ticket for cashier printer sections.
     * Uses department-ticket.blade.php — a small, compressed ticket
     * showing section name + filtered items.
     *
     * @param  Order  $order       The order model.
     * @param  string $sectionName The department/section name (e.g., "مطبخ الشاورما").
     * @param  array  $sectionItems Array of formatted items (name, quantity, notes, etc.).
     * @return string              Absolute path to the generated PNG image.
     */
    public function buildDepartmentTicketReceipt(
        Order $order,
        string $sectionName,
        array $sectionItems
    ): string {
        // Convert arrays to stdClass so template property access works
        $items = array_map(function ($item) {
            if (is_object($item)) {
                return $item;
            }
            return (object) [
                'item_name_ar' => $item['item_name_ar'] ?? $item['item_name'] ?? $item['name'] ?? '—',
                'item_name'    => $item['item_name'] ?? $item['name'] ?? '',
                'quantity'     => (int) ($item['quantity'] ?? 1),
                'notes'        => $item['notes'] ?? '',
                'price'        => (float) ($item['price'] ?? 0),
                'total'        => (float) ($item['total'] ?? 0),
            ];
        }, $sectionItems);

        $html = view('receipts.department-ticket', [
            'order'        => $order,
            'sectionName'  => $sectionName,
            'sectionItems' => $items,
        ])->render();

        return $this->renderHtmlToImage($html);
    }

    /**
     * Generate a preview PNG for visual inspection (not for printing).
     * Returns the absolute path to the preview image.
     */
    public function generatePreview(Order $order): string
    {
        return $this->buildInvoiceReceipt($order);
    }
}
