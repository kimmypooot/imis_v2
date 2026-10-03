<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HR-6c: a request to convert leave credits to cash — partial
 * monetization of Vacation Leave, or terminal leave (all VL and SL on
 * separation). Written only by App\Support\HR\Leave\LeaveMonetizations.
 */
class LeaveMonetization extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'vl_days' => 'decimal:3',
            'sl_days' => 'decimal:3',
            'vl_balance' => 'decimal:3',
            'sl_balance' => 'decimal:3',
            'monthly_salary' => 'decimal:2',
            'amount' => 'decimal:2',
            'calendar_year' => 'integer',
            'decided_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    protected function label(): Attribute
    {
        return Attribute::get(fn () => ($this->kind === 'terminal' ? 'terminal leave ' : 'leave monetization ').($this->reference_number ?? "#{$this->id}")
            .' of '.$this->loadMissing('employee.user')->employee?->user?->name);
    }
}
