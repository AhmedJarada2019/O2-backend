# O2 System — توثيق المشروع الشامل

هاد الملف بيوثّق مشروع `o2-system-backend` بالكامل: المعمارية، الموديولات
الأساسية، قاعدة البيانات، والـAPI. لتفاصيل نظام الطباعة تحديدًا (معمارية
محطات الكاشير، الأعطال وحلولها، الانستولر) راجع
[`docs/PRINTING.md`](./PRINTING.md) — قسم كبير ومستقل بذاته.

---

## 1. نظرة عامة

- **الإطار**: Laravel 12، PHP 8.2.
- **الفرونت إند**: ريبو منفصل تمامًا — `o2-company-front` (React 18 +
  Vite + Tailwind 4 + Zustand + React Router 7)، اسمه الداخلي
  "RestoMaster". بيتواصل مع هاد الباك إند عبر API فقط (`config/cors.php`
  بيسمح لمنافذ Vite المحلية + IP شبكة داخلية `192.168.2.250:8095`).
- **قاعدة البيانات**: MySQL/MariaDB بالإنتاج (رغم إنه `.env.example`
  الافتراضي مضبوط على sqlite — تأكد من `.env` الفعلي بأي بيئة).
- **المصادقة**: Laravel Sanctum (token-based، مو stateful cookies
  بالمسار الأساسي).
- **الصلاحيات**: `spatie/laravel-permission` — أدوار وصلاحيات محفوظة
  بقاعدة البيانات، مطبّقة عبر middleware alias زي
  `permission:manage-orders|access-pos`.
- **الطباعة**: `mike42/escpos-php` + Puppeteer (render-server.js) مع
  Browsershot كـfallback — تفصيل كامل بـ[`docs/PRINTING.md`](./PRINTING.md).
- **الهاتف/الكول سنتر**: تكامل مخصص مع FreePBX (`config/freepbx.php`)
  عبر OAuth2 client-credentials.
- **مكتبات أخرى مهمة**: `maatwebsite/excel` (استيراد/تصدير)،
  `mpdf/mpdf` (كشوفات PDF)، `khaled.alshamaa/ar-php` (تشكيل نص عربي
  بالإيصالات).

---

## 2. المصادقة والصلاحيات والعزل حسب الفرع

### 2.1 — تسجيل الدخول

`AuthController::login` — تحقق عادي بـ`Auth::attempt`، وبعدين لو الطلب
فيه هيدر `X-Device-UUID` (يعني جاي من جهاز كاشير أو جهاز hospitality
مسجّل)، بيتأكد إنه فرع المستخدم يطابق فرع الجهاز نفسه (إلا
`super-admin` معفى من هاد الشرط) — يمنع تسجيل دخول مستخدم فرع على جهاز
فرع تاني.

### 2.2 — الصلاحيات

الكتالوج الكامل للأدوار/الصلاحيات محفوظ بقاعدة البيانات (seeder هو
المصدر الرسمي — لاحظ إنه ملف `permissions.ts` بالفرونت إند فيه نسخة
**جزئية/أقدم** من الصلاحيات الفعلية بالباك إند، فيه صلاحيات أدق
بالراوتس مش موجودة هناك زي `create-orders`, `add-payments`,
`post-journal`, `manage-printers`). لما توثّق صلاحية جديدة، اعتمد
الـseeder والـmiddleware بالراوتس، مش ملف الفرونت إند.

`GET /auth/me` بيرجع المستخدم الحالي + `roles` + `permissions` — هاد
يلي الفرونت إند بيستخدمه لبناء واجهته حسب الصلاحيات.

### 2.3 — العزل حسب الفرع (Multi-tenancy)

`app/Models/Scopes/BranchScope.php` — global scope بيضيف تلقائيًا
`WHERE branch_id = {user.branch_id}` لأي موديل مطبّق عليه، **إلا لو**:
المستخدم `super-admin`، أو مفيش مستخدم مسجّل دخول (سياق نظام)، أو
`branch_id` المستخدم فاضي. فيه حماية من التكرار اللانهائي (`static $lock`)
لو حل المستخدم نفسه استدعى استعلام محكوم بنفس الـscope.

### 2.4 — العزل حسب الشبكة (لأجهزة الكاشير/الـhospitality)

