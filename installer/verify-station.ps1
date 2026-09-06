<#
.SYNOPSIS
    O2 System - verify a cashier station's local backend copy has all the
    known printing fixes, instead of silently running on a stale bundle.

.DESCRIPTION
    Each cashier station keeps its OWN local copy of the Laravel backend
    (needed for local Chrome rendering + the USB print bridge). There is no
    automatic sync between this copy and the central server's code, so a
    station provisioned from an old installer build (or never re-synced
    after a fix) can silently keep running buggy code for weeks - this is
    exactly what happened to POS-012 on 2026-09-06: it was missing
    run-hidden.vbs entirely, had a start-queue-worker.bat that always used
    the shared "default" queue, and had pre-fix copies of PrintInvoiceJob,
    OrderPrintingService, render-server.js and ReceiptImageBuilder - each
    one hiding behind the last, so it took 5 rounds of debugging to find
    them all one at a time instead of catching it in one shot up front.

    Run this against any station - right after provisioning a new one, or
    at any time against an existing one you're unsure about - to catch that
    class of problem immediately instead of hours into a live debugging
    session.

.EXAMPLE
    .\verify-station.ps1 -InstallDir "C:\Program Files\O2System\backend"
#>

param(
    [string]$InstallDir = "C:\Program Files\O2System\backend"
)

function Test-Signature {
    param(
        [string]$Name,
        [string]$RelativePath,
        [string]$MustContain,
        [string]$MustNotContain = $null
    )

    $path = Join-Path $InstallDir $RelativePath

    if (-not (Test-Path $path)) {
        Write-Host "  [FAIL] $Name - file not found: $RelativePath" -ForegroundColor Red
        return $false
    }

    $content = Get-Content $path -Raw

    if ($content -notlike "*$MustContain*") {
        Write-Host "  [FAIL] $Name - looks like an OLD version (missing '$MustContain') in $RelativePath" -ForegroundColor Red
        return $false
    }

    if ($MustNotContain -and $content -like "*$MustNotContain*" -and $content -notlike "*$MustContain*") {
        Write-Host "  [FAIL] $Name - still has the old '$MustNotContain' in $RelativePath" -ForegroundColor Red
        return $false
    }

    Write-Host "  [OK]   $Name" -ForegroundColor Green
    return $true
}

Write-Host "Checking station at: $InstallDir" -ForegroundColor Cyan
Write-Host ""

$results = @(
    Test-Signature -Name "run-hidden.vbs (hidden console + accurate Task Scheduler state)" `
        -RelativePath "run-hidden.vbs" -MustContain "0, True"

    Test-Signature -Name "start-queue-worker.bat (per-station queue auto-detection)" `
        -RelativePath "start-queue-worker.bat" -MustContain "POS_REGISTER_ID"

    Test-Signature -Name "PrintInvoiceJob.php (per-station printer routing)" `
        -RelativePath "app\Jobs\PrintInvoiceJob.php" -MustContain "posRegisterId"

    Test-Signature -Name "OrderPrintingService.php (resolveCashierPrinter helper)" `
        -RelativePath "app\Services\Printing\OrderPrintingService.php" -MustContain "resolveCashierPrinter"

    Test-Signature -Name "EscPosPrinterDriver.php (getimagesize validation)" `
        -RelativePath "app\Services\Printing\Drivers\EscPosPrinterDriver.php" -MustContain "getimagesize"

    Test-Signature -Name "ReceiptImageBuilder.php (temp file cleanup age-gate)" `
        -RelativePath "app\Services\Printing\Renderers\ReceiptImageBuilder.php" -MustContain "maxAgeSeconds"

    Test-Signature -Name "render-server.js (fullPage:false screenshot fix)" `
        -RelativePath "render-server.js" -MustContain "fullPage: false"
)

$failCount = ($results | Where-Object { $_ -eq $false }).Count

Write-Host ""
if ($failCount -eq 0) {
    Write-Host "ALL CHECKS PASSED - this station is on the current code." -ForegroundColor Green
} else {
    Write-Host "$failCount CHECK(S) FAILED - this station is running stale code and WILL misbehave" -ForegroundColor Red
    Write-Host "the same way POS-012 did. Re-copy the failing file(s) from the current" -ForegroundColor Red
    Write-Host "backend-fixes package and restart the affected Scheduled Task(s)." -ForegroundColor Red
}
