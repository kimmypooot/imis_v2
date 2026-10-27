<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HR-8: one earning or deduction on a payroll entry. `adjusted` marks a
 * line HR changed or added by hand, so regenerating the entry warns
 * before discarding it. An override keeps the configured amount in
 * `original_amount`, with who, when and why; `remitted_on` marks a
 * deduction confirmed as actually paid.
 */
class PayrollLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'original_amount' => 'decimal:2',
            'adjusted' => 'boolean',
            'adjusted_at' => 'datetime',
            'remitted_on' => 'date:Y-m-d',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(PayrollEntry::class, 'payroll_entry_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PayComponent::class, 'pay_component_id');
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(EmployeeLoan::class, 'employee_loan_id');
    }

    public function adjuster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by');
    }

    public function isEarning(): bool
    {
        return $this->kind === 'earning';
    }
}
