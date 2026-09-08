# نظام الطباعة — التوثيق الكامل

هاد الملف بيوثّق معمارية الطباعة بالكامل: كيف بتطبع فاتورة من نقطة البيع
لحتى تطلع ورقة من طابعة حرارية فعلية بمحطة كاشير محددة، كل المشاكل
الحقيقية يلي واجهناها وحلولها، وكيف تشخّص أي عطل جديد بأسرع وقت.

اقرأ هاد الملف قبل ما تلمس أي شي بمنطق الطباعة — معظم "الأخطاء البديهية"
يلي ممكن تجرب تصلّحها موثّقة هون مع السبب الحقيقي، وتصليحها بطريقة سطحية
رح يرجع يكسر شي تاني اتصلّح بالفعل.

---

## 1. الصورة الكبيرة: ليش كل محطة كاشير عندها نسخة كاملة من الباك إند

كل محطة كاشير (POS-001، POS-010، POS-012، ...) هي **جهاز Windows فيزيائي**
عليه:

- نسخة كاملة من مشروع `o2-system-backend` (PHP + node_modules + vendor)
  مثبّتة محليًا تحت `C:\Program Files\O2System\backend\`
- طابعة حرارية (USB غالبًا، أو شبكة أحيانًا) موصولة فيزيائيًا بنفس الجهاز

هاي النسخة المحلية **ضرورية**، مش زيادة: لازم Chrome يفتح محليًا على نفس
جهاز الكاشير لرندر الإيصال كصورة (Puppeteer)، ولازم في عملية محلية تتكلم
مع الطابعة مباشرة (USB عبر SMB، أو TCP لطابعة شبكة). ما فيه طريقة تعمل
هاد الشغل من السيرفر المركزي البعيد.

**كل المحطات بتتصل بنفس قاعدة البيانات المركزية** (`192.168.2.250`)، وكل
محطة عندها `queue:work` محلي خاص فيها بيسحب بس المهام يلي إلها (تفصيل
بقسم 3).

### النتيجة المهمة لأي حدا بيدير هالنظام

**ما في مزامنة تلقائية** بين كود `o2-system-backend` المركزي (هاد الريبو)
وبين النسخ المحلية عالمحطات. أي تصحيح بمنطق الطباعة (queue routing،
render-server.js، إلخ) **لازم يتنسخ يدويًا** لكل محطة موجودة أصلاً —
وإلا هاي المحطة بتضل شغالة بكود قديم بصمت لأسابيع لحد ما حدا يلاحظ. هاد
بالضبط يلي صار مع POS-012 بتاريخ 2026-09-06 (تفصيل كامل بقسم 7).

**الحل الدائم لهاد المشكلة**: `installer/verify-station.ps1` — شغّله ضد
أي محطة (جديدة أو قديمة) ليتأكد إنها على آخر نسخة. تفصيل بقسم 8.

---

## 2. العمليات الثلاث الشغالة بكل محطة

| الاسم | نوعها | ليش هيك | الملف |
|---|---|---|---|
| **O2PrintBridge** | Windows Service حقيقي (عبر NSSM) | ما بيفتح متصفح، فما في مشكلة Session 0 | `print-bridge.php` |
| **O2QueueWorker** | Scheduled Task (تسجيل دخول تفاعلي) | لازم يقدر يفتح Chrome أحيانًا (fallback لـBrowsershot) | `start-queue-worker.bat` |
| **O2RenderServer** | Scheduled Task (تسجيل دخول تفاعلي) | لازم يفتح Chrome دايمًا (Puppeteer) | `start-render-server.bat` |

### ليش O2QueueWorker وO2RenderServer مش Windows Services عاديين؟

**Session 0 Isolation**: أي Windows Service (بغض النظر عن حساب تشغيله —
Local System أو حساب حقيقي، ما بيفرق) بيشتغل بجلسة معزولة (Session 0)
مالها "سطح مكتب" حقيقي. Chrome/Puppeteer لما يشتغل من جوا Session 0
بينتج screenshots فاسدة أو فاضية بصمت — بدون أي error واضح. هاد انثبت
فعليًا بإعادة إنتاج نفس المشكلة (نفس المحتوى، نفس الإعدادات) وانحلّت بس
لما صار التشغيل من جلسة تفاعلية حقيقية (مستخدم مسجّل دخول فعليًا).

الحل: Scheduled Task بـtrigger نوعه `AtLogOn` تحت principal
`LogonType Interactive` — هيك العملية بتشتغل بجلسة حقيقية.

### مشكلة ثانية حليناها: نافذة cmd.exe الظاهرة

لو Scheduled Task بينفّذ `.bat` مباشرة، بيطلع نافذة console ظاهرة —
الكاشير ممكن يسكرها بالغلط، وهيك بيوقف حلقة إعادة التشغيل التلقائية
(`:loop` بالـ.bat) مش بس العملية الحالية.

**الحل**: `run-hidden.vbs` — الـScheduled Task action بينادي
`wscript.exe run-hidden.vbs "path\to\script.bat"` بدل ما ينادي الـ.bat
مباشرة. مافيش نافذة تنسكر بالغلط.

```vbscript
Set objShell = CreateObject("WScript.Shell")
objShell.Run """" & WScript.Arguments(0) & """", 0, True
```

**التفصيلة الحرجة**: الباراميتر الأخير `True` (مش `False`) — هاد
`WaitOnReturn`. لازم يكون `True` وإلا `wscript.exe` بيرجع فورًا بعد
التشغيل، فـTask Scheduler ما بيعرف إنه التاسك لسا "Running" فعليًا —
وأي `Start-ScheduledTask` تاني (AtLogOn، إعادة تشغيل يدوية) بيطلق **نسخة
ثانية** فوق يلي شغالة أصلاً بدل ما يتجاهلها كتكرار. هاد سبب فعليًا
تشغيل نسختين من queue worker بنفس الوقت بيعالجوا نفس الطلبات.

### مشكلة ثالثة (الأخطر): عمليات cmd.exe يتيمة

حتى بعد تصحيح `run-hidden.vbs`، استمرت مشكلة تكرار العمليات. السبب
الحقيقي: كل تاسك هو سلسلة `wscript.exe → cmd.exe → php.exe/node.exe`.
إيقاف/حذف التاسك (`Unregister-ScheduledTask`, `schtasks /End`) أو قتل
php.exe بالاسم (`Stop-Process`) **بيلمس بس طرفي السلسلة**، مش الحلقة
الوسطى (`cmd.exe`). الـcmd.exe اليتيم بيضل شغال بحلقته الخاصة
(`:loop ... goto loop`) ويعيد يفتح php.exe/node.exe جديد من عمره كل شوي
— بغض النظر شو منعمل بالـScheduled Task.

**الحل**: أي إيقاف/إعادة تسجيل لازم يستخدم **قتل شجرة العمليات كاملة**
(`taskkill /F /T /PID <wscript_pid>`) مش `Stop-Process` لوحدها. هاد
مطبّق بدالة `Stop-O2OrphanProcesses` بـ`provision.ps1` و
`fix-session0-render.ps1`، وبملف `installer/stop-o2-tasks.ps1` المستخدم
بخطوة الـuninstall.

---

## 3. توجيه الطباعة حسب المحطة (PosRegister)

### المشكلة يلي هاد النظام بيحلها

فرع فيه أكتر من محطة كاشير (POS-010، POS-012) — لازم فاتورة تُطبع
بمحطتها فقط، مش بأي محطة تانية بنفس الفرع.

### الآلية

1. **التفعيل**: كل جهاز كاشير بينفعّل مرة وحدة عبر كود تفعيل (صالح 15
   دقيقة، من `PosRegisterController::generateActivationToken()`) — هاد
   بيربط الجهاز بسجل `PosRegister` محدد ويحفظ `POS_REGISTER_ID` و
   `POS_REGISTER_CODE` بملف `.env` المحلي للمحطة.

2. **الواجهة (frontend)**: بعد التفعيل، الجهاز بيخزّن `posInfo` (فيها
   `posInfo.id` = رقم الـPosRegister) بـlocalStorage محلي للمتصفح
   (`getRegisterInfoSecurely()` بـ`src/utils/posSecurity.ts`).

3. **طلب الطباعة**: لما يضغط المستخدم "طباعة"، الواجهة (`pos.tsx`،
   `HospitalityPOS.tsx`) بترسل `pos_register_id: posInfo.id` صراحةً مع
   طلب `/orders/{id}/print-invoice`.

4. **السيرفر المركزي** (`OrderController::printInvoice`): بيستقبل
   `pos_register_id`، وبينادي
   `OrderPrintingService::resolveQueueForOrder($order, $printerId, $posRegisterId)`
   لتحديد اسم قائمة الانتظار — `pos-register-{id}` لو `$posRegisterId`
   موجود، أو تخمين احتياطي (أول طابعة كاشير فعّالة بالفرع) لو مش موجود
   (توافق عكسي بس، مش المسار الطبيعي).

5. **الـJob**: `PrintInvoiceJob::dispatch($order, $printerId, $userId, $mode, $posRegisterId)->onQueue($queue)`
   — الوظيفة بتترمى بقائمة مخصصة لهاي المحطة بالتحديد.

6. **المحطة المحلية**: `start-queue-worker.bat` بيقرأ `POS_REGISTER_ID`
   من `.env` المحلي، وبيشغّل
   `php artisan queue:work database --queue=pos-register-{id},default`
   — يعني **بس** هاي المحطة قادرة تسحب مهام من هاي القائمة.

7. **اختيار الطابعة الفعلية**: `OrderPrintingService::resolveCashierPrinter($branchId, $posRegisterId)`
   — لو `$posRegisterId` معروف، بيدوّر على طابعة `CASHIER` مربوطة
   بالتحديد بـ`linked_pos_register_id = $posRegisterId`، مش "أي طابعة
   كاشير فعّالة بالفرع".

### الخطأ يلي صار (ودرس مهم): طبقتين لازم تتصلحوا سوا

أول تصحيح غطّى بس خطوة توجيه الـqueue (الخطوة 4-5)، وترك خطوة اختيار
الطابعة الفعلية (الخطوة 7) على منطقها القديم "أول طابعة كاشير فعّالة
بالفرع". النتيجة: الـqueue routing صار صح (الطلب فعليًا يوصل لقائمة
المحطة الصحيحة)، بس لمّا القائمة المحلية الصحيحة تعالج الطلب، كانت
لسا **تختار الطابعة الغلط** لأنه منطق اختيار الطابعة نفسه ما تصحح.

**الدرس**: أي "طلب طباعة" بيمر بمرحلتين مستقلتين لازم توجيه صحيح
بالاثنتين: (أ) أي queue بتوصله الوظيفة، (ب) أي طابعة فعليًا بيستخدمها
كود التنفيذ. تصحيح وحدة بدون التانية بيبين إنه "اتصلح" بينما هو نص
الحل بس.

### طابعات USB مقابل طابعات شبكة — نفس عنوان IP طبيعي وغير طبيعي

طابعة USB بمحطة كاشير بتوصلها عبر جسر محلي (`print-bridge.php`) على
`127.0.0.1:9100` — **هاد العنوان محلي نسبةً لكل جهاز على حدة**، فتكراره
بين محطات مختلفة بنفس الفرع طبيعي 100% ومتوقع (كل محطة عندها
`127.0.0.1` خاص فيها).

أما طابعة شبكة حقيقية (IP زي `192.168.1.50`) فعنوانها **فريد فعليًا
لجهاز واحد بالشبكة** — تكراره بين محطتين هو خطأ حقيقي لازم يُمنع.

`PrinterController::store()/update()` بيميّز بين الحالتين عبر
`isLoopbackAddress()`:
- طابعة CASHIER بعنوان loopback: فحص التكرار بيصير حسب
  `(IP + linked_pos_register_id)` — بيسمح بتكرار `127.0.0.1` بين محطات
  مختلفة.
- أي طابعة تانية (IP حقيقي، أو نوع KITCHEN/BAR/OTHER): فحص التكرار حسب
  `(IP + branch_id)` كامل — يمنع أي تكرار حقيقي.

---

## 4. مسار الطباعة الكامل (خطوة بخطوة)

```
مستخدم يضغط "طباعة" بالواجهة
  → POST /orders/{id}/print-invoice  { mode, pos_register_id }
  → OrderController::printInvoice()  [السيرفر المركزي]
      - يحدد $queue عبر resolveQueueForOrder()
      - PrintInvoiceJob::dispatch(...)->onQueue($queue)
  → الوظيفة تنحفظ بجدول `jobs` (قاعدة البيانات المشتركة)
  → queue:work المحلي بمحطة الكاشير الصحيحة (بس هي) بيسحب الوظيفة
  → PrintInvoiceJob::handle()  [محليًا بجهاز الكاشير]
      - printerId محدد؟ → printInvoiceById()
      - غير هيك → printLocal($order, $mode, $posRegisterId)
          - resolveCashierPrinter() يلاقي طابعة CASHIER الصحيحة
          - لكل قسم (لو mode = all/departments) → طابعته الخاصة
  → ReceiptImageBuilder::buildReceiptImage()
      - renderViaServer(): POST محلي لـhttp://127.0.0.1:4790/render
        (render-server.js — Puppeteer دائم الفتح، أسرع)
      - فشل الاتصال؟ → renderViaBrowsershot() (يفتح Chrome من الصفر،
        أبطأ بس شغّال دايمًا كـfallback)
      - ينضّف الملفات المؤقتة الأقدم من 120 ثانية (مش كل شي فورًا)
  → EscPosPrinterDriver::connect() + bitImage()
      - يتحقق من أبعاد الصورة عبر getimagesize() (مو escpos-php
        getWidth/getHeight المُحمّلة كسولًا)
      - WindowsPrintConnector (SMB) أو NetworkPrintConnector (TCP)
        حسب إعداد الطابعة
  → print-bridge.php (لو USB) يستقبل على 127.0.0.1:9100 ويمرر لـsmbclient
  → ورقة فعلية تطلع من الطابعة
