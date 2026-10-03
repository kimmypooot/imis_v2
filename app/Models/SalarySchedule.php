<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * HR-4: a salary schedule (grade × step monthly salaries) as issued, e.g.
 * the 3rd tranche under NBC No. 601. In force from its effective date
 * until the next schedule's. Amounts are entered by HR from the issuance.
 */
class SalarySchedule extends Model
{
    protected $fillable = ['name', 'reference', 'effective_date', 'remarks'];

    protected function casts(): array
    {
        return ['effective_date' => 'date:Y-m-d'];
    }

    public function rates(): HasMany
    {
        return $this->hasMany(SalaryRate::class);
    }

    /**
     * The schedule in force on a date: the latest one effective by then.
     *
     * @param  Builder<self>  $query
     */
    public function scopeInForceOn(Builder $query, Carbon $date): void
    {
        $query->whereDate('effective_date', '<=', $date)->orderByDesc('effective_date');
    }

    protected function label(): Attribute
    {
        return Attribute::get(fn () => 'Salary schedule '.$this->name);
    }
}