`CheckPosNetwork` middleware (مطبّق على معظم راوتس الطلبات/POS) —
بيتطلب هيدر `X-Device-UUID` يشاور على `PosRegister` أو
`HospitalityDevice` مسجّل وحالته `ACTIVE`، بيقارن subnet الـIP تبع
الطلب مع `static_ip` المسجّل بالفرع (يمنع وصول من خارج شبكة الفرع)،
وبيتأكد فرع المستخدم = فرع الجهاز. `CheckHospitalityNetwork` نفس
المنطق لراوتس الـhospitality.

### 2.5 ⚠️ درس أمني موثّق بالكود نفسه

فيه تعليقات عربية مطوّلة جوا `routes/api.php` توثّق عطل أمني حقيقي صار
سابقًا: تسجيل راوتس مكررة لنفس المسار/الطريقة (Laravel بياخد آخر تسجيل
لنفس method+URI) كان يلغي بصمت الـpermission gate عن راوتس إدارة
الطلبات — أي مستخدم مسجّل دخول (بغض النظر عن صلاحياته) كان يقدر يوصل
لكل عمليات إدارة الطلبات. انصلح، بس التعليقات موجودة كتحذير دائم. **أي
إضافة راوت جديد لازم تتأكد ما في مسار مكرر بنفس method+URI بمكان تاني
بالملف.**

---

## 3. الموديولات الأساسية

### الطلبات ونقطة البيع (Orders/POS)
**الموديلات**: `Order`, `OrderItem`, `OrderExecutionEvent`,
`OrderCancellationRequest`, `OrderCustomerExperience`, `Shift`,
`PosRegister`.
**الكنترولرز**: `OrderController` (الأكبر بالمشروع — إنشاء/تعديل/إضافة
أصناف/تأكيد/إلغاء/تسليم/تحويل/طباعة...)، `ShiftController` (فتح/إغلاق/
ترحيل الشفت)، `CashDrawerController` (فتح الصندوق عبر الطابعة، F9)،
`Admin\PosRegisterController` (تفعيل/إلغاء تفعيل أجهزة الكاشير)،
`ProductionTicketController` (بونات المطبخ: بدء/جاهز/تسليم).
**الوصف**: قلب النظام — دورة حياة الطلب الكاملة من الإنشاء، عبر تحضير
المطبخ، التجميع، التسليم/التقديم بالطاولة، التسوية المالية، والفوترة،
مع نقاط الطباعة المرتبطة بكل خطوة.

### التسوية والدفعات
`SettleController`, `PaymentMethodController`, موديل `Payment` — تسوية
دفع الطلب وتسجيل طريقة/طرق الدفع المستخدمة.

### الفوترة (Invoice)
**الموديلات**: `Invoice`, `InvoiceItem`.
**الكنترولرز**: `InvoiceController` (تحويل طلب لفاتورة، دفعات، قيد
محاسبي)، `InvoiceDetailsController` (تفاصيل الفاتورة: منتجات، دفعات،
محاسبة، خصومات، مخزون، جدول زمني، مرفقات، ملاحظات).

### فواتير المبيعات (Sales Invoices) — دفتر منفصل
**الموديلات**: `SalesInvoice`, `SalesInvoiceItem`, `SalesInvoicePayment`.
دفتر فوترة أعم (احتمال بيع بالجملة/B2B أو استيراد بيانات قديمة)، منفصل
عن مسار `Invoice` الحي بنقطة البيع، وبيتزامن معه عبر خدمات المزامنة
(`PosSalesSyncService`).

### المحاسبة (دفتر الأستاذ العام)
**الموديلات**: `Account`, `CostCenter`, `Entry`, `Transaction`,
`FinancialTransaction`, `AccountingPeriod`, `AccountingSetting`,
`FiscalYear`.
هاد المحرك المحاسبي (قيد مزدوج) يلي كل الموديولات التانية (طلبات،
فواتير، عملاء، موردين، موظفين) بتسجّل فيه — تفصيل الخدمات بقسم 5.

### العملاء (CRM)
**الموديلات**: `Customer`, `CustomerAddress`, `CustomerNote`,
`CustomerOccasion`, `CustomerComplaint`, `ComplaintFollowup`.
يجمع إدارة مالية (حسابات مدينة) مع CRM موجّه لمركز الاتصال (شكاوى،
مناسبات/أعياد ميلاد، ملاحظات)، وبوابة عميل عامة (`CustomerPortalController`)
عبر QR-code لتصفح الطاولة/الطلب النشط/طلب الحساب بدون تسجيل دخول.

