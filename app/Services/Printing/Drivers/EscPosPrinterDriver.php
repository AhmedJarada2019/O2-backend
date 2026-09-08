<?php

namespace App\Services\Printing\Drivers;

use App\Models\Printer;
use App\Services\Printing\Contracts\PrinterDriverInterface;
use Illuminate\Support\Facades\Log;
use Mike42\Escpos\CapabilityProfile;
use Mike42\Escpos\EscposImage;
use Mike42\Escpos\Printer as EscposPrinter;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;

class EscPosPrinterDriver implements PrinterDriverInterface
{
    /**
     * Send raw ESC/POS text content to a printer.
     */
    public function send(Printer $printer, string $content): array
    {
        $escpos = null;

        try {
            $escpos = $this->connect($printer);
            $escpos->text($content);
            $escpos->feed(4);
            $escpos->cut();

            return $this->success($printer);
        } catch (\Exception $e) {
            return $this->error($printer, $e);
        } finally {
            $this->close($escpos);
        }
    }

    /**
     * Execute a callback with the raw ESC/POS printer object.
     * The callback must NOT call cut() or close() — the driver handles that.
     */
    public function sendWithClosure(Printer $printer, callable $callback): array
    {
        $escpos = null;

        try {
            $escpos = $this->connect($printer);
            $callback($escpos);
            $escpos->feed(4);
            $escpos->cut();

            return $this->success($printer);
        } catch (\Exception $e) {
            return $this->error($printer, $e);
        } finally {
            $this->close($escpos);
        }
    }

    /**
     * Print a receipt image (the entire receipt rendered as a single PNG).
     * This is the primary method for Arabic printing.
     */
    public function printReceiptImage(Printer $printer, string $imagePath): array
    {
        $escpos = null;

        try {
            $escpos = $this->connect($printer);

            // Validate the file directly via getimagesize() BEFORE handing it
            // to EscposImage. EscposImage::getWidth()/getHeight() only
            // reflect real values *after* something has triggered its lazy
            // pixel-loading (e.g. toRasterFormat(), called internally by
            // bitImage() below) — calling them beforehand always returns 0
            // regardless of whether the image is actually valid, which is
            // NOT a sign of corruption. getimagesize() has no such lazy
            // loading quirk, so it's the reliable way to catch a genuinely
            // corrupt/degenerate render before wasting paper on it.
            $dimensions = @getimagesize($imagePath);

            Log::info('ESC/POS bitImage', [
                'printer'     => $printer->name,
                'image_path'  => $imagePath,
                'file_exists' => file_exists($imagePath),
                'file_size'   => file_exists($imagePath) ? filesize($imagePath) : 0,
                'img_width'   => $dimensions[0] ?? 0,
                'img_height'  => $dimensions[1] ?? 0,
            ]);

            if (! $dimensions || $dimensions[0] <= 0 || $dimensions[1] <= 0) {
                throw new \RuntimeException(
                    'Rendered receipt image is invalid (width=' . ($dimensions[0] ?? 0)
                        . ', height=' . ($dimensions[1] ?? 0) . ') — refusing to print a blank page.'
                );
            }

            $img = EscposImage::load($imagePath);

            $escpos->bitImage($img);

            $escpos->feed(4);
            $escpos->cut();

            return $this->success($printer);
        } catch (\Exception $e) {
            Log::error('ESC/POS bitImage failed', [
                'printer'    => $printer->name,
                'path'       => $imagePath,
                'error'      => $e->getMessage(),
                'error_type' => get_class($e),
            ]);
            return $this->error($printer, $e);
        } finally {
            $this->close($escpos);
        }
    }

    /**
     * Print several receipt images to the same printer over one connection.
     * "fawri" mode prints a separate ticket per department but all of them
     * land on the same cashier printer - opening/closing a fresh connection
     * per ticket was paying a full TCP handshake + driver init for every
     * single one even though nothing about the destination changed between
     * them. One connection, N images each still individually fed+cut as
     * their own physical receipt.
     */
    public function printMultipleReceiptImages(Printer $printer, array $imagePaths): array
    {
        $escpos = null;
        $results = [];

        try {
            $escpos = $this->connect($printer);

            foreach ($imagePaths as $imagePath) {
                try {
                    // نفس فحص getimagesize() المستخدم بـprintReceiptImage() —
                    // راجع التعليق هناك لتفاصيل ليش مش $img->getWidth().
                    $dimensions = @getimagesize($imagePath);

                    if (! $dimensions || $dimensions[0] <= 0 || $dimensions[1] <= 0) {
                        throw new \RuntimeException(
                            'Rendered receipt image is invalid (width=' . ($dimensions[0] ?? 0)
                                . ', height=' . ($dimensions[1] ?? 0) . ') — refusing to print a blank page.'
                        );
                    }

                    $img = EscposImage::load($imagePath);
                    $escpos->bitImage($img);
                    $escpos->feed(4);
                    $escpos->cut();

                    $results[] = $this->success($printer);
                } catch (\Exception $e) {
                    // صورة وحدة فاسدة ما لازم توقف باقي التذاكر بنفس الاتصال.
                    Log::error('ESC/POS bitImage failed (batch)', [
                        'printer'    => $printer->name,
                        'path'       => $imagePath,
                        'error'      => $e->getMessage(),
                        'error_type' => get_class($e),
                    ]);
                    $results[] = $this->error($printer, $e);
                }
            }
        } catch (\Exception $e) {
            // فشل الاتصال نفسه (مش صورة محددة) - كل الصور بهالدفعة فشلت.
            foreach ($imagePaths as $imagePath) {
                $results[] = $this->error($printer, $e);
            }
        } finally {
            $this->close($escpos);
        }

        return $results;
    }

