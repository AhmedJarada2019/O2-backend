; ═══════════════════════════════════════════════════════════════════════
;  O2 System — Installer لتجهيز جهاز كاشير جديد (Inno Setup)
; ═══════════════════════════════════════════════════════════════════════
;  هاد السكربت بيبني installer واحد (.exe) بيجهّز أي لابتوب كاشير جديد
;  بالكامل بدون إنترنت: PHP + Node + Chrome + النظام نفسه + آليات تشغيل
;  دائمة لـ 3 عمليات (queue worker, print bridge, render server).
;
;  ملاحظة معمارية مهمة: print bridge بس هو Windows Service حقيقي (عبر
;  NSSM). queue worker وrender server مسجّلين كـ Scheduled Tasks تشتغل
;  عند تسجيل الدخول (Logon) — مو كـWindows Services — لأنه أي كود بيفتح
;  متصفح Chrome/Puppeteer بيطلع نتائج فاسدة/مبتورة لما يشتغل من جوا
;  Session 0 (بيئة الخدمات المعزولة)، بغض النظر عن حساب الخدمة. فحصنا هاد
;  فعليًا بإعادة إنتاج نفس المشكلة، وانحلّت بس لما صار التشغيل من جلسة
;  تفاعلية حقيقية. التفاصيل الكاملة بتعليق provision.ps1.
;
;  ⚠️ قبل ما تصير تقدر تعمل Compile لهاد الملف، لازم تجهّز مجلد "bundle"
;  جنبه (خطوة تصير مرة وحدة بس، على جهاز فيه إنترنت). شوف installer/README.md
;  للتفاصيل الكاملة خطوة-خطوة.
;
;  البنية المطلوبة جنب هاد الملف وقت الـCompile:
;    installer/
;      ├── setup.iss          (هاد الملف)
;      ├── provision.ps1
;      └── bundle/
;            ├── php/          PHP portable (php.exe بالجذر)
;            ├── node/         Node.js portable (node.exe بالجذر)
;            ├── chrome/       Chromium (chrome.exe بالجذر)
;            ├── nssm/         nssm.exe (win64)
;            └── app/          نسخة نظيفة من o2-system-backend
;                              (فيها vendor/ + node_modules/puppeteer، بدون
;                              .env وبدون .git)
;
;  الناتج: installer/output/O2CashierSetup.exe — انسخه لأي لابتوب كاشير
;  وشغّله (Run as Administrator). خلص.
; ═══════════════════════════════════════════════════════════════════════

#define MyAppName "O2 System - محطة كاشير"
#define MyAppVersion "1.0.0"
#define MyAppPublisher "O2 System"

