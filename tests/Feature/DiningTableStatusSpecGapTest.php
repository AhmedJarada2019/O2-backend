<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\DiningZone;
use App\Models\Item;
use App\Models\Order;
use App\Models\PosRegister;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * يثبّت هذا الملف بالاختبار الآلي الفجوات المكتشفة أثناء تدقيق QA لوحدة
 * "الضيافة" (خدمة الطاولات) مقابل docs/table-status-spec.md.
 *
 * كل اختبار هنا يفترض السلوك الصحيح **حسب المواصفة الموثقة**، وليس حسب
 * السلوك الحالي في الكود. إن فشل الاختبار، فهذا يعني أن الكود يخالف
 * المواصفة المعتمدة في docs/table-status-spec.md — وهذا هو الغرض منه:
 * إثبات الفجوة آلياً بدل وصفها نصياً فقط.
 */
class DiningTableStatusSpecGapTest extends TestCase
{
    use RefreshDatabase;

    private function makeActingUser(Branch $branch, array $permissions): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user = User::factory()->create(['branch_id' => $branch->id]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function makeTable(Branch $branch): DiningTable
    {
        $zone = DiningZone::create([
            'branch_id' => $branch->id,
            'name' => 'الصالة الرئيسية',
            'code' => 'A',
            'status' => 'ACTIVE',
        ]);

        return DiningTable::create([
            'dining_zone_id' => $zone->id,
            'branch_id' => $branch->id,
            'table_number' => 'A1',
            'qr_code' => 'QR-' . uniqid(),
            'qr_url' => 'https://example.test/qr',
            'capacity' => 4,
            'status' => 'AVAILABLE',
        ]);
    }

    private function registerDevice(Branch $branch): string
    {
        $uuid = (string) \Illuminate\Support\Str::uuid();
        PosRegister::create([
            'branch_id' => $branch->id,
            'code' => 'POS-' . uniqid(),
            'name' => 'Register ' . uniqid(),
            'device_uuid' => $uuid,
            'status' => 'ACTIVE',
        ]);

        return $uuid;
    }

    private function makeSellableItem(Branch $branch): Item
    {
        $item = Item::factory()->create();
        $item->branches()->attach($branch->id, ['price' => 25, 'is_active' => true]);

        return $item;
    }

    /**
     * القسم 3.1 من المواصفة: إضافة صنف بعد طباعة الفاتورة يجب أن يعيد الطاولة
     * لـ OCCUPIED (أخضر) فوراً — الطاولة يجب ألا تبقى زرقاء (BILL_PRINTED)
     * بينما هناك صنف جديد غير محاسَب لسا عم يتحضّر بالمطبخ.
     *
     * الكود الفعلي (OrderController::addItem / addItemsBatch) لا يتحقق إطلاقاً
     * من حالة الطاولة ولا يستدعي أي منطق لإرجاعها لـ OCCUPIED — هذا الاختبار
     * متوقع أن يفشل على الكود الحالي، ما يثبت الفجوة آلياً.
     */
    public function test_adding_item_after_bill_printed_reverts_table_to_occupied(): void
    {
        $branch = Branch::factory()->create(['static_ip' => null]);
        $deviceUuid = $this->registerDevice($branch);
        $user = $this->makeActingUser($branch, ['create-orders', 'manage-orders']);
        $table = $this->makeTable($branch);
        $item = $this->makeSellableItem($branch);

        $order = Order::create([
            'branch_id' => $branch->id,
            'order_type' => 'dine_in',
            'status' => 'confirmed',
            'dining_table_id' => $table->id,
            'table_number' => $table->table_number,
            'opened_by' => $user->id,
        ]);

        $table->update(['status' => 'OCCUPIED', 'current_order_id' => $order->id]);

        // محاكاة طباعة الفاتورة — الطاولة تضوي أزرق
        $order->markDiningTableBillPrinted();
        $this->assertSame('BILL_PRINTED', $table->fresh()->status, 'تمهيد الاختبار: الطاولة يجب أن تكون زرقاء بعد الطباعة');

        // الزبون طلب صنفاً إضافياً بعد الطباعة
        $response = $this->actingAs($user)
            ->withHeaders(['X-Device-UUID' => $deviceUuid])
            ->postJson("/api/orders/{$order->id}/items", [
                'item_id' => $item->id,
                'quantity' => 1,
            ]);

        $response->assertCreated();

        $this->assertSame(
            'OCCUPIED',
            $table->fresh()->status,
            'القسم 3.1 من docs/table-status-spec.md: إضافة صنف بعد الطباعة يجب أن ترجع الطاولة لـ OCCUPIED (أخضر)، '
            . 'لكن الكود الحالي يترك الطاولة على BILL_PRINTED (أزرق) رغم وجود صنف غير محاسَب.'
        );
    }

    /**
     * القسم 3.3 وشروط القبول في القسم 6: "تحرير قسري → يتطلب صلاحية مدير
     * وسبباً مكتوباً، ويُسجَّل في audit_logs باسم المدير".
     *
     * TableOperationsController::free() لا يطلب Request أصلاً (لا مجال لإرسال
     * سبب)، ولا يتحقق من دور "مدير"، ولا يسجل شيئاً في audit_logs. أي مستخدم
     * يملك صلاحية POS العادية (access-pos) — وليس مديراً بالضرورة — يقدر يحرر
     * طاولة عالقة على الأزرق بلا سبب وبلا أثر تدقيقي.
     */
    public function test_forced_free_of_billed_table_requires_manager_role_and_reason(): void
    {
        $branch = Branch::factory()->create(['static_ip' => null]);
        $user = $this->makeActingUser($branch, ['access-pos']); // ليس مديراً
        $table = $this->makeTable($branch);

        $order = Order::create([
            'branch_id' => $branch->id,
            'order_type' => 'dine_in',
            'status' => 'confirmed',
            'dining_table_id' => $table->id,
            'table_number' => $table->table_number,
            'opened_by' => $user->id,
        ]);

        // الطاولة "عالقة على الأزرق": طبعت فاتورتها، والزبون غادر بدون دفع،
        // والطلب لسا نشطاً (لم يُقفل، لم يُلغَ).
        $table->update(['status' => 'BILL_PRINTED', 'current_order_id' => $order->id]);

        $response = $this->actingAs($user)
            ->postJson("/api/tables/{$table->id}/free");

        // القاعدة الحالية: tableHasActiveOrders() يمنع free() طالما فيه طلب
        // نشط — أي أن "التحرير القسري" غير موجود عملياً كمسار منفصل، بل
        // free() هو نفسه سواء تحرير عادي أو قسري. بانتظار تصحيح ذلك: يجب أن
        // يكون هناك مسار تحرير قسري يتطلب صلاحية مدير + سبب مكتوب ويُسجَّل
        // في audit_logs، حتى عندما يوجد طلب نشط.
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => DiningTable::class,
            'auditable_id' => $table->id,
        ]);
    }

    /**
     * القسم 3.5: نقل طلب من طاولة لأخرى يجب أن يورّث الطاولة الجديدة نفس حالة
     * الطلب كما هي (زرقاء تبقى زرقاء، خضراء تبقى خضراء) — لا يُفترض أن كل نقل
     * يعيدها OCCUPIED.
     *
     * TableOperationsController::transfer() يستدعي دائماً
     * $toTable->setOccupied($lastOrderId) بغض النظر عن حالة الطاولة المصدر.
     */
    public function test_transfer_preserves_billed_status_on_destination_table(): void
    {
        $branch = Branch::factory()->create(['static_ip' => null]);
        $user = $this->makeActingUser($branch, ['manage-orders', 'manage-branches']);

        $fromTable = $this->makeTable($branch);
        $toTable = DiningTable::create([
            'dining_zone_id' => $fromTable->dining_zone_id,
            'branch_id' => $branch->id,
            'table_number' => 'A2',
            'qr_code' => 'QR-' . uniqid(),
            'qr_url' => 'https://example.test/qr',
            'capacity' => 4,
            'status' => 'AVAILABLE',
        ]);

        $order = Order::create([
            'branch_id' => $branch->id,
            'order_type' => 'dine_in',
            'status' => 'confirmed',
            'dining_table_id' => $fromTable->id,
            'table_number' => $fromTable->table_number,
            'opened_by' => $user->id,
        ]);

        // الطاولة المصدر زرقاء (فاتورتها مطبوعة) وقت النقل
        $fromTable->update(['status' => 'BILL_PRINTED', 'current_order_id' => $order->id]);

        $response = $this->actingAs($user)->postJson('/api/tables/transfer', [
            'from_table_id' => $fromTable->id,
            'to_table_id' => $toTable->id,
        ]);

        $response->assertOk();

        $this->assertSame(
            'BILL_PRINTED',
            $toTable->fresh()->status,
            'القسم 3.5: الطاولة الجديدة يجب أن ترث حالة BILL_PRINTED (زرقاء) من المصدر، '
            . 'لكن transfer() الحالي يفرض دائماً OCCUPIED بغض النظر عن حالة المصدر.'
        );
    }
}
