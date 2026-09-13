# O2 Cashier Setup — بناء واستخدام الـ Installer

هاد المجلد بيبني ملف `.exe` واحد بينصّب أي لابتوب كاشير جديد بالكامل
(PHP + Node + Chrome + 3 خدمات ويندوز دائمة) **بدون إنترنت** على الجهاز
النهائي. الإنترنت مطلوب مرة وحدة بس، وقت تحضير الـ`bundle`.

## الخطوة 1 — جهّز أدوات البناء (مرة وحدة، بجهاز فيه إنترنت)

1. نصّب [Inno Setup Compiler](https://jrsoftware.org/isdl.php) (مجاني).
2. تأكد إنه عندك Node.js و Composer مثبتين محلياً (بس لتحضير الـbundle،
   مش لازمين على جهاز الكاشير النهائي).

## الخطوة 2 — جهّز مجلد `bundle/` جنب `setup.iss`

لازم تصير البنية هيك بالضبط:

```
installer/
  setup.iss
  provision.ps1
  README.md          (هاد الملف)
  bundle/
    php/       ← PHP portable (php.exe بالجذر مباشرة)
    node/      ← Node.js portable (node.exe بالجذر مباشرة)
    chrome/    ← Chromium (chrome.exe بالجذر مباشرة)
    nssm/      ← nssm.exe (نسخة win64)
    app/       ← نسخة كاملة من المشروع (vendor/ + node_modules/puppeteer)
```

### PHP portable

حمّل نسخة **PHP 8.2 x64 (Non Thread Safe, zip)** من
https://windows.php.net/download/ (قسم "PHP 8.2 VS16 x64 Non Thread Safe")
وفكّها بـ `installer/bundle/php/`. تأكد إنه `php.exe` موجود مباشرة
بجذر هالمجلد (مو جوا مجلد فرعي).

### Node.js portable

حمّل **Node.js LTS — Windows Binary (.zip), x64** من
https://nodejs.org/en/download وفكّها بـ `installer/bundle/node/`
(تأكد `node.exe` بالجذر).

### Chrome (لـ Puppeteer)

من داخل نسخة المشروع (بجهازك، فيه إنترنت):

```
npx puppeteer browsers install chrome
```

هاد بيحمّل Chrome بمجلد زي:
`%USERPROFILE%\.cache\puppeteer\chrome\win64-<version>\chrome-win64\`

انسخ **محتويات** هداك المجلد (مو المجلد نفسه) لـ `installer/bundle/chrome/`
بحيث `chrome.exe` يصير بجذر `bundle/chrome/`.

⚠️ **لا تستخدم Edge أو Chrome النظام العادي هون.** جرّبنا هيك على جهاز
إنتاج فعلي وكان بينقطع من فترة لفترة (على الأغلب بسبب التحديث التلقائي
بالخلفية)، وهاد كان بيسبب طباعة صور فاسدة أحياناً. لازم تكون نسخة مخصصة
وثابتة (زي يلي بيجيبها `npx puppeteer browsers install chrome`) ما حدا
غيرها بيلمسها أو يحدّثها.

### NSSM

حمّل NSSM من https://nssm.cc/download (أو مرآة GitHub لو الموقع الرسمي
واقف)، وانسخ `win64\nssm.exe` لـ `installer/bundle/nssm/nssm.exe`.

### نسخة المشروع (app/)

```
# من مجلد نظيف (خارج .git) أو بعد git clone جديد:
composer install --no-dev --optimize-autoloader
npm install
```

بعدين انسخ المشروع كامل (بما فيه `vendor/` و`node_modules/`) لـ
`installer/bundle/app/` — **بلا** ملف `.env` وبلا مجلد `.git` (الـ
installer نفسه بيستثنيهم تلقائياً لو كانوا موجودين، بس أنضف تشيلهم مسبقاً
لتصغير حجم الـinstaller).

## الخطوة 3 — Compile

افتح `installer/setup.iss` بـ Inno Setup Compiler واضغط **Compile**
(أو F9). الناتج: `installer/output/O2CashierSetup.exe`.

## الخطوة 4 — استخدامه على أي لابتوب كاشير

1. انسخ `O2CashierSetup.exe` للابتوب الجديد (USB، مشاركة شبكة، إلخ —
   ما بيحتاج إنترنت من هون وطالع).
2. شغّله **Run as Administrator**.
3. بيسألك عن:
   - كلمة سر قاعدة البيانات المركزية (نفسها لكل الأجهزة). ⚠️ تجنّب وجود
     علامة اقتباس (`"`) بكلمة السر — بتكسر تمرير القيمة لسكربت التجهيز.
   - اسم الطابعة المحلية بالويندوز (تأكد إنها مطابقة تماماً لاسمها
     بـ Devices and Printers — مثلاً `XP-80C`).
   - اسم حساب الويندوز يلي رح يسجّل دخول فيه عادةً على هالجهاز (معبّى
     افتراضياً باسم المستخدم الحالي — بس لازم تدخل كلمة سره يدوياً).
4. اضغط Install واستنى — بيجهّز PHP، يكتب `.env`، وبعدها:
   - `O2PrintBridge` بينسجّل كـ**Windows Service** حقيقي (عبر NSSM) —
     بيشتغل تلقائياً بالخلفية بغض النظر مين مسجّل دخول.
   - `O2QueueWorker` و`O2RenderServer` بينسجّلوا كـ**Scheduled Tasks**
     تشتغل تلقائياً **عند تسجيل الدخول** (Logon) بنفس حساب المستخدم يلي
     أدخلته. ⚠️ **مو Windows Services عن قصد** — أي كود بيفتح متصفح
     Chrome/Puppeteer بيطلع نتائج فاسدة/مبتورة (صور طباعة فاضية) لما
     يشتغل من جوا "Session 0" (بيئة الخدمات المعزولة اللي ما إلها سطح
     مكتب حقيقي)، بغض النظر عن حساب الخدمة. جرّبنا هالمشكلة فعليًا
     وانحلّت بس لما صار التشغيل من جلسة تفاعلية حقيقية (Scheduled Task
     عند Logon).
5. خلص. `O2PrintBridge` شغّال دايمًا. `O2QueueWorker` و`O2RenderServer`
   بيشتغلوا تلقائيًا أول ما حدا يسجّل دخول على الجهاز، وبيعيدوا نفسهم لو
   وقعوا. **مهم:** إذا الجهاز أعاد التشغيل ولا حدا سجّل دخول، الطباعة
   بتضل واقفة لحد ما حدا يسجّل دخول — إذا هالسيناريو وارد (جهاز بدون
   مراقبة)، فعّل Windows Auto-Logon لنفس الحساب.

### التحقق إنه كل شي تمام

- `services.msc` → دوّر على `O2PrintBridge` (لازم Running).
- Task Scheduler (`taskschd.msc`) → دوّر على `O2QueueWorker` و
  `O2RenderServer` (لازم Running، Trigger = At log on)، أو بـPowerShell:
  ```
  Get-ScheduledTask O2QueueWorker, O2RenderServer | Select TaskName, State
  ```
- جرّب تطبع فاتورة، وشوف `{app}\backend\storage\logs\laravel.log` —
  لازم يطلع `"via":"render-server"`.
- لوغات كل عملية لحالها: `{app}\backend\storage\logs\O2QueueWorker.out.log`
  (وهيك للباقي) — مفيدة لو صار عطل.

### تحديث لابتوب موجود مسبقاً

فقط شغّل نفس `O2CashierSetup.exe` من جديد عليه — بيعيد نسخ الملفات
وتسجيل الخدمات بأحدث نسخة (بياخد نفس كلمة السر واسم الطابعة يلي تدخلهم
من جديد).