```

### أنماط الطباعة (`mode`)

| القيمة | الوصف |
|---|---|
| `all` (افتراضي) | فاتورة كاشير مدمجة (كل الأصناف سوا) + نسخة لكل قسم تاني على طابعته |
| `merged` | فاتورة الكاشير المدمجة فقط |
| `departments` | نسخ الأقسام فقط، كل وحدة على طابعتها |
| `fawri` | فاتورة منفصلة لكل قسم، بس **كلها على طابعة الكاشير** (بدون توجيه لطابعات أقسام) — مرتبط بحقل `orders.is_fawri` |

---

## 5. مشاكل حقيقية واجهناها وحلولها (اقرأ قبل ما "تصلّح" شي مشابه)

### 5.1 — `$img->getWidth()`/`getHeight()` ترجع 0 دايمًا

مكتبة `mike42/escpos-php` بتحمّل أبعاد الصورة **كسولًا** (lazy) — ما
بتتحسب فعليًا إلا بعد استدعاء methode زي `toRasterFormat()`. فحص دفاعي
ساذج زي:
```php
if ($img->getWidth() <= 0) throw ...
```
بيرفض **كل صورة**، حتى الصحيحة 100%، لأنه بيتنفذ قبل أي تحميل فعلي.

**الحل**: استخدام `getimagesize()` مباشرة على ملف الصورة (مو على
كائن escpos-php) — بيرجع الأبعاد الحقيقية من هيدر الملف نفسه بدون أي
اعتماد على توقيت تحميل المكتبة.

### 5.2 — `page.screenshot({fullPage: true})` بترجع صورة فاضية

لو المحتوى الفعلي بالصفحة **أقصر** من ارتفاع الـviewport المضبوط
مسبقًا، `fullPage: true` بترجع صورة فاضية أو شبه فاضية بدل المحتوى —
هاد سلوك حقيقي بنسخة Chrome المستخدمة، انثبت بمقارنة مباشرة (نفس
المحتوى: `fullPage:true` = 3078 بايت فاضية، `fullPage:false` بنفس
اللحظة = 41027 بايت صحيحة).

**الحل** (بـ`render-server.js`): قياس `document.body.scrollHeight`
الفعلي، ضبط الـviewport على هاد الارتفاع بالضبط، وبعدين استخدام
`fullPage: false`.

### 5.3 — تنظيف الملفات المؤقتة كان يمسح ملف لسا قيد الاستخدام

`cleanupOldTempFiles()` كانت تمسح **كل** ملف مطابق للنمط بدون فحص
عمره — لو صار طلبين طباعة بنفس اللحظة تقريبًا، الطلب الأول ممكن يمسح
ملف الطلب الثاني وهو لسا قيد المعالجة (race condition).

**الحل**: عتبة عمر 120 ثانية — يمسح بس الملفات الأقدم من هيك.

### 5.4 — ارتفاع الفاتورة المقصوصة ينهار لـ0-1 بكسل

`resizeAndCrop()` كانت تحسب `$finalHeight = $cropRow + 1` — لمحتوى
قليل (فاتورة قصيرة)، هاد ممكن يطلع رقم شبه صفري.

**الحل**: `min(max($cropRow + 5, 100), $targetHeight)` — حد أدنى 100
بكسل مضمون.

### 5.5 — توجيه الطباعة لطابعة/محطة غلط (المشكلة الرئيسية، قسم 3)

راجع قسم 3 بالكامل.

### 5.6 — تكرار عمليات queue:work / نافذة cmd ظاهرة

راجع قسم 2.

### 5.7 — صفحة إعدادات الانستولر بتقصّ حقول

Inno Setup `TInputQueryWizardPage` إلها ارتفاع صفحة ثابت وما بتعمل
scroll تلقائي. 5 حقول + نص توضيحي طويل بنفس الصفحة كانت تخلي آخر حقلين
يترسموا **خارج** حدود الصفحة المرئية — بدون أي رسالة خطأ، الحقول موجودة
بالـDOM بس مش ظاهرة.

**الحل**: فصل الحقول على صفحتين منفصلتين (`DbPage` و`SvcPage`) بدل
صفحة وحدة.

### 5.8 — تخمين "أي طابعة كاشير فعّالة بالفرع" كان لسا موجود، وصار خطير فعليًا

بعد إصلاح 5.5، بقي fallback "توافق عكسي" جوا `resolveQueueForOrder()`
و`resolveCashierPrinter()`: لو ما في `printer_id` ولا `pos_register_id`
صريح، النظام كان يختار "أول طابعة كاشير فعّالة بالفرع" تلقائيًا. هاد
كان يبدو غير ضار وقت ما كان في محطة كاشير وحدة بس بالفرع.

**تحول لعطل حقيقي** لما صار الفرع فيه 4 طابعات كاشير فعّالة: أي طلب
طباعة بدون هوية محطة معروفة (أشهر مثال: فتح الكاشير من **واجهة
الإدارة** `/admin/pos` بدون جهاز مفعّل حقيقي — `posInfo` هناك بيكون
placeholder بدون `id`) كان يوجّه لمحطة **عشوائية مختلفة كل مرة**
(لوحظ فعليًا بسجل يوم واحد: نفس الفرع، فواتير توجهت لمحطات 2، 4، 5،
6، 15، 16 بالتناوب) — فاتورة حقيقية ممكن تطبع بمحطة حية بعيدة كليًا
عن مين طلبها.

**الحل**: أُلغي التخمين نهائيًا. الآن:
- `OrderController::printInvoice()` — لو ما في `printer_id` ولا
  `pos_register_id` بالطلب، يرجع خطأ `422` واضح فورًا ("يجب تحديد
  الطابعة أو محطة الكاشير") بدل ما يكمل ويخمّن.
- `resolveQueueForOrder()` و`resolveCashierPrinter()` — بدون هوية
  محطة صريحة، يرجعوا `'default'`/`null` مباشرة، وليس أي تخمين.

**الدرس**: أي "fallback للتوافق العكسي" لازم يُعاد تقييمه دوريًا —
افتراض كان آمن (محطة واحدة بالفرع) صار خطر فعلي بمجرد ما تغيّر واقع
النظام (عدة محطات)، بصمت، بدون أي تنبيه.

---

## 6. الطباعة والفرونت إند: نقاط التقاء مهمة

- `src/utils/posSecurity.ts` — تخزين آمن لـ`device_uuid` و`pos_register_info`
  (`posInfo`) محليًا بالمتصفح بعد التفعيل.
- `POSActivationPage.tsx` — شاشة التفعيل، بتنادي `/api/pos/activate`
  وتخزّن الناتج (`pos_info`) كـ`posInfo`.
- `pos.tsx` / `HospitalityPOS.tsx` — كل نداء لـ`/orders/{id}/print-invoice`
  **لازم** يبعت `pos_register_id: posInfo?.id` — نسيان هاد الحقل يرجّع
  المشكلة الأصلية (توجيه غلط) فورًا.
- الصفحة كاملة (`pos.tsx`) بتستخدم **container queries** (`@container`,
  `@sm:`, `@2xl:`, `@5xl:`) بدل viewport breakpoints عادية للتخطيط —
  السبب: العمود الفعلي المتاح للمنيو مضغوط بالسايدبار + صندوق السلة
  الثابت العرض، فـviewport breakpoints عادية (`sm:`, `xl:`) بتنكذب على
  المساحة الحقيقية المتوفرة وتحاول تعرض محتوى بمساحة أكبر من الموجود
  فعليًا. لو بدك تضيف تخطيط جديد بمنطقة المنيو، استخدم container query
  variants (`@`) مش viewport variants عادية.

---

## 7. حالة POS-012 (2026-09-06) — دراسة حالة كاملة لأخطر عطل هالسنة

توثيق هاي الحادثة بالتفصيل لأنها **نموذج متكرر محتمل**: أي محطة قديمة
لسا موجودة معرّضة لنفس السيناريو بالضبط.

**الأعراض الأولية**: "POS-012 طبع أوردر، الورق طلع من طابعة POS-010".

**التشخيص تطلب 5 جولات** لأنه كل تصحيح كان يكشف عطل جديد **كان موجود
من الأساس** بس مخفي وراء العطل يلي قبله:

1. تأكدنا التوجيه بالسيرفر المركزي صحيح (`resolveQueueForOrder` رجّعت
   `pos-register-12` صح) — بس الطلب ضل عالق بالقائمة، محدا يعالجه.
2. اكتشفنا: **ما في `queue:work` شغال إطلاقًا** على جهاز POS-012 —
   `Get-ScheduledTask O2QueueWorker` رجعت فاضية. الجهاز ما كان مفعّل
   (`.env` بلا `POS_REGISTER_ID`) وناقصه `run-hidden.vbs` بالكامل.
3. بعد تفعيل الجهاز وتسجيل الـTasks: القائمة طلعت `--queue=default` —
   `start-queue-worker.bat` عندهم **نسخة قديمة جدًا** (من قبل ميزة
   قراءة `POS_REGISTER_ID` بالكامل).
4. بعد تحديث الـ.bat: الطابعة المستخدمة طلعت طابعة **POS-010**
   (`printer_id: 11`) رغم كل شي — لأنه `PrintInvoiceJob.php` و
   `OrderPrintingService.php` المحليين **كمان نسخة قديمة** (قبل
   `resolveCashierPrinter`).
5. بعد تحديث هدول: الصورة طلعت فاضية (104 بايت، 0×0) — `render-server.js`
   المحلي **كمان نسخة قديمة** (قبل تصحيح `fullPage: false`).
6. بعد تحديث render-server.js: getimagesize() لسا ترفض الصورة رغم
   حجمها صحيح (58KB) — تبيّن إنه الملف الجديد **ما انسخ فعليًا لمكانه**
   (نزل بـDownloads وما انتقل). بعد النقل الصحيح: الصورة صارت تُقبل —
   بس تبيّن كمان إنه `ReceiptImageBuilder.php` المحلي **كمان نسخة
   قديمة** (بدون عتبة الـ120 ثانية)، فالملف كان ينمسح قبل ما يتفحص.

**الجذر الحقيقي**: جهاز POS-012 اتجهّز بمرحلة أقدم من كل هالتصحيحات،
ونسخته المحلية بقيت عالقة — **6 ملفات مختلفة** كانت قديمة بنفس الوقت،
كل وحدة بتسبب عرض مختلف تمامًا.

**الحل الدائم**: `installer/verify-station.ps1` (قسم 8) — لو كان موجود
وقتها، كان رح يظهر كل الـ6 مشاكل بفحص واحد يستغرق ثواني، بدل ساعات
تشخيص.

---

## 8. `verify-station.ps1` — الفحص التلقائي لكشف الكود القديم

```powershell
powershell -ExecutionPolicy Bypass -File "installer\verify-station.ps1" -InstallDir "C:\Program Files\O2System\backend"
```

بيفحص 7 توقيعات بالملفات المثبّتة فعليًا على الجهاز (مو الكود المركزي):

| الملف | التوقيع المطلوب | يدل على |
|---|---|---|
| `run-hidden.vbs` | `0, True` | إصلاح "Running" الدقيق + منع تكرار العمليات |
| `start-queue-worker.bat` | `POS_REGISTER_ID` | كشف قائمة المحطة تلقائيًا |
| `app\Jobs\PrintInvoiceJob.php` | `posRegisterId` | تمرير هوية المحطة للتنفيذ |
| `app\Services\Printing\OrderPrintingService.php` | `resolveCashierPrinter` | اختيار طابعة المحطة الصحيحة |
| `app\Services\Printing\Drivers\EscPosPrinterDriver.php` | `getimagesize` | فحص أبعاد الصورة الصحيح |
| `app\Services\Printing\Renderers\ReceiptImageBuilder.php` | `maxAgeSeconds` | منع حذف الملف قبل أوانه |
| `render-server.js` | `fullPage: false` | منع الصورة الفاضية |

**متى تشغّله**:
- تلقائيًا بنهاية أي تنصيب جديد (`provision.ps1` بينادي عليه لحاله).
- يدويًا ضد أي محطة موجودة، خصوصًا لو عندك شك إنها ما اتحدثت من فترة،
  أو قبل ما تصعّد أي بلاغ طباعة غريب — أول خطوة تشخيص، مش آخرها.

**لو فشل فحص**: انسخ الملف المحدّث من هاد الريبو (`o2-system-backend`)
لنفس المسار بالضبط عالمحطة، وأعد تشغيل التاسك المرتبط (`O2QueueWorker`
أو `O2RenderServer`) عبر قتل شجرة العمليات كاملة (قسم 2) وبعدين
`Start-ScheduledTask`.

---

## 9. الانستولر (`installer/`) — نظرة سريعة

| الملف | الدور |
|---|---|
| `setup.iss` | سكربت Inno Setup — بيبني `O2CashierSetup.exe` واحد يجهّز جهاز جديد بالكامل بدون إنترنت |
| `provision.ps1` | المنطق الفعلي: php.ini، `.env`، تفعيل PosRegister، تسجيل الخدمة/التاسكات، فحص `verify-station.ps1` بالنهاية |
| `verify-station.ps1` | فحص الكود القديم (قسم 8) |
| `stop-o2-tasks.ps1` | تنظيف كامل (tree-kill) + حذف التاسكات — يُستخدم بخطوة الـuninstall |
| `fix-session0-render.ps1` | ترحيل محطة قديمة (Services) لأسلوب Scheduled Tasks الجديد بدون إعادة تنصيب كامل |
| `README.md` | خطوات تجهيز مجلد `bundle/` (php/node/chrome/nssm/app) قبل الـcompile — خطوة تصير مرة وحدة يدويًا |

**تحذير مهم**: مجلد `bundle/app` (نسخة المشروع المُجمّعة بالانستولر) هو
**snapshot يدوي** — ما بيتحدث لحاله. أي تصحيح بهاد الملف
(`docs/PRINTING.md` نفسه دليل) لازم يترافق مع تحديث `bundle/app` قبل
أي compile جديد، وإلا الانستولر الجديد رح يبني نفس مشكلة POS-012 من
الصفر بمحطة جديدة كليًا.

---

## 10. تشخيص سريع — لائحة أوامر جاهزة

```powershell
# كل العمليات المرتبطة بـO2 (php/node/cmd/wscript)
Get-CimInstance Win32_Process -Filter "Name='php.exe' or Name='node.exe' or Name='cmd.exe' or Name='wscript.exe'" |
    Select-Object ProcessId, ParentProcessId, CommandLine

