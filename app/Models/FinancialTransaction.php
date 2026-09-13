<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Scopes\BranchScope;

class FinancialTransaction extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    protected $fillable = [
        'branch_id',
        'shift_id',
        'cashier_id',
        'type',
        'amount',
        'reason',
        'timestamp',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'timestamp' => 'datetime',
    ];

    // ── Relationships ──────────────────────────────────────────────────────────

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    // ── Scopes ─────────────────────────────────────────────────────────────────

    public function scopeForBranch($query, int $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeForDate($query, $date)
    {
        return $query->whereDate('timestamp', $date);
    }
}
