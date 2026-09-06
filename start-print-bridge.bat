@echo off
REM ─────────────────────────────────────────────────────────────
REM  O2 System — Print Bridge (جسر الطباعة لطابعة الكاشير USB)
REM  لازم يضل شغّال دايماً على جهاز الكاشير نفسه — وإلا طباعة الكاشير ما بتشتغل.
REM  شغّله يدوياً بالضغط عليه، أو خلّيه Scheduled Task عند تشغيل الويندوز
REM  (بنفس أسلوب start-queue-worker.bat).
REM
REM  لتغيير اسم الطابعة (لو مختلف عن XP-80C) عدّل السطر تحت أو مرّر:
REM    start-print-bridge.bat "اسم الطابعة"
REM ─────────────────────────────────────────────────────────────
cd /d "%~dp0"

set PRINTER_NAME=%1
if "%PRINTER_NAME%"=="" set PRINTER_NAME=XP-80C

:loop
echo [%date% %time%] starting print bridge (printer: %PRINTER_NAME%)...
php print-bridge.php --printer="%PRINTER_NAME%" --listen=127.0.0.1:9100
echo [%date% %time%] print bridge exited, restarting in 3s...
timeout /t 3 /nobreak >nul
goto loop
