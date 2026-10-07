<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * HR-8: a recurring allowance or deduction on someone's pay profile, from
 * a month on (and up to one, if it ends). Its amount overrides the
 * component's; for an automatic component, 0 exempts the person.
 * Written only by App\Support\HR\Payroll\PayProfiles.
 */
class EmployeePayItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
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

    /** In effect at any time during the month that starts on $month. */
    public function scopeInEffectDuring(Builder $query, Carbon $month): void
    {
        $query->whereDate('effective_from', '<=', $month->copy()->endOfMonth())
            ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $month->copy()->startOfMonth()));
    }

    protected function label(): Attribute
    {
        return Attribute::get(fn () => $this->loadMissing('component')->component?->name.' on the pay profile of '.$this->loadMissing('employee.user')->employee?->user?->name);
    }
}
