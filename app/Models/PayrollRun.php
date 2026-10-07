<?php

namespace App\Models;

use App\Enums\PayrollRunStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * HR-8: one month's payroll, released in two halves (the 15th and the
 * 30th). Written only by App\Support\HR\Payroll\Payrolls.
 */
class PayrollRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'status' => PayrollRunStatus::class,
            'first_release_on' => 'date:Y-m-d',
            'second_release_on' => 'date:Y-m-d',
            'finalized_at' => 'datetime',
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(PayrollEntry::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function isFinalized(): bool
    {
        return $this->status === PayrollRunStatus::Finalized;
    }

    /** The first day of the payroll month. */
    public function month(): Carbon
    {
        return Carbon::create($this->year, $this->month, 1)->startOfDay();
    }

    /** "October 2026". */
    protected function period(): Attribute
    {
        return Attribute::get(fn () => $this->month()->format('F Y'));
    }

    protected function label(): Attribute
    {
        return Attribute::get(fn () => "payroll for {$this->period}");
    }
}
