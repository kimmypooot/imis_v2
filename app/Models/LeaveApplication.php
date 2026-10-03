<?php

namespace App\Models;

use App\Enums\LeaveApplicationStatus;
use App\Enums\LeaveDetail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HR-6b: a leave application (CS Form No. 6). Written only by
 * App\Support\HR\Leave\LeaveApplications; its approval chain is in
 * `steps` (one round per submission).
 */
class LeaveApplication extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date_from' => 'date:Y-m-d',
            'date_to' => 'date:Y-m-d',
            'is_half_day' => 'boolean',
            'days' => 'decimal:3',
            'days_with_pay' => 'decimal:3',
            'days_without_pay' => 'decimal:3',
            'detail' => LeaveDetail::class,
            'commutation_requested' => 'boolean',
            'status' => LeaveApplicationStatus::class,
            'current_step' => 'integer',
            'submitted_at' => 'datetime',
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

    protected function label(): Attribute
    {
        return Attribute::get(fn () => 'leave application '.($this->reference_number ?? "#{$this->id}").' of '.$this->loadMissing('employee.user')->employee?->user?->name);
    }
}
