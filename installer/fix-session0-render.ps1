<#
.SYNOPSIS
    O2 System - Move O2RenderServer and O2QueueWorker off Windows Services
    (Session 0) onto interactive-session Scheduled Tasks.

.DESCRIPTION
    Windows Services run in "Session 0", an isolated session with no real
    desktop/window station. Chrome/Puppeteer can silently produce corrupt or
    truncated screenshots when launched from Session 0 - this happens
    regardless of which user account the service runs as (Local System or a
    real user makes no difference here; Session 0 isolation is a separate
    Windows security feature introduced in Vista and applies to ALL
    services). Confirmed by reproducing the exact same order/content
    directly (not as a service) with a normal, correctly-sized result.

    This script removes the NSSM services for O2RenderServer and
    O2QueueWorker and replaces them with Scheduled Tasks triggered "at log
    on" for the current user, which run in a real interactive session where
    Chrome renders correctly. O2PrintBridge is left untouched - it never
    launches a browser, so it is not affected by this issue.

.NOTES
    Run as Administrator, from a normal interactive PowerShell session,
    logged in as the account that should run these tasks going forward.

.EXAMPLE
    .\fix-session0-render.ps1 -InstallDir "C:\Users\<user>\Documents\o2-system-backend" -NssmExe "C:\Users\<user>\Documents\nssm\nssm.exe"
#>

param(
    [Parameter(Mandatory = $true)] [string]$InstallDir,
    [Parameter(Mandatory = $true)] [string]$NssmExe,

    # If this station already has a POS_REGISTER_ID in its .env (from a
    # prior activation), pass it here so the queue worker only picks up
    # print jobs meant for THIS station instead of the shared "default"
    # queue. E.g. -PosRegisterId 10. Leave empty to keep using "default".
    [string]$PosRegisterId = ""
)

$ErrorActionPreference = "Stop"

function Write-Step($msg) { Write-Host "==> $msg" -ForegroundColor Cyan }
function Write-Ok($msg)   { Write-Host "    OK: $msg" -ForegroundColor Green }

if (-not (Test-Path $InstallDir)) { throw "InstallDir not found: $InstallDir" }
if (-not (Test-Path $NssmExe))    { throw "nssm.exe not found: $NssmExe" }

$currentUser = "$env:USERDOMAIN\$env:USERNAME"
Write-Host "Registering tasks to run as: $currentUser" -ForegroundColor DarkGray

# -- 1) Remove the two Session-0 services ---------------------------------
# nssm.exe writes plain status text to stderr (e.g. "service doesn't exist
# yet" if it was never registered) - with $ErrorActionPreference = "Stop"
# that gets treated as a terminating error otherwise. Relax it just for
# these two best-effort cleanup calls.
Write-Step "Removing O2RenderServer and O2QueueWorker Windows Services..."
$prevEap0 = $ErrorActionPreference
$ErrorActionPreference = "Continue"
foreach ($svc in @("O2RenderServer", "O2QueueWorker")) {
    & $NssmExe stop $svc 2>&1 | Out-Null
    & $NssmExe remove $svc confirm 2>&1 | Out-Null
}
$ErrorActionPreference = $prevEap0
Write-Ok "Services removed if they existed (O2PrintBridge left running as-is)"

# Clear out any leftover processes before starting fresh
Get-Process node -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
Get-Process chrome -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
Start-Sleep -Seconds 2

# -- 2) Register interactive Scheduled Tasks ------------------------------
$vbsPath = Join-Path $InstallDir "run-hidden.vbs"
if (-not (Test-Path $vbsPath)) {
    Write-Step "Writing run-hidden.vbs (hides the console window so nobody can accidentally close it)..."
    $vbsLines = @(
        '' + "'" + ' O2 System - launches a program completely hidden (no visible console window).',
        'Set objShell = CreateObject("WScript.Shell")',
        'objShell.Run """" & WScript.Arguments(0) & """", 0, True'
    )
    Set-Content -Path $vbsPath -Value $vbsLines -Encoding ASCII
    Write-Ok "run-hidden.vbs created"
}