# حالة التاسكات
Get-ScheduledTask O2QueueWorker, O2RenderServer | Select-Object TaskName, State

# فحص كود قديم
powershell -ExecutionPolicy Bypass -File "C:\Program Files\O2System\backend\installer\verify-station.ps1" `
    -InstallDir "C:\Program Files\O2System\backend"

# آخر 60 سطر من سجل هاد المحطة بالتحديد (مش السيرفر المركزي!)
Get-Content "C:\Program Files\O2System\backend\storage\logs\laravel.log" -Tail 60

# تنظيف كامل (tree-kill) قبل أي إعادة تسجيل
$InstallDir = "C:\Program Files\O2System\backend"
$pattern = [regex]::Escape($InstallDir)
Get-CimInstance Win32_Process -Filter "Name='wscript.exe' or Name='cmd.exe'" |
    Where-Object { $_.CommandLine -match $pattern } |
    ForEach-Object { taskkill /F /T /PID $_.ProcessId }
```

**تذكير أساسي**: سجل الأخطاء الفعلي لأي مشكلة طباعة **محلي بكل محطة**
(`storage/logs/laravel.log` تحت نسخة المحطة نفسها) — مش بسجل السيرفر
المركزي. السيرفر المركزي بس بيسجل *قرار التوجيه* (أي queue انتخب)،
التنفيذ الفعلي وأي فشل فيه بيتسجل محليًا عند المحطة يلي عالجت الوظيفة.
