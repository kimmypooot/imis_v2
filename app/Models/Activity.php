<?php

namespace App\Models;

use App\Enums\ActivityMode;
use App\Enums\ActivityStatus;
use Carbon\CarbonInterface;
use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Calendar of Activities: one occurrence of an office's activity. It
 * always has a year and month; `starts_on`/`ends_on` are null while the
 * date is still to follow (status `planned`). Whether it has moved comes
 * from its move history, not its status. PALD's removal soft-deletes it
 * with a reason.
 */
class Activity extends Model
{
    /** @use HasFactory<ActivityFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organizational_unit_id', 'title', 'responsible', 'participants', 'venue', 'mode', 'remarks',
        'year', 'month', 'starts_on', 'ends_on', 'starts_at', 'ends_at', 'all_day',
        'status', 'cancel_reason', 'unplanned', 'series_id', 'detached_from_series_id',
        'returned_note', 'returned_at', 'removed_reason', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'mode' => ActivityMode::class,
            'status' => ActivityStatus::class,
            'year' => 'integer',
            'month' => 'integer',
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'all_day' => 'boolean',
            'unplanned' => 'boolean',
            'returned_at' => 'datetime',
        ];
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'organizational_unit_id');
    }

    /**
     * Date changes, latest first.
     */
    public function moves(): HasMany
    {
        return $this->hasMany(ActivityMove::class)->latest('created_at')->latest('id');
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(ActivitySeries::class, 'series_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Activities in a month: dated ones overlapping it, and month-only
     * ones planned for it.
     */
    public function scopeInMonth(Builder $query, int $year, int $month): Builder
    {
        $first = Carbon::create($year, $month, 1)->startOfDay();
        $last = $first->copy()->endOfMonth();

        return $query->where(fn (Builder $query) => $query
            ->where(fn (Builder $query) => $query->between($first, $last))
            ->orWhere(fn (Builder $query) => $query
                ->whereNull('starts_on')
                ->where('year', $year)
                ->where('month', $month)));
    }

    /**
     * Dated activities overlapping the range (inclusive). A row without an
     * end date counts as a single day.
     */
    public function scopeBetween(Builder $query, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $query
            ->whereNotNull('starts_on')
            ->whereDate('starts_on', '<=', $to->toDateString())
            ->where(fn (Builder $query) => $query
                ->whereDate('ends_on', '>=', $from->toDateString())
                ->orWhere(fn (Builder $query) => $query
                    ->whereNull('ends_on')
                    ->whereDate('starts_on', '>=', $from->toDateString())));
    }

    public function scopeNotCancelled(Builder $query): Builder
    {
        return $query->where('status', '!=', ActivityStatus::Cancelled->value);
    }

    /**
     * Whether its date has ever been changed (it has move records).
     */
    protected function hasMoved(): Attribute
    {
        return Attribute::get(fn (): bool => $this->relationLoaded('moves')
            ? $this->moves->isNotEmpty()
            : $this->moves()->exists());
    }
}
