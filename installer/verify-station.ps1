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

    Also checks the station's .env for the config-side version of the same
    problem - on 2026-09-10, POS-015 (station code, PosRegister id 19) had a
    completely blank/default .env: no POS_REGISTER_ID, no POS_REGISTER_CODE,
    and a wrong DB_PASSWORD. The queue worker silently produced a garbage
    queue name ("pos-register-"=,default") and could never reach the
    database, so print jobs queued up forever with nobody consuming them and
    the cashier only found out when paper never came out. A code-only check
    would never have caught this - the code was fine, the config wasn't.

    Run this against any station - right after provisioning a new one, or
    at any time against an existing one you're unsure about - to catch that
    class of problem immediately instead of hours into a live debugging
    session.

.EXAMPLE
    .\verify-station.ps1 -InstallDir "C:\Program Files\O2System\backend"
#>

param(
    [string]$InstallDir = "C:\Program Files\O2System\backend",

    # Pass this when checking the installer's own bundle\app template folder
    # (via refresh-bundle.ps1) instead of a real, provisioned station. The
    # template intentionally has no .env yet - provision.ps1 writes one fresh
    # per physical machine, including POS_REGISTER_ID from a mandatory
    # activation step. Running the .env/DB checks against the template would
    # always fail there and isn't a real problem, so skip them in that case.
    [switch]$SkipRuntimeChecks
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

function Test-EnvKeyPresent {
    param(
        [string]$Name,
        [string]$Key
    )

    $envPath = Join-Path $InstallDir ".env"

    if (-not (Test-Path $envPath)) {
        Write-Host "  [FAIL] $Name - .env not found at $envPath" -ForegroundColor Red
        return $false
    }

    $line = Get-Content $envPath | Where-Object { $_ -match "^$Key=" } | Select-Object -First 1

    if (-not $line) {
        Write-Host "  [FAIL] $Name - $Key is missing from .env entirely (station was never provisioned for a specific PosRegister)" -ForegroundColor Red
        return $false
    }

    $value = ($line -split '=', 2)[1]
    $value = $value.Trim().Trim('"')

    if ([string]::IsNullOrWhiteSpace($value)) {
        Write-Host "  [FAIL] $Name - $Key is present in .env but empty" -ForegroundColor Red
        return $false
    }

    Write-Host "  [OK]   $Name ($Key=$value)" -ForegroundColor Green
    return $true
}

function Test-DatabaseConnection {
    param(
        [string]$Name
    )

    $envPath = Join-Path $InstallDir ".env"
    if (-not (Test-Path $envPath)) {
        Write-Host "  [FAIL] $Name - .env not found, cannot test connection" -ForegroundColor Red
        return $false
    }

    Push-Location $InstallDir
    try {
        $output = & php artisan migrate:status 2>&1 | Out-String
        $ok = ($LASTEXITCODE -eq 0) -and ($output -notmatch "Access denied|SQLSTATE|could not find driver|Connection refused|getaddrinfo")

        if (-not $ok) {
            Write-Host "  [FAIL] $Name - database connection failed (wrong DB_PASSWORD/DB_HOST in .env, or DB unreachable):" -ForegroundColor Red
            $firstLine = ($output -split "`r?`n" | Where-Object { $_.Trim() -ne "" } | Select-Object -First 1)
            if ($firstLine) {
                Write-Host "         $firstLine" -ForegroundColor Red
            }
            return $false
        }

        Write-Host "  [OK]   $Name" -ForegroundColor Green
        return $true
    } catch {
        Write-Host "  [FAIL] $Name - could not run php artisan (php not on PATH?): $($_.Exception.Message)" -ForegroundColor Red
        return $false
    } finally {
        Pop-Location
    }
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

    Test-Signature -Name "start-queue-worker.bat (fast queue poll interval)" `
        -RelativePath "start-queue-worker.bat" -MustContain "--sleep=0.25"

    Test-Signature -Name "EscPosPrinterDriver.php (batched printer connection)" `
        -RelativePath "app\Services\Printing\Drivers\EscPosPrinterDriver.php" -MustContain "printMultipleReceiptImages"

    Test-Signature -Name "render-server.js (no networkidle0 stall on setContent)" `
        -RelativePath "render-server.js" -MustContain "waitUntil: 'load'" -MustNotContain "networkidle0"
)

if (-not $SkipRuntimeChecks) {
    $results += Test-EnvKeyPresent -Name ".env has POS_REGISTER_ID (station identity for print routing)" `
        -Key "POS_REGISTER_ID"

    $results += Test-EnvKeyPresent -Name ".env has POS_REGISTER_CODE (station identity, human-readable)" `
        -Key "POS_REGISTER_CODE"

    $results += Test-DatabaseConnection -Name "Database connection actually works (DB_PASSWORD etc. correct)"
} else {
    Write-Host "  [SKIP] .env / database checks (bundle template - provision.ps1 writes .env per-machine)" -ForegroundColor DarkGray
}

$failCount = ($results | Where-Object { $_ -eq $false }).Count

Write-Host ""
if ($failCount -eq 0) {
    Write-Host "ALL CHECKS PASSED - this station is on the current code." -ForegroundColor Green
} else {
    Write-Host "$failCount CHECK(S) FAILED - this station is running stale code and WILL misbehave" -ForegroundColor Red
    Write-Host "the same way POS-012 did. Re-copy the failing file(s) from the current" -ForegroundColor Red
    Write-Host "backend-fixes package and restart the affected Scheduled Task(s)." -ForegroundColor Red
}
