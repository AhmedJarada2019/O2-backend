<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Department;
use App\Models\PosRegister;
use App\Models\Printer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * PrinterSeeder
 * ─────────────────────────────────────────────────────────────
 * يحذف كل الطابعات القديمة وينشئ 10 طابعات فرع غزة الحقيقية + طابعة
 * الكاشير، ويربط كل طابعة بأقسامها عبر printer_department (هو ما تقرأه
 * PrintRoutingService / DirectPrintRoutingService للتوجيه).
 *
 * طابعات "تجميع" و"تيك أوي" تُنشأ بدون ربط أقسام (نقاط تجميع).
 *
 * طابعة الكاشير (CASHIER) موصولة USB بجهاز الكاشير نفسه — ip_address
 * تشاور على جسر الطباعة المحلي (127.0.0.1:9100 عبر print-bridge.php)
 * بدل مشاركة SMB عبر الشبكة اللي كانت عرضة لمشاكل صلاحيات/بروتوكول.
 * راجع print-bridge.php و start-print-bridge.bat لتفاصيل التشغيل.
 *
 * التشغيل:  php artisan db:seed --class=PrinterSeeder
 */
class PrinterSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            // 1. حذف كل الطابعات القديمة
            //    (يتسلسل الحذف تلقائيًا على printer_department / printer_item / print_routes)
            Printer::query()->delete();

            // 2. فرع غزة
            $gaza = Branch::query()
                ->where('code', 'GAZA-01')
                ->orWhere('name', 'like', '%غزة%')
                ->first()
                ?? Branch::create([
                    'name' => 'فرع غزة الرئيسي',
                    'code' => 'GAZA-01',
                    'address' => 'غزة',
                    'is_active' => true,
                    'isMainBranch' => false,
                    'openingTime' => '08:00:00',
                    'closingTime' => '23:59:00',
                ]);

            // 3. الطابعات: [الاسم, IP, النوع, أكواد الأقسام التي تُطبع عليها]
            $printers = [
                ['بيتزا',               '192.168.2.242', 'KITCHEN', ['C01S01']],
                ['الكالزوني',           '192.168.2.245', 'KITCHEN', ['C01S02']],
                ['الغربي',              '192.168.2.246', 'KITCHEN', ['C02', 'C02S01', 'C02S02', 'C02S03', 'C03', 'C03S01']],
                ['الشاورما',            '192.168.2.248', 'KITCHEN', ['C09', 'C09S01']],
                ['الحلو الشرقي',        '192.168.2.244', 'KITCHEN', ['C04', 'C04S01', 'C05', 'C05S03', 'C05S04', 'C05S05', 'C07', 'C07S01']],
                ['جيلاتو و كيك',        '192.168.2.249', 'KITCHEN', ['C05S01', 'C05S02', 'C06', 'C06S01', 'C06S02', 'C06S03', 'C06S05']],
                ['البار',               '192.168.2.243', 'BAR',     ['C05S06', 'C06S04', 'C06S06', 'C08', 'C08S01']],
                ['تيك أوي',             '192.168.2.247', 'OTHER',   []],
                ['تجميع غربي و شاورما', '192.168.2.240', 'OTHER',   []],
                ['تجميع صالة',          '192.168.2.241', 'OTHER',   []],
            ];

            foreach ($printers as [$name, $ip, $type, $deptCodes]) {
                $printer = Printer::create([
                    'branch_id' => $gaza->id,
                    'name' => $name,
                    'ip_address' => $ip,
                    'port' => '9100',
                    'type' => $type,
                    'is_active' => true,
                    'print_on_direct' => true, // مطلوب لوضع "تنفيذ وطباعة" الفوري
                ]);

                if ($deptCodes) {
                    $ids = Department::whereIn('code', $deptCodes)->pluck('id')->all();
                    $printer->departments()->sync($ids);
                }
            }

            // 4. طابعة الكاشير — USB محلي عبر جسر الطباعة (127.0.0.1:9100)، لا تُربط
            //    بأقسام بل بجهاز كاشير (POS register) محدد
            $cashierRegister = PosRegister::where('branch_id', $gaza->id)->first();

            if ($cashierRegister) {
                Printer::create([
                    'branch_id' => $gaza->id,
                    'name' => 'كاشير رئيسي',
                    'ip_address' => '127.0.0.1',
                    'port' => '9100',
                    'type' => 'CASHIER',
                    'linked_pos_register_id' => $cashierRegister->id,
                    'is_active' => true,
                    'print_on_direct' => false,
                ]);
            } else {
                $this->command?->warn('⚠️ ما فيه جهاز كاشير (POS register) لفرع غزة — تخطّينا إنشاء طابعة الكاشير');
            }
        });

        $this->command?->info('✅ حُذفت الطابعات القديمة وأُنشئت 10 طابعات + طابعة الكاشير لفرع غزة مع ربط الأقسام');
    }
}
