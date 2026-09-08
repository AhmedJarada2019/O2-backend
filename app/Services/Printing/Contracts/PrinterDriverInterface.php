<?php

namespace App\Services\Printing\Contracts;

use App\Models\Printer;

interface PrinterDriverInterface
{
    /**
     * Send raw content to a printer.
     */
    public function send(Printer $printer, string $content): array;

    /**
     * Send ESC/POS commands via a closure for fine-grained control.
     */
    public function sendWithClosure(Printer $printer, callable $callback): array;

    /**
     * Print the entire receipt as a single image (primary method for Arabic).
     * Handles image rendering, feed, and cut.
     */
    public function printReceiptImage(Printer $printer, string $imagePath): array;

    /**
     * Print several receipt images to the same printer over a single
     * connection (one connect/close instead of one per image) — used when
     * an order needs multiple separate receipts on one printer (e.g.
     * "fawri" mode's one-ticket-per-department, all on the cashier
     * printer). Returns one result array per input image, in order; a
     * failure on one image does not stop the rest from printing.
     */
    public function printMultipleReceiptImages(Printer $printer, array $imagePaths): array;

    /**
     * Print an image file to the printer (no cut).
     */
    public function printImage(Printer $printer, string $imagePath): array;

    /**
     * Send a test print to verify connectivity.
     */
    public function testPrint(Printer $printer): array;

    /**
     * Kick the cash drawer connected to this printer's drawer port
     * (ESC/POS pulse — no paper feed/cut).
     */
    public function openDrawer(Printer $printer): array;
}