### الموردين (حسابات دائنة)
`Supplier`, `PurchaseBill`, `PurchaseBillItem`, `PurchaseBillAttachment`
— دفتر مورّدين قياسي مع مسار فواتير شراء (مسودة → معتمدة → مدفوعة/
ملغية، تتبع تأخير).

### الموظفين (HR)
`Employee`, `EmployeeLoan`, `JobTitle`, `Department` — دفتر شبيه بالموردين
(سُلف، رواتب، قروض) مدمج بالمحرك المحاسبي، بالإضافة للهيكل التنظيمي
(أقسام/مسميات وظيفية) المستخدم لتوجيه طباعة المطبخ.

### الفروع (Multi-location)
موديل `Branch` — كل فرع إله IP ثابت خاص فيه (للعزل حسب الشبكة)، أصناف،
أقسام، طابعات، طاولات مرتبطة فيه.

### الأصناف/المنيو
`Item` — الكتالوج، معزول حسب الفرع، مرتبط بالأقسام لتوجيه طباعة المطبخ.

### الطاولات (Hospitality / Dine-in)
`DiningTable`, `DiningZone`, `HospitalityDevice` — تخطيط الصالة: جلوس،
نقل/دمج/فصل طاولات، حالة الطاولة، إقرار نداء النادل. أجهزة الـhospitality
(تابلت/كشك) معزولة حسب الشبكة بنفس أسلوب PosRegister.

### التوصيل (Delivery)
`DeliveryTrip`, `DeliveryTripStop`, `DeliveryZone` — تجميع طلبات جاهزة
برحلات سائق متعددة التوقفات، تتبع نتيجة كل توقف (تم التسليم/فشل)، حساب
رسوم التوصيل حسب المنطقة، ومسار طلب/موافقة إلغاء خاص بتوقفات التوصيل
المرسلة أصلاً.

### مركز الاتصال (Call Center)
`CallTicket` — المكالمات الواردة بتصير "تذاكر" الوكيل بياخدها، بيربطها
بعميل/طلب، وبيحلّها. راوتس منفصلة بتعرض بيانات FreePBX الخام (مكالمات
حية، سجل، إحصائيات طابور/وكلاء، امتدادات، تسجيلات) للمشرفين.

### الطباعة
`Printer`, `PrintRoute` — تفصيل كامل بـ[`docs/PRINTING.md`](./PRINTING.md).

### الخصومات
`Discount`, `DiscountTarget`, `DiscountSetting`, `DiscountExclusion`,
`DiscountUsageLog` — قواعد خصم قابلة للإعداد (نسبة/مبلغ، حسب أصناف/
فئات/عملاء) تُطبق وقت الدفع، مع تتبع استخدام.

### عروض الأسعار (Quotes)
`Quote`, `QuoteItem` — مسار عرض سعر بيقدر يتحول لفاتورة مبيعات — على
الأغلب لعملاء جملة/كيترينج.

### السندات (Vouchers)
`Voucher`, `VoucherAllocation` — سندات قبض/صرف مخصصة لفواتير/جهات
محددة — آلية قيد يدوي خارج مسار نقطة البيع.

### المصادقة والصلاحيات والتدقيق
`User`, `AuditLog` — `AuditObserver` وtrait `Auditable` بيتتبعوا التغييرات
عبر الموديلات.

---

## 4. قاعدة البيانات — نظرة عامة

117 ملف migration. أهم مجموعات الجداول:

| المجموعة | الجداول الرئيسية |
|---|---|
| البنية التنظيمية | `branches`, `departments`, `job_titles`, `employees`, `employee_loans` |
| الكتالوج | `items`, `branch_item` |
| المحاسبة | `accounts`, `cost_centers`, `accounting_periods`, `transactions`, `entries`, `journal_entries`, `accounting_settings`, `financial_transactions`, `fiscal_years` |
| المصادقة | `users`, جداول `spatie/laravel-permission` |
| العملاء | `customers`, `customer_addresses`, `customer_occasions`, `customer_complaints`, `complaint_followups`, `customer_notes` |
| الدفعات/الفوترة | `payment_methods`, `invoices`, `invoice_items`, `payments` |
| الخصومات | `discounts`, `discount_targets`, `discount_usage_logs`, `discount_settings` |
| فواتير المبيعات | `sales_invoices`, `sales_invoice_items`, `sales_invoice_payments` |
| الموردين | `suppliers`, `purchase_bills`, `purchase_bill_items`, `purchase_bill_attachments` |
| أجهزة نقطة البيع | `pos_registers`, `printers`, `printer_department`, `printer_item`, `print_routes` |
| الصالة | `hospitality_devices`, `dining_zones`, `dining_tables` |
| الطلبات | `orders`, `order_items` |
| الشفتات | `shifts` |
| المطبخ | `production_tickets`, `production_ticket_items` |
| التوصيل | `delivery_zones`, `delivery_trips`, `delivery_trip_stops` |
| عروض/سندات | `quotes`, `quote_items`, `vouchers`, `voucher_allocations` |
| متفرقات | `audit_logs` |