[Setup]
AppId={{B7E4B9A1-2C4A-4F1E-9C7A-O2CASHIERSETUP}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher={#MyAppPublisher}
DefaultDirName={autopf}\O2System
DefaultGroupName=O2 System
DisableProgramGroupPage=yes
DisableWelcomePage=no
OutputDir=output
OutputBaseFilename=O2CashierSetup
Compression=lzma2
SolidCompression=yes
ArchitecturesInstallIn64BitMode=x64compatible
PrivilegesRequired=admin
WizardStyle=modern

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Files]
Source: "bundle\php\*"; DestDir: "{app}\php"; Flags: recursesubdirs createallsubdirs ignoreversion
Source: "bundle\node\*"; DestDir: "{app}\node"; Flags: recursesubdirs createallsubdirs ignoreversion
Source: "bundle\chrome\*"; DestDir: "{app}\chrome"; Flags: recursesubdirs createallsubdirs ignoreversion
Source: "bundle\nssm\nssm.exe"; DestDir: "{app}\tools"; Flags: ignoreversion
Source: "bundle\app\*"; DestDir: "{app}\backend"; Excludes: ".env,.git\*"; Flags: recursesubdirs createallsubdirs ignoreversion
Source: "provision.ps1"; DestDir: "{app}"; Flags: ignoreversion
Source: "stop-o2-tasks.ps1"; DestDir: "{app}"; Flags: ignoreversion

[Code]
var
  DbPage: TInputQueryWizardPage;
  SvcPage: TInputQueryWizardPage;

procedure InitializeWizard;
begin
  // ملاحظة: هاي الحقول موزعة على صفحتين مقصود، مش صفحة وحدة — صفحة الـwizard
  // بـInno Setup إلها ارتفاع ثابت وما بتعمل scroll تلقائي؛ لما كانت الحقول
  // الخمسة + النص التوضيحي الطويل كلهم بصفحة وحدة، آخر حقلين (كلمة سر حساب
  // الويندوز وكود التفعيل) كانوا يطلعوا خارج حدود الصفحة المرئية وينقطعوا
  // بدون أي رسالة خطأ - ثبت هذا فعليًا عالجهاز.
  DbPage := CreateInputQueryPage(wpSelectDir,
    'إعدادات جهاز الكاشير',
    'معلومات قاعدة البيانات والطابعة',
    'هاي المعلومات موحّدة لكل أجهزة الكاشير — اسأل المسؤول عن السيرفر ' +
    'المركزي إذا ما كنت متأكد. اسم الطابعة لازم يطابق بالضبط اسمها المثبّت ' +
    'بويندوز (Devices and Printers).');

  DbPage.Add('كلمة سر قاعدة البيانات (DB_PASSWORD):', True);
  DbPage.Add('اسم الطابعة المحلية (Windows printer name):', False);
  DbPage.Values[1] := 'XP-80C';

  SvcPage := CreateInputQueryPage(DbPage.ID,
    'حساب تشغيل الخدمات وتفعيل المحطة',
    'حساب الويندوز، وكود تفعيل نقطة البيع (اختياري)',
    'مهم: حساب الويندوز وكلمة سره لازم يكونوا لحساب حقيقي مسجّل دخول فيه ' +
    'عادةً على هالجهاز — الخدمات ما بتقدر تفتح متصفح (لرندر الإيصالات) إذا ' +
    'اشتغلت بحساب النظام الافتراضي (Local System).' + #13#10 + #13#10 +
    'كود التفعيل: اختياري — لو موجود (طلبه من الأدمن، صالح 15 دقيقة)، بيربط ' +
    'هالجهاز بمحطة كاشير محددة (PosRegister) فما تختلط طلبات الطباعة بين ' +
    'المحطات. سيبه فاضي لو ما بدك هالميزة (رح تستخدم قائمة انتظار مشتركة).');

  SvcPage.Add('اسم حساب الويندوز لتشغيل الخدمات:', False);
  SvcPage.Add('كلمة سر حساب الويندوز هاد:', True);
  SvcPage.Add('كود تفعيل نقطة البيع (اختياري):', False);
  SvcPage.Values[0] := GetUserNameString();
end;

function NextButtonClick(CurPageID: Integer): Boolean;
begin
  Result := True;
  if CurPageID = DbPage.ID then
  begin
    if DbPage.Values[0] = '' then
    begin
      MsgBox('لازم تدخل كلمة سر قاعدة البيانات.', mbError, MB_OK);
      Result := False;
    end;
    if DbPage.Values[1] = '' then
    begin
      MsgBox('لازم تدخل اسم الطابعة.', mbError, MB_OK);
      Result := False;
    end;
  end
  else if CurPageID = SvcPage.ID then
  begin
    if SvcPage.Values[0] = '' then
    begin
      MsgBox('لازم تدخل اسم حساب الويندوز.', mbError, MB_OK);
      Result := False;
    end;
    if SvcPage.Values[1] = '' then
    begin
      MsgBox('لازم تدخل كلمة سر حساب الويندوز — بدونها الخدمات ما رح تقدر تفتح متصفح لرندر الإيصالات.', mbError, MB_OK);
      Result := False;
    end;
  end;
end;

function GetDbPassword(Param: String): String;
begin
  Result := DbPage.Values[0];
end;

function GetPrinterName(Param: String): String;
begin
  Result := DbPage.Values[1];
end;

function GetServiceUsername(Param: String): String;
begin
  Result := SvcPage.Values[0];
end;

function GetServicePassword(Param: String): String;
begin
  Result := SvcPage.Values[1];
end;

function GetActivationToken(Param: String): String;
begin
  Result := SvcPage.Values[2];
end;

[Run]
Filename: "powershell.exe"; \
    Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\provision.ps1"" -InstallDir ""{app}\backend"" -PhpDir ""{app}\php"" -NodeDir ""{app}\node"" -ChromeExe ""{app}\chrome\chrome.exe"" -NssmExe ""{app}\tools\nssm.exe"" -DbPassword ""{code:GetDbPassword}"" -PrinterName ""{code:GetPrinterName}"" -ServiceUsername ""{code:GetServiceUsername}"" -ServicePassword ""{code:GetServicePassword}"" -ActivationToken ""{code:GetActivationToken}"""; \
    StatusMsg: "عم يجهّز الخدمات (PHP, Node, Chrome, وقاعدة البيانات)... هاي بتاخد شوي وقت."; \
    Flags: runascurrentuser waituntilterminated

[UninstallRun]
; O2PrintBridge is a real Windows Service (NSSM) - no browser involved, Session 0 is fine for it.
Filename: "{app}\tools\nssm.exe"; Parameters: "stop O2PrintBridge"; Flags: runhidden; RunOnceId: "StopPrintBridge"
Filename: "{app}\tools\nssm.exe"; Parameters: "remove O2PrintBridge confirm"; Flags: runhidden; RunOnceId: "RemovePrintBridge"
; O2QueueWorker and O2RenderServer are logon Scheduled Tasks, not services (see provision.ps1
; for why - Session 0 corrupts Chrome/Puppeteer screenshots regardless of the service account).
; "schtasks /End" alone is NOT enough here: each task's real process chain is
; wscript.exe -> cmd.exe -> php.exe/node.exe, and ending the task only stops the
; wscript.exe at the top - the cmd.exe (and its :loop auto-restart) survives as an
; orphan and keeps relaunching php.exe/node.exe on its own. stop-o2-tasks.ps1 does a
; real process-tree kill (taskkill /T) before deleting the tasks - see its header
; comment for how this was confirmed (3 concurrent queue:work processes on a real
; station, surviving repeated "clean" task re-registration).
Filename: "powershell.exe"; \
    Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\stop-o2-tasks.ps1"" -InstallDir ""{app}\backend"""; \
    Flags: runhidden; RunOnceId: "StopO2Tasks"
