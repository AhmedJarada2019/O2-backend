@echo off
REM ─────────────────────────────────────────────────────────────
REM  O2 System — Queue Worker (الطباعة / الطلبات الخلفية)
REM  لازم يضل شغّال دايماً وإلا "تنفيذ وطباعة" و"طباعة" ما بيطبعوا.
REM  شغّله يدوياً بالضغط عليه، أو خلّيه Scheduled Task عند تشغيل الويندوز.
REM
REM  اسم قائمة الانتظار (queue) بيتحدد تلقائياً من قيمة POS_REGISTER_ID
REM  بملف .env (تنكتب هناك وقت تفعيل الجهاز — راجع provision.ps1). هيك
REM  المحطة هاي بس بتاخد طلبات الطباعة الخاصة فيها، مش أي طلب من محطة
REM  تانية. ما بنمرر أي باراميتر عبر Task Scheduler عن قصد — تمرير
REM  باراميتر لمسار فيه مسافات (Program Files) عبر cmd.exe/Task Scheduler
REM  ثبت إنه غير موثوق (الـtask كانت توقف تسجيل حالتها "Running" بشكل
REM  صحيح). قراءة القيمة من .env مباشرة أبسط وأضمن.
REM
REM  لو POS_REGISTER_ID مش موجود بـ.env (جهاز قديم أو ما انفعّل)، بترجع
REM  لـ"default" القديمة تلقائياً — توافق عكسي كامل بدون كسر أي شي.
REM ─────────────────────────────────────────────────────────────
cd /d "%~dp0"

set POS_REGISTER_ID=
for /f "usebackq tokens=1,* delims==" %%A in (".env") do (
    if "%%A"=="POS_REGISTER_ID" set "POS_REGISTER_ID=%%B"
)
REM شيل أي علامات اقتباس محتملة حوالين القيمة (POS_REGISTER_ID="10")
set POS_REGISTER_ID=%POS_REGISTER_ID:"=%

if defined POS_REGISTER_ID (
    set QUEUE_NAME=pos-register-%POS_REGISTER_ID%,default
) else (
    set QUEUE_NAME=default
)

REM --sleep=0.25: الـworker بيفحص جدول jobs كل 0.25 ثانية لما يكون فاضي
REM (بدل 1 ثانية الافتراضية) — أسرع رد فعل لأمر طباعة جديد، بدون حمل
REM إضافي يُذكر (استعلام DB خفيف على جدول صغير). Laravel Worker::sleep()
REM بيدعم كسور الثانية فعليًا (usleep داخليًا)، مش مجرد رقم صحيح.
:loop
echo [%date% %time%] starting queue worker (queue: %QUEUE_NAME%)...
php artisan queue:work database --queue=%QUEUE_NAME% --tries=2 --timeout=120 --sleep=0.25 --max-time=3600
echo [%date% %time%] worker exited, restarting in 3s...
timeout /t 3 /nobreak >nul
goto loop