**ملاحظة**: جداول `orders`, `invoices`, `dining_tables`, `accounts`
عندها أعلى عدد migrations إضافية (تعديل أعمدة/قيود) — يعني هاي أكتر
الجداول تطورًا وحساسية بالمشروع، وأي تعديل عليها يستاهل حذر إضافي
ومراجعة migration history كاملة قبل التعديل.

---

## 5. الخدمات (`app/Services`)

### `Services/Accounting/` — محرك القيد المزدوج
`AccountCreationService`, `AccountLedgerService`,
`CustomerAccountingService`, `EmployeeAccountingService` /
`EmployeeModuleService` / `EmployeeStatementService`,
`JournalEntryValidationService` (يتأكد القيد متوازن قبل الترحيل),
`SettlementEngine`, `StatementClassifier`, `StatementExportService` /
`StatementResponseEnricher`, `SubledgerService` (حسابات مدينة/دائنة
عامة مشتركة بين عملاء/موردين/موظفين), `SupplierAccountingService`,
`SystemAccountProvisioner`, `TransactionPostingService` (نواة "رحّل
معاملة"), `TrialBalanceService`.

### `Services/CallCenter/`
`CallCenterService`, `CallTicketService`, `CustomerWorkspaceBuilder`.

### `Services/Delivery/`
`OrderCancellationService`.

### `Services/Discount/`
`DiscountEngineService`.

### `Services/Invoice/`
`InvoiceFromOrderService`.

### `Services/Operations/`
`DeliveryAssignmentService`, `OperationsDashboardService`,
`OrderExecutionService`.

### `Services/Order/`
`OrderPricingService`.

### `Services/Printing/`
راجع [`docs/PRINTING.md`](./PRINTING.md) للتفصيل الكامل.

### `Services/SalesInvoice/`
`PosSalesSyncService`, `SalesInvoiceExcelImportService`,
`SalesInvoiceJournalService`, `SalesInvoiceService`.

### `Services/Support/`
`IdempotencyService` (يمنع معالجة طلب مكرر مرتين — مهم لعملاء POS
اللي بيشتغلوا offline أحيانًا), `PhoneNormalizer`.

### توجيه الطباعة (top-level)
`DirectPrintRoutingService`, `PrintRoutingService` — تحديد الطابعة/
الطابعات المستهدفة حسب الأقسام/الأصناف.

---

## 6. خريطة الـAPI (`routes/api.php`، ~660 سطر)

**عام (بدون مصادقة)**: `branches`, `/login`, `/freepbx/test`,
`customer/*` (بوابة العميل عبر QR)، بالإضافة لـ`pos/activate`،
`hospitality/activate`، و`call-center/webhook/incoming` (webhook
موقّع HMAC، محكوم بـthrottle).

**بعد `auth:sanctum`** (~350-450 راوت إجمالي، فيها تكرار تاريخي موثّق
بالتعليقات — راجع قسم 2.5): يشمل مجموعات كبيرة لـ Orders/POS
(~45 راوت، أكبر مجموعة)، الطاولات، المستخدمين/الأدوار، الفروع،
الأصناف/المسميات الوظيفية، الموظفين (~20)، تفاصيل الفاتورة، الفواتير
المالية، فواتير المبيعات (~17)، المحاسبة (~13)، السنوات المالية،
إدارة نقاط البيع + الخصومات (~17)، إدارة التوصيل (~17)، مركز الاتصال
(8)، مراجعة إلغاء الطلبات، الأقسام، الموردين (14)، العملاء (15)،
الطابعات (8)، مسارات الطباعة (4)، أجهزة الصالة (7)، مناطق الصالة (10)،
عروض الأسعار (11)، السندات (11)، فواتير الشراء (10)، تشخيص PBX (6).

---

## 7. ملفات الإعداد المخصصة

| الملف | المحتوى |
|---|---|
| `config/printing.php` | إعدادات الإيصال الكاملة (عرض 58/80مم، خط عربي، ألوان، تخطيط RTL، إعدادات render-server) — راجع [`docs/PRINTING.md`](./PRINTING.md) |
| `config/freepbx.php` | تكامل FreePBX: base URL، OAuth token URL، client ID/secret |
| `config/call_center.php` | `webhook_secret` (HMAC للـwebhook الوارد)، `trip_max_stops` (افتراضي 3) |
| `config/cors.php` | نطاقات الفرونت إند المسموحة (منافذ Vite + IP الشبكة الداخلية) |
| `config/permission.php` | إعدادات حزمة spatie/laravel-permission |
| `config/telescope.php` | Laravel Telescope (أداة تصحيح/profiling، للتطوير فقط) |

---

## 8. الاختبارات

PHPUnit 11 (مش Pest). قاعدة بيانات SQLite بالذاكرة للاختبارات.

**Feature**: `CrmCustomerDirectoryTest`,
`DeliveryManagementAuthorizationTest` (فحص صلاحيات إدارة التوصيل —
مرتبط بدرس قسم 2.5)، `DiscountAccountingTest`.

**Unit**: `DeliveryWorkflowContractTest`, `InvoiceItemResourceTest`,
`PhoneNormalizerTest`, `Services/DiscountEngineServiceTest`,
`Services/JournalEntryValidationServiceTest`,
`Services/StatementClassifierTest`.

**تغطية الاختبارات ضعيفة نسبيًا** مقارنة بحجم المشروع — مركّزة بس على
محرك الخصومات، فحص القيد المحاسبي، تصنيف الكشوفات، سير عمل التوصيل،
تطبيع رقم الهاتف، ودليل عملاء الـCRM. الطلبات، الطباعة، مركز الاتصال،
محرك الترحيل المحاسبي، وفواتير المبيعات **بدون اختبارات مخصصة** —
أولوية واضحة لو حدا بده يزيد تغطية الاختبارات مستقبلاً.

---

## 9. النشر والعمليات

- **ما في Docker ولا CI** (`.github/`, `Dockerfile`, `docker-compose.yml`)
  — النشر مباشر على بيئة Windows/Linux مختلطة (سيرفر مركزي Linux +
  محطات كاشير Windows، تفصيل بـ[`docs/PRINTING.md`](./PRINTING.md)).
- **`installer/`** — انستولر Windows الكامل لمحطات الكاشير، موثّق
  بالكامل بـ[`docs/PRINTING.md`](./PRINTING.md) قسم 9.

### ⚠️ ديون تقنية موجودة بجذر المشروع — تحتاج تنظيف

- `fix_migration.php`, `fix_migration2.php` — سكربتات إصلاح قاعدة
  بيانات لمرة وحدة (مش جزء من مسار الـmigrations العادي). **لا تشغّلها
  عشوائيًا** — افهم أول شو بالضبط بتعمل قبل أي تشغيل، واعتبرها مرشحة
  للحذف بعد التأكد إنها اتنفذت فعلاً ومش محتاجة تتكرر.
- `.env.bak2` — نسخة احتياطية قديمة لملف `.env` بجذر المشروع، **قد
  تحتوي أسرار حقيقية** (كلمات سر قاعدة بيانات، مفاتيح). تأكد إنها
  بـ`.gitignore` ولا تُنشر/تُشارك أبدًا.
- `TRANSFER_FIX_SUMMARY.md` — توثيق عطل سابق (نقل طلب/طاولة على
  الأغلب) — مصدر مفيد لو بدك تبني سجل تغييرات (changelog) لاحقًا.
- `scripts/baseline-migrations.php` — سكربت لتثبيت قاعدة بيانات جديدة
  كأنها مهاجَرة أصلاً لخط أساس معين — مفيد بس فهمه قبل الاستخدام.

---

## 10. للتفصيل الكامل لنظام الطباعة

راجع [`docs/PRINTING.md`](./PRINTING.md) — معمارية محطات الكاشير
الكاملة، آلية التوجيه حسب PosRegister، كل الأعطال الحقيقية وحلولها
(بما فيها دراسة حالة POS-012 الكاملة)، الانستولر، وأوامر تشخيص جاهزة.