function Stop-O2OrphanProcesses {
    param([string]$InstallDir)

    # A Scheduled Task action here is wscript.exe -> cmd.exe -> php.exe or
    # node.exe. Ending/unregistering the task (or Stop-Process on php/node
    # by name) only ever touches the top or bottom of that chain - Windows
    # does NOT cascade-terminate the cmd.exe in between. Left running, that
    # orphaned cmd.exe just keeps looping (":loop ... goto loop" in
    # start-queue-worker.bat / start-render-server.bat) and silently spawns
    # its own worker again a few seconds later, right next to whatever this
    # script registers afterwards. Confirmed on a real station: this is what
    # caused 3 concurrent "queue:work" processes fighting over the same jobs
    # table, even after every task was freshly re-registered. taskkill /T
    # does a real process-tree kill, so use that instead of Stop-Process
    # before ever touching these tasks.
    $pattern = [regex]::Escape($InstallDir)
    Get-CimInstance Win32_Process -Filter "Name='wscript.exe' or Name='cmd.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -and $_.CommandLine -match $pattern } |
        ForEach-Object {
            & taskkill.exe /F /T /PID $_.ProcessId 2>&1 | Out-Null
        }
}

function Register-O2Task {
    param(
        [string]$TaskName,
        [string]$ScriptPath
    )

    if (-not (Test-Path $ScriptPath)) {
        throw "${TaskName}: script not found at $ScriptPath"
    }

    Stop-O2OrphanProcesses -InstallDir $InstallDir
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue

    # Route through run-hidden.vbs so no console window ever appears for a
    # cashier to accidentally close (that would kill the .bat's own
    # auto-restart loop along with it, not just the current print job).
    $action = New-ScheduledTaskAction -Execute "wscript.exe" -Argument "`"$vbsPath`" `"$ScriptPath`"" -WorkingDirectory $InstallDir

    $trigger = New-ScheduledTaskTrigger -AtLogOn -User $currentUser

    $settings = New-ScheduledTaskSettingsSet `
        -AllowStartIfOnBatteries `
        -DontStopIfGoingOnBatteries `
        -StartWhenAvailable `
        -ExecutionTimeLimit ([TimeSpan]::Zero) `
        -RestartCount 999 `
        -RestartInterval (New-TimeSpan -Minutes 1)

    $principal = New-ScheduledTaskPrincipal -UserId $currentUser -LogonType Interactive -RunLevel Highest

    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger `
        -Settings $settings -Principal $principal | Out-Null

    Start-ScheduledTask -TaskName $TaskName

    Write-Ok "$TaskName registered as a logon Scheduled Task and started"
}

Write-Step "Registering O2RenderServer..."
Register-O2Task -TaskName "O2RenderServer" -ScriptPath (Join-Path $InstallDir "start-render-server.bat")

Write-Step "Registering O2QueueWorker..."
if ($PosRegisterId -ne "") {
    # start-queue-worker.bat now reads POS_REGISTER_ID from .env itself
    # (more reliable than passing it as a Scheduled Task argument through
    # cmd.exe against a path with spaces - that combination was observed
    # to leave the task stuck reporting "Ready" instead of "Running").
    # Just make sure .env has the value the caller told us about.
    $envPath = Join-Path $InstallDir ".env"
    if (Test-Path $envPath) {
        $envLines = Get-Content $envPath
        $pattern = "^#?\s*POS_REGISTER_ID\s*="
        $found = $false
        for ($i = 0; $i -lt $envLines.Count; $i++) {
            if ($envLines[$i] -match $pattern) {
                $envLines[$i] = "POS_REGISTER_ID=$PosRegisterId"
                $found = $true
                break
            }
        }
        if (-not $found) { $envLines += "POS_REGISTER_ID=$PosRegisterId" }
        Set-Content -Path $envPath -Value $envLines -Encoding UTF8
        Write-Ok ".env updated with POS_REGISTER_ID=$PosRegisterId"
    }
}
Register-O2Task -TaskName "O2QueueWorker" -ScriptPath (Join-Path $InstallDir "start-queue-worker.bat")

Start-Sleep -Seconds 5

Write-Host ""
Write-Host "Done. Verify with:" -ForegroundColor Green
Write-Host "  Get-ScheduledTask O2RenderServer, O2QueueWorker | Select TaskName, State"
Write-Host "  Get-Process node   (should show 1-2 node.exe processes running)"
Write-Host ""
Write-Host "IMPORTANT: these tasks only start once a user is actually logged" -ForegroundColor Yellow
Write-Host "on interactively - that's the whole point, since it fixes the" -ForegroundColor Yellow
Write-Host "Session 0 problem. If this machine reboots with nobody logging" -ForegroundColor Yellow
Write-Host "in, printing stays down until someone logs in. Ask about setting" -ForegroundColor Yellow
Write-Host "up Windows auto-logon if unattended reboots are a concern." -ForegroundColor Yellow
