<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Printer;
use App\Models\PrintRoute;
use App\Services\Printing\PrinterService;
use App\Services\Printing\OrderPrintingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class PrinterController extends Controller
{
    /**
     * جلب جميع الطابعات الخاصة بالفرع الحالي
     */
    public function index(Request $request)
    {
        $branchId = $this->resolveBranchId($request);

        $query = Printer::with(['linkedPosRegister', 'departments', 'items'])
            ->orderByDesc('is_active')
            ->orderBy('name');

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        $printers = $query->get();

        return response()->json([
            'success' => true,
            'data'    => $printers,
        ]);
    }

    /**
     * إضافة طابعة جديدة
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'                   => 'required|string|max:255',
            'ip_address'             => 'required|string|max:45',
            'port'                   => 'nullable|string|max:10',
            'type'                   => 'required|in:CASHIER,KITCHEN,BAR,OTHER',
            'branch_id'              => 'nullable|integer|exists:branches,id',
            'linked_pos_register_id' => 'nullable|integer|exists:pos_registers,id',
            'print_on_direct'             => 'nullable|boolean',
            'department_ids'         => 'nullable|array',
            'department_ids.*'       => 'integer|exists:departments,id',
            'item_ids'               => 'nullable|array',
            'item_ids.*'             => 'integer|exists:items,id',
        ]);

        $branchId = $validated['branch_id'] ?? $this->resolveBranchId($request);

        if ($branchId === null) {
            return response()->json([
                'success' => false,
                'message' => 'branch_id مطلوب. يرجى تحديد الفرع أو التأكد من أن المستخدم مرتبط بفرع.',
            ], 400);
        }

        // التحقق من الحقول حسب النوع
        if ($validated['type'] === 'CASHIER') {
            if (empty($validated['linked_pos_register_id'])) {
                throw ValidationException::withMessages([
                    'linked_pos_register_id' => 'يجب تحديد جهاز كاشير (POS) لطابعة الكاشير',
                ]);
            }
            // التأكد من أن الجهاز تابعة للفرع
            \App\Models\PosRegister::where('branch_id', $branchId)
                ->where('id', $validated['linked_pos_register_id'])
                ->firstOrFail();
        } else {
            if (empty($validated['department_ids'])) {
                throw ValidationException::withMessages([
                    'department_ids' => 'يجب تحديد قسم واحد على الأقل لطابعة الأقسام',
                ]);
            }
        }

        // فحص عدم التكرار
        // طابعات الكاشير المربوطة بـUSB بتستخدم 127.0.0.1 محليًا على جهاز كل
        // محطة على حدة (كل محطة إلها print-bridge محلي خاص فيها) - نفس
        // العنوان بيتكرر بشكل طبيعي ومتوقع بين محطات مختلفة بنفس الفرع. أما
        // طابعة شبكة حقيقية (IP زي 192.168.1.50) فعنوانها فريد فعليًا لجهاز
        // واحد بالشبكة، فلازم يضل التكرار ممنوع على مستوى الفرع كامل - غير
        // هيك ما منقدر نمسك غلطة تكرار نفس IP الشبكة بمحطتين مختلفتين بالخطأ.
        $duplicateQuery = Printer::where('branch_id', $branchId)
            ->where('ip_address', $validated['ip_address']);

        if ($validated['type'] === 'CASHIER' && $this->isLoopbackAddress($validated['ip_address'])) {
            $duplicateQuery->where('linked_pos_register_id', $validated['linked_pos_register_id']);
        }

        if ($duplicateQuery->exists()) {
            throw ValidationException::withMessages([
                'ip_address' => $validated['type'] === 'CASHIER'
                    ? 'هذه المحطة (POS) عندها طابعة كاشير مربوطة بنفس العنوان مسبقاً'
                    : 'طابعة بهذا العنوان موجودة مسبقاً في هذا الفرع',
            ]);
        }

        $printer = Printer::create([
            'name'                   => $validated['name'],
            'ip_address'             => $validated['ip_address'],
            'port'                   => $validated['port'] ?? '9100',
            'type'                   => $validated['type'],
            'branch_id'              => $branchId,
            'linked_pos_register_id' => $validated['linked_pos_register_id'] ?? null,
            'print_on_direct'             => $validated['print_on_direct'] ?? false,
            'is_active'              => true,
        ]);

        // حفظ الأقسام والأصناف (لغير الكاشير)
        if ($validated['type'] !== 'CASHIER') {
            if (!empty($validated['department_ids'])) {
                $printer->departments()->sync($validated['department_ids']);
            }
            if (!empty($validated['item_ids'])) {
                $printer->items()->sync($validated['item_ids']);
            }
        }

        $printer->load(['linkedPosRegister', 'departments', 'items']);

        return response()->json([
            'success' => true,
            'message' => 'تمت إضافة الطابعة بنجاح',
            'data'    => $printer,
        ], 201);
    }

    /**
     * جلب طابعة بالمعرّف
     */
    public function show(Request $request, $id)
    {
        $branchId = $this->resolveBranchId($request);

        $printer = Printer::where('branch_id', $branchId)
            ->with(['routes' => function ($q) {
                $q->with(['user', 'category', 'item']);
            }, 'linkedPosRegister', 'departments', 'items'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => $printer,
        ]);
    }

    /**
     * تحديث طابعة
     */
    public function update(Request $request, $id)
    {
        $branchId = $this->resolveBranchId($request);

        $printer = Printer::where('branch_id', $branchId)->findOrFail($id);

        $validated = $request->validate([
            'name'                   => 'sometimes|string|max:255',
            'ip_address'             => 'sometimes|string|max:45',
            'port'                   => 'nullable|string|max:10',
            'type'                   => 'sometimes|in:CASHIER,KITCHEN,BAR,OTHER',
            'is_active'              => 'sometimes|boolean',
            'print_on_direct'             => 'sometimes|boolean',
            'linked_pos_register_id' => 'nullable|integer|exists:pos_registers,id',
            'department_ids'         => 'nullable|array',
            'department_ids.*'       => 'integer|exists:departments,id',
            'item_ids'               => 'nullable|array',
            'item_ids.*'             => 'integer|exists:items,id',
        ]);

        $printerType = $validated['type'] ?? $printer->type;
        $resolvedPosRegisterId = array_key_exists('linked_pos_register_id', $validated)
            ? $validated['linked_pos_register_id']
            : $printer->linked_pos_register_id;

        // فحص عدم التكرار عند تغيير IP (نفس منطق store() أعلاه - طابعات
        // الكاشير المربوطة بـUSB بتستخدم 127.0.0.1 محليًا فلازم نميزها حسب
        // محطة الكاشير (linked_pos_register_id) مش الفرع لحاله. طابعة شبكة
        // حقيقية (IP فعلي) بتضل خاضعة لتفرد كامل الفرع لأنه عنوانها فريد
        // فعليًا لجهاز واحد بالشبكة).
        if (isset($validated['ip_address'])) {
            $duplicateQuery = Printer::where('branch_id', $branchId)
                ->where('ip_address', $validated['ip_address'])
                ->where('id', '!=', $id);

            if ($printerType === 'CASHIER' && $this->isLoopbackAddress($validated['ip_address'])) {
                $duplicateQuery->where('linked_pos_register_id', $resolvedPosRegisterId);
            }

            if ($duplicateQuery->exists()) {
                throw ValidationException::withMessages([
                    'ip_address' => $printerType === 'CASHIER'
                        ? 'هذه المحطة (POS) عندها طابعة كاشير مربوطة بنفس العنوان مسبقاً'
                        : 'طابعة بهذا العنوان موجودة مسبقاً في هذا الفرع',
                ]);
            }
        }

        // التحقق من الحقول حسب النوع
        if ($printerType === 'CASHIER') {
            if (isset($validated['linked_pos_register_id']) && $validated['linked_pos_register_id'] !== null) {
                \App\Models\PosRegister::where('branch_id', $branchId)
                    ->where('id', $validated['linked_pos_register_id'])
                    ->firstOrFail();
            }
            // إزالة الأقسام والأصناف عند التحويل لكاشير
            $printer->departments()->detach();
            $printer->items()->detach();
            $validated['linked_pos_register_id'] = $validated['linked_pos_register_id'] ?? $printer->linked_pos_register_id;
        } else {
            // إزالة linked_pos_register_id عند التحويل لأقسام
            $validated['linked_pos_register_id'] = null;
        }

        $printer->update(collect($validated)->only([
            'name', 'ip_address', 'port', 'type', 'is_active', 'print_on_direct', 'linked_pos_register_id',
        ])->toArray());

        // تحديث الأقسام والأصناف (لغير الكاشير)
        if ($printerType !== 'CASHIER') {
            if (array_key_exists('department_ids', $validated)) {
                $printer->departments()->sync($validated['department_ids'] ?? []);
            }
            if (array_key_exists('item_ids', $validated)) {
                $printer->items()->sync($validated['item_ids'] ?? []);
            }
        }

        $printer->load(['linkedPosRegister', 'departments', 'items']);

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث الطابعة',
            'data'    => $printer->fresh(['linkedPosRegister', 'departments', 'items']),
        ]);
    }

    /**
     * حذف طابعة + قواعدها المرتبطة
     */
    public function destroy(Request $request, $id)
    {
        $branchId = $this->resolveBranchId($request);

        $printer = Printer::where('branch_id', $branchId)->findOrFail($id);

        // حذف القواعد المرتبطة أولاً
        PrintRoute::where('printer_id', $id)->delete();
        $printer->delete();

        return response()->json([
            'success' => true,
            'message' => 'تم حذف الطابعة وقواعدها المرتبطة',
        ]);
    }

    /**
     * اختبار الاتصال بالطابعة
     */
    public function testConnection(Request $request, $id)
    {
        $branchId = $this->resolveBranchId($request);

        $printer = Printer::where('branch_id', $branchId)->findOrFail($id);
        $result = $printer->testConnection();

        return response()->json($result);
    }

    /**
     * جلب قواعد التوجيه لطابعة محددة
     */
    public function routes(Request $request, $printerId)
    {
        $branchId = $this->resolveBranchId($request);

        // التأكد من أن الطابعة تابعة للفرع
        Printer::where('branch_id', $branchId)->findOrFail($printerId);

        $routes = PrintRoute::where('printer_id', $printerId)
            ->with(['user', 'category', 'item'])
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $routes,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────

    /**
     * عنوان "محلي" بيرجع لنفس الجهاز يلي شغّل عليه العملية - مش عنوان شبكة
     * حقيقي فريد لجهاز واحد. طابعات USB المربوطة عبر print-bridge المحلي
     * (كل محطة كاشير إلها نسخة خاصة فيها شغالة على جهازها) بتستخدم هيك عنوان،
     * فتكراره بين محطات مختلفة بنفس الفرع طبيعي ومتوقع.
     */
    private function isLoopbackAddress(string $ip): bool
    {
        return in_array(strtolower(trim($ip)), ['127.0.0.1', 'localhost', '::1'], true);
    }

    private function resolveBranchId(Request $request): ?int
    {
        if ($request->has('branch_id')) {
            return (int) $request->branch_id;
        }

        $user = Auth::user();
        if ($user && $user->branch_id) {
            return (int) $user->branch_id;
        }

        $branchHeader = $request->header('X-Branch-Id');
        if ($branchHeader) {
            return (int) $branchHeader;
        }

        // Fallback: أول فرع في قاعدة البيانات (للمستخدمين العامين)
        $firstBranch = \App\Models\Branch::first();
        if ($firstBranch) {
            return (int) $firstBranch->id;
        }

        return null;
    }

    public function testPrint(Request $request, $id, PrinterService $printerService)
    {
        $branchId = $this->resolveBranchId($request);
        $printer = Printer::where('branch_id', $branchId)->findOrFail($id);

        $result = $printerService->testPrint($printer);

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    public function printInvoice(Request $request, $orderId, OrderPrintingService $printingService)
    {
        $order = Order::with(['items', 'customer', 'branch'])->findOrFail($orderId);

        $result = $printingService->printInvoiceToCashier($order);

        return response()->json($result, $result['success'] ? 200 : 500);
    }
}
