' O2 System - launches a program completely hidden (no visible console
' window), while it still runs in the normal interactive user session.
' This matters because O2QueueWorker and O2RenderServer must run
' interactively (not as a Windows Service) for Chrome/Puppeteer to render
' correctly - see render-server.js and provision.ps1 comments for why.
'
' Running the .bat file directly as a Scheduled Task action shows a
' console window a cashier could accidentally close, killing the script's
' own auto-restart loop along with it. Routing through this launcher
' means there is nothing visible to close - if the underlying php.exe/
' node.exe process is killed some other way (e.g. Task Manager), the
' still-running hidden .bat loop notices and restarts it automatically,
' same as always.
'
' Usage: wscript.exe run-hidden.vbs "C:\path\to\something.bat"
'
' The last argument to Run() is WaitOnReturn = True: wscript.exe blocks
' here for as long as the hidden process keeps running (which, for these
' looping .bat files, is forever). This is required, not optional - with
' False, wscript.exe would exit immediately after launching, so Task
' Scheduler would never see this task as "Running", and every later
' Start-ScheduledTask call (at logon, a manual restart, etc.) would launch
' ANOTHER copy on top of the one already running instead of being skipped
' as a duplicate - confirmed happening (two queue workers processing the
' same jobs table at once) before this was set to True.
Set objShell = CreateObject("WScript.Shell")
objShell.Run """" & WScript.Arguments(0) & """", 0, True
