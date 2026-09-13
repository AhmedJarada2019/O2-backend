<#
.SYNOPSIS
    O2 System - fully stop O2QueueWorker / O2RenderServer, including their
    orphaned child processes, then delete both Scheduled Tasks.

.DESCRIPTION
    Each of these tasks runs as wscript.exe -> cmd.exe -> php.exe/node.exe.
    "schtasks /End" (what the uninstaller used to call) and Stop-Process by
    image name both only ever touch the top or bottom of that chain -
    Windows does NOT cascade-terminate the cmd.exe in between. Left running,
    that orphaned cmd.exe just keeps looping (see start-queue-worker.bat /
    start-render-server.bat) and silently spawns its own worker again a few
    seconds later. Confirmed on a real station: this is what caused 3
    concurrent "queue:work" processes fighting over the same jobs table,
    surviving several rounds of "clean" task re-registration. taskkill /T
    does a real process-tree kill, so use that here instead.

    Called from installer/setup.iss [UninstallRun], and safe to re-run by
    hand any time these tasks need a truly clean restart.

.EXAMPLE
    .\stop-o2-tasks.ps1 -InstallDir "C:\Program Files\O2System\backend"
#>

param(
    [Parameter(Mandatory = $true)] [string]$InstallDir
)

$pattern = [regex]::Escape($InstallDir)
Get-CimInstance Win32_Process -Filter "Name='wscript.exe' or Name='cmd.exe'" -ErrorAction SilentlyContinue |
    Where-Object { $_.CommandLine -and $_.CommandLine -match $pattern } |
    ForEach-Object {
        & taskkill.exe /F /T /PID $_.ProcessId 2>&1 | Out-Null
    }

foreach ($task in @("O2QueueWorker", "O2RenderServer")) {
    Unregister-ScheduledTask -TaskName $task -Confirm:$false -ErrorAction SilentlyContinue
}
