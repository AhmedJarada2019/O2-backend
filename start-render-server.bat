@echo off
REM ─────────────────────────────────────────────────────────────
REM  O2 System — Render Server (رندر إيصالات HTML→PNG عبر Chrome دائم)
REM  لازم يضل شغّال دايماً على جهاز الكاشير — بيسرّع الطباعة كتير لأنه
REM  Chrome بيضل مفتوح بدل ما ينفتح من الصفر كل إيصال (كان بياخد 1-2 ثانية
REM  لحاله). لو هاي الخدمة مو شغّالة، النظام بيرجع تلقائياً للطريقة القديمة
REM  الأبطأ (Browsershot) — يعني ما في طباعة معطلة، بس أبطأ.
REM
REM  شغّله يدوياً بالضغط عليه، أو خلّيه Scheduled Task عند تشغيل الويندوز
REM  (بنفس أسلوب start-queue-worker.bat / start-print-bridge.bat).
REM ─────────────────────────────────────────────────────────────
cd /d "%~dp0"

:loop
echo [%date% %time%] starting render server...
node render-server.js
echo [%date% %time%] render server exited, restarting in 3s...
timeout /t 3 /nobreak >nul
goto loop
