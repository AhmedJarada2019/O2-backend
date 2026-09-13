<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * الفاتورة الرسمية — تُنشأ من الطلب بعد تقسيمه للأقسام (تذاكر).
 * الدفع يتم عبر payments ولا يُربط مباشرة بالطلب.
 *
 * تحتوي الفاتورة على جزئين:
 *   1. تفاصيل نقطة البيع (POS): pos_register_id, pos_code, pos_name, branch, user
 *   2. تفاصيل الفاتورة: number, time, date, currency, status, account_number
 *   3. تفاصيل الفتح والإغلاق: opened_by/at, closed_by/at
 */
class Invoice extends Model
{
    protected $fillable = [
        'number',
        'type',
        'order_id',
        'customer_id',
        'customer_name',
        'customer_phone',
        'entity_type',
        'entity_id',
        'entity_name',
        'entity_number',
        'branch_id',
        'status',
        'currency',
        'payment_method',
        'subtotal',
        'discount',
        'tax_total',
        'total',
        'paid_amount',
        'remaining_amount',
        'invoice_date',
        'due_date',
        'delivery_date',
        'expected_payment_date',
        'notes',
        'pos_register_id',
        'pos_code',
        'pos_name',
        'opened_by',
        'opened_at',
        'closed_by',
        'closed_at',
        'account_number',
        'approved_by',
        'approved_at',
        'supply_date',
        'reference_number',
        'exchange_rate',
        'daily_sequence',
        'financial_voucher_number',
        'vat_report_number',
    ];

    protected $casts = [
        'invoice_date' => 'datetime',
        'due_date' => 'datetime',
        'delivery_date' => 'datetime',
        'expected_payment_date' => 'datetime',
        'supply_date' => 'date',
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'approved_at' => 'datetime',
        'exchange_rate' => 'decimal:6',
        'daily_sequence' => 'integer',
    ];

    // ═══════════════════════════════════════════════════════
    //  العلاقات (Relationships)
    // ═══════════════════════════════════════════════════════

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * الكيان صاحب الفاتورة عند البيع "على الحساب" (مورد/زبون/موظف) — بديل
     * عام عن customer() يغطي الأنواع الثلاثة عبر entity_type/entity_id.
     */
    public function entity(): ?Model
    {
        if (! $this->entity_type || ! $this->entity_id) {
            return null;
        }

        return match ($this->entity_type) {
            'customer' => Customer::find($this->entity_id),
            'supplier' => Supplier::find($this->entity_id),
            'employee' => Employee::find($this->entity_id),
            default => null,
        };
    }

    /**
     * لقطة (snapshot) اسم ورقم الكيان وقت إنشاء الفاتورة — تُستخدم بدل الاستعلام
     * الحي عن الكيان حتى لا يتغيّر عرض فاتورة قديمة إذا تغيّر اسم/رقم الزبون
     * أو المورد أو الموظف لاحقاً (نفس مبدأ snapshot المطبّق في invoice_items).
     *
     * @return array{name: ?string, number: ?string}
     */
    public static function resolveEntitySnapshot(?string $entityType, ?int $entityId): array
    {
        if (! $entityType || ! $entityId) {
            return ['name' => null, 'number' => null];
        }

        $model = match ($entityType) {
            'customer' => Customer::find($entityId),
            'supplier' => Supplier::find($entityId),
            'employee' => Employee::find($entityId),
            default => null,
        };

        if (! $model) {
            return ['name' => null, 'number' => null];
        }

        return [
            'name' => $model->name,
            // للموظف: الرقم الوظيفي (employeeId) هو المعرّف المتعارف عليه، وليس
            // الهاتف. للزبون/المورد: الهاتف أولاً ثم الكود كبديل.
            'number' => $entityType === 'employee'
                ? ($model->employeeId ?? null)
                : ($model->phone ?? $model->mobile ?? $model->code ?? null),
        ];
    }

    /** نقطة البيع التي أُنشئت منها الفاتورة */
    public function posRegister(): BelongsTo
    {
        return $this->belongsTo(PosRegister::class, 'pos_register_id');
    }

    /** المستخدم الذي فتح الفاتورة */
    public function openedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /** المستخدم الذي أغلق الفاتورة */
    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /** المستخدم الذي عمد الفاتورة */
    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** القيد المحاسبي المرتبط بالطلب التابع لهذه الفاتورة */
    public function journalEntry()
    {
        if (! $this->order_id) {
            return null;
        }

        return Transaction::where('source_type', Order::class)
            ->where('source_id', $this->order_id)
            ->where('type', 'sale')
            ->first();
    }

    /** رقم القيد المحاسبي المرتبط (لعرضه بتبويب بيانات الفاتورة) */
    public function journalEntryNumber(): ?string
    {
        return $this->journalEntry()?->transaction_number;
    }

    public function paidAmount(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    public function remainingAmount(): float
    {
        return max(0, (float) $this->total - $this->paidAmount());
    }

    public function recalculateTotals(): void
    {
        $this->subtotal = $this->items()->sum('total_before_tax');
        $this->discount = $this->items()->sum('discount');
        $this->tax_total = $this->items()->sum('tax_amount');
        $this->total = $this->items()->sum('total');
        $this->paid_amount = $this->payments()->sum('amount');
        $this->remaining_amount = max(0, $this->total - $this->paid_amount);
        $this->resolveAutoStatus();
        $this->save();
    }

    /**
     * Auto-resolve status based on payment ratio.
     *
     * Rules:
     *  - cancelled → stays cancelled
     *  - paid → stays paid (terminal state)
     *  - draft → returns to awaiting_approval (so user must explicitly approve)
     *  - awaiting_approval/awaiting_payment/partial:
     *      • no payments   → awaiting_approval
     *      • some payments → partial
     *      • fully paid    → paid
     */
    public function resolveAutoStatus(): void
    {
        if (in_array($this->status, ['cancelled', 'paid', 'draft'], true)) {
            return;
        }

        if ($this->total > 0 && (float) $this->paid_amount >= (float) $this->total) {
            $this->status = 'paid';
        } elseif ($this->paid_amount > 0) {
            $this->status = 'partial';
        } else {
            // No payments yet — keep at awaiting_approval (so user must approve)
            $this->status = 'awaiting_approval';
        }
    }

    public static function generateNumber(): string
    {
        $prefix = 'INV-'.now()->format('Ymd').'-';
        $last = static::where('number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('number');

        $seq = $last ? (int) substr($last, -4) + 1 : 1;

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    /**
     * الرقم التسلسلي اليومي لنقطة بيع معينة — يبدأ من 1 كل يوم لكل جهاز
     * (بديل "الرقم اليومي" عند الأمين، مستقل عن رقم الفاتورة العام).
     */
    public static function nextDailySequence(?int $posRegisterId): int
    {
        if (! $posRegisterId) {
            return 1;
        }

        $last = static::where('pos_register_id', $posRegisterId)
            ->whereDate('created_at', now()->toDateString())
            ->max('daily_sequence');

        return ((int) $last) + 1;
    }
}
