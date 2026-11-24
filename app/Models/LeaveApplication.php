<?php

namespace App\Models;

use App\Enums\LeaveApplicationStatus;
use App\Enums\LeaveDetail;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * HR-6b: a leave application (CS Form No. 6). Written only by
 * App\Support\HR\Leave\LeaveApplications; its approval chain is in
 * `steps` (one round per submission).
 *
 * The days it covers are either every day from date_from to date_to, or,
 * when `dates` is set, exactly those dates (chosen one by one; date_from
 * and date_to are then the first and last). Use coveredDates() and
 * covers() rather than the range when the exact days matter.
 */
class LeaveApplication extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date_from' => 'date:Y-m-d',
            'date_to' => 'date:Y-m-d',
            'dates' => 'array',
            'event_date' => 'date:Y-m-d',
            'charges' => 'array',
            'deadline_notices' => 'integer',
            'is_half_day' => 'boolean',
            'days' => 'decimal:3',
            'days_with_pay' => 'decimal:3',
            'days_without_pay' => 'decimal:3',
            'detail' => LeaveDetail::class,
            'commutation_requested' => 'boolean',
            'is_emergency' => 'boolean',
            'status' => LeaveApplicationStatus::class,
            'current_step' => 'integer',
            'submitted_at' => 'datetime',
            'signed_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(LeaveApplicationStep::class)->orderBy('round')->orderBy('step');
    }

    /** The current round's steps. */
    public function currentSteps(): HasMany
    {
        return $this->hasMany(LeaveApplicationStep::class)->where('round', $this->currentRound())->orderBy('step');
    }

    public function currentRound(): int
    {
        return (int) ($this->steps()->max('round') ?? 1);
    }

    /** The step waiting for action now, if any. */
    public function pendingStep(): ?LeaveApplicationStep
    {
        return $this->status === LeaveApplicationStatus::ForReview
            ? $this->currentSteps()->where('action', 'pending')->orderBy('step')->first()
            : null;
    }

    /**
     * Applications whose days still count: under review, returned, or
     * approved (for overlaps and the year's limits).
     *
     * @param  Builder<self>  $query
     */
    public function scopeHoldingDays(Builder $query): void
    {
        $query->whereIn('status', [LeaveApplicationStatus::ForReview, LeaveApplicationStatus::Returned, LeaveApplicationStatus::Approved]);
    }

    /** Whether the dates were chosen one by one (not a from–to range). */
    public function hasChosenDates(): bool
    {
        return is_array($this->dates) && $this->dates !== [];
    }

    /**
     * Every date the leave covers, as Y-m-d: the chosen dates, or each day
     * of the range (weekends and holidays included; the days counted
     * are in `days`).
     *
     * @return array<int, string>
     */
    public function coveredDates(): array
    {
        if ($this->hasChosenDates()) {
            return array_values($this->dates);
        }

        return collect(CarbonPeriod::create($this->date_from, $this->date_to))
            ->map(fn ($day) => $day->toDateString())->all();
    }

    public function covers(CarbonInterface|string $day): bool
    {
        $date = Carbon::parse($day)->toDateString();

        return $this->hasChosenDates()
            ? in_array($date, $this->dates, true)
            : $date >= $this->date_from->toDateString() && $date <= $this->date_to->toDateString();
    }

    protected function label(): Attribute
    {
        return Attribute::get(fn () => 'leave application '.($this->reference_number ?? "#{$this->id}").' of '.$this->loadMissing('employee.user')->employee?->user?->name);
    }
}
