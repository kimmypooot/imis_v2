<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * HR-6c: a flexible work arrangement (CSC MC No. 06, s. 2022): flexitime,
 * compressed work week, work from home, or a combination. Written only by
 * App\Support\HR\Leave\FlexibleWork.
 */
class FwaArrangement extends Model
{
    public const TYPES = ['flexitime' => 'Flexitime', 'cww' => 'Compressed work week', 'wfh' => 'Work from home', 'combination' => 'Combination'];

    public const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'schedule' => 'array',
            'decided_at' => 'datetime',
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

    public function reports(): HasMany
    {
        return $this->hasMany(FwaReport::class);
    }

    /**
     * Approved and running on any day of the period.
     *
     * @param  Builder<self>  $query
     */
    public function scopeInEffect(Builder $query, Carbon $from, ?Carbon $to = null): void
    {
        $to ??= $from;
        $query->where('status', 'approved')
            ->whereDate('effective_from', '<=', $to)
            ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from));
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /**
     * The days off of a compressed work week, as ISO weekday numbers
     * (1 = Mon … 7 = Sun). Legacy default: Friday off.
     *
     * @return array<int, int>
     */
    public function restDays(): array
    {
        if ($this->type !== 'cww') {
            return [];
        }

        $off = collect($this->schedule ?? [])->filter(fn ($mode) => $mode === 'off')->keys()
            ->map(fn (string $day) => array_search($day, self::DAYS, true))
            ->filter(fn ($index) => $index !== false)
            ->map(fn (int $index) => $index + 1)
            ->values()->all();

        return $off ?: [5];
    }

    protected function label(): Attribute
    {
        return Attribute::get(fn () => 'flexible work arrangement of '.$this->loadMissing('employee.user')->employee?->user?->name);
    }
}