    /**
     * Print an image (standalone, no cut).
     */
    public function printImage(Printer $printer, string $imagePath): array
    {
        $escpos = null;

        try {
            $escpos = $this->connect($printer);
            $img = EscposImage::load($imagePath);
            $escpos->bitImage($img);

            return $this->success($printer);
        } catch (\Exception $e) {
            return $this->error($printer, $e);
        } finally {
            $this->close($escpos);
        }
    }

    /**
     * Send a test print to verify printer connectivity.
     */
    public function testPrint(Printer $printer): array
    {
        $escpos = null;

        try {
            $escpos = $this->connect($printer, 3);

            $escpos->setJustification(EscposPrinter::JUSTIFY_CENTER);
            $escpos->selectPrintMode(EscposPrinter::MODE_EMPHASIZED);
            $escpos->text("O2 SYSTEM\n");
            $escpos->text("--------------------------------\n");
            $escpos->text("Test Print Success!\n");
            $escpos->text("Printer: " . $printer->name . "\n");
            $escpos->text("Date: " . now()->format('Y-m-d H:i:s') . "\n");
            $escpos->text("--------------------------------\n");

            $escpos->cut();

            return $this->success($printer);
        } catch (\Exception $e) {
            return $this->error($printer, $e);
        } finally {
            $this->close($escpos);
        }
    }

    /**
     * فتح صندوق النقدية المربوط بمنفذ الدرج (drawer kick) — بدون تغذية ورق أو قص،
     * لأنه ما في إيصال منطبع أصلاً.
     */
    public function openDrawer(Printer $printer): array
    {
        $escpos = null;

        try {
            $escpos = $this->connect($printer, 3);
            $escpos->pulse();

            return $this->success($printer);
        } catch (\Exception $e) {
            return $this->error($printer, $e);
        } finally {
            $this->close($escpos);
        }
    }

    // ── Connection Helpers ──────────────────────────────────

    private function connect(Printer $printer, int $timeout = 5): EscposPrinter
    {
        $target = trim((string) $printer->ip_address);

        // إذا كانت خانة "IP" اسم طابعة ويندوز محلية (XP-80C) أو مشاركة
        // (smb://localhost/XP80) بدل عنوان IP رقمي → نطبع عبر مزود ويندوز
        // مباشرة على الطابعة الموصولة USB بنفس الجهاز الذي يشغّل الـ worker.
        if ($target !== '' && ! filter_var($target, FILTER_VALIDATE_IP)) {
            $connector = new WindowsPrintConnector($target);
        } else {
            $connector = new NetworkPrintConnector(
                $target,
                (int) ($printer->port ?? 9100),
                $timeout
            );
        }

        try {
            $profile = CapabilityProfile::load('default');

            return new EscposPrinter($connector, $profile);
        } catch (\Throwable $e) {
            // إذا فشل أي شيء بعد فتح الاتصال وقبل إرجاع كائن الطابعة، لازم نصفّي
            // (finalize) الموصل هون بنفسنا. غير هيك، pas destructor العنصر اليتيم
            // رح يطلق "Print connector was not finalized" (via trigger_error) لما
            // يتحرر من الذاكرة، وهاد بيحجب رسالة الخطأ الحقيقية اللي صارت هون.
            try { $connector->finalize(); } catch (\Throwable $ignored) {}
            throw $e;
        }
    }

    private function close(?EscposPrinter $escpos): void
    {
        if ($escpos !== null) {
            try { $escpos->close(); } catch (\Exception $ex) {}
        }
    }

    private function success(Printer $printer): array
    {
        return [
            'success' => true,
            'printer' => $printer->name,
            'message' => 'تمت الطباعة بنجاح',
        ];
    }

    private function error(Printer $printer, \Exception $e): array
    {
        return [
            'success' => false,
            'printer' => $printer->name,
            'message' => 'خطأ في الطباعة: ' . $e->getMessage(),
        ];
    }
}
