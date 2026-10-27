<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HR-8: a loan someone repays through payroll (GSIS, Pag-IBIG, a bank or
 * cooperative), amortized monthly from `start_month`. Its balance is
 * never stored: total payable, less what was paid before IMIS, less its
 * lines on finalized payrolls (App\Support\HR\Payroll\Loans). Stopping it
 * ends further deductions without writing anything off.
 */
class EmployeeLoan extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'principal' => 'decimal:2',
            'total_payable' => 'decimal:2',
            'monthly_amortization' => 'decimal:2',
            'paid_before' => 'decimal:2',
            'granted_on' => 'date:Y-m-d',
            'start_month' => 'date:Y-m-d',
            'stopped_on' => 'date:Y-m-d',
            'cancelled_on' => 'date:Y-m-d',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PayComponent::class, 'pay_component_id');
    }

    public function payrollLines(): HasMany
    {
        return $this->hasMany(PayrollLine::class);
    }

    protected function label(): Attribute
    {
        return Attribute::get(fn () => $this->loadMissing('component')->component?->name.($this->reference ? " {$this->reference}" : '')
            .' of '.$this->loadMissing('employee.user')->employee?->user?->name);
    }
}
