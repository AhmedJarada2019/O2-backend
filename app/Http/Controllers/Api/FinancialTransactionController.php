<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\FinancialTransaction;
use App\Models\Shift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinancialTransactionController extends ApiController
{
    /**
     * حركات الصندوق (مصروفات/سحوبات/إيداعات...) — لتغذية لوحة المعلومات
     * وسجل الحركات اليومي. مفلترة تلقائيًا على فرع المستخدم عبر BranchScope.
     */
    public function index(Request $request): JsonResponse
    {
        $transactions = FinancialTransaction::with(['cashier'])
            ->when($request->branch_id, fn ($q) => $q->where('branch_id', $request->branch_id))
            ->when($request->date, fn ($q) => $q->whereDate('timestamp', $request->date))
            ->when($request->shift_id, fn ($q) => $q->where('shift_id', $request->shift_id))
            ->orderByDesc('timestamp')
            ->get();

        return $this->success('تم جلب الحركات المالية', $transactions);
    }

    /**
     * تسجيل حركة صندوق جديدة (مصروف/سحب/إيداع...) — الفرع والكاشير واليومية
     * تُحدَّد من المستخدم المسجّل دخوله، لا تُرسل من الواجهة (كانت الواجهة
     * تحفظ الحركة محليًا بالمتصفح بس بدون أي اتصال بالباك اند إطلاقًا).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|in:SALE,EXPENSE,WITHDRAWAL,DEPOSIT,REFUND,CASH_DROP,VOID',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string|max:1000',
        ]);

        $user = auth()->user();
        $branchId = $user->branch_id;

        if (! $branchId) {
            return $this->error('لا يوجد فرع مرتبط بحسابك — لا يمكن تسجيل الحركة', 422);
        }

        $shift = Shift::getOpenForBranch($branchId);

        $transaction = FinancialTransaction::create([
            'branch_id' => $branchId,
            'shift_id' => $shift?->id,
            'cashier_id' => $user->id,
            'type' => $validated['type'],
            'amount' => $validated['amount'],
            'reason' => $validated['reason'] ?? null,
            'timestamp' => now(),
        ]);

        return $this->success('تم تسجيل الحركة', $transaction->load('cashier'), 201);
    }
}
