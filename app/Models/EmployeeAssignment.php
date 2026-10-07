<?php

namespace App\Models;

use App\Enums\AssignmentKind;
use App\Enums\HeadCapacity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * HR-1b: one movement in an employee's assignment history. Written only
 * by App\Support\HR\EmployeeAssignments. Removing one soft-deletes it.
 *
 * A designation `as_head` makes the person head of the office it names, as
 * Officer-in-Charge or Caretaker (`head_capacity`), in a concurrent
 * capacity (they stay in their mother unit): it widens their
 * office-scoped access while it runs (`officesHeadedBy()`).
 */
class EmployeeAssignment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'kind', 'as_head', 'head_capacity', 'from_unit_id', 'to_unit_id', 'effective_date', 'end_date', 'order_number', 'order_date', 'remarks',
        'hand_over_supervision', 'restore_supervision',
    ];

    protected function casts(): array
    {
        return [
            'kind' => AssignmentKind::class,
            'as_head' => 'boolean',
            'head_capacity' => HeadCapacity::class,
            'effective_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'order_date' => 'date:Y-m-d',
            'applied_at' => 'datetime',
            'hand_over_supervision' => 'boolean',
            'supervision_handed_at' => 'datetime',
            'restore_supervision' => 'boolean',
            'supervision_restored_at' => 'datetime',
        ];
    }

    public function handovers(): HasMany
    {
        return $this->hasMany(SupervisorHandover::class, 'assignment_id');
    }

    /**
     * Head-of-office designations running on a date (today by default).
     *
     * @param  Builder<self>  $query
     */
    public function scopeHeadingOn(Builder $query, ?Carbon $date = null): void
    {
        $date ??= today();

        $query->where('kind', AssignmentKind::Designation)->where('as_head', true)
            ->whereDate('effective_date', '<=', $date)
            ->where(fn (Builder $query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $date));
    }

    /**
     * The offices someone heads today by designation (not their own office).
     *
     * @return array<int, int>
     */
    public static function officesHeadedBy(User $user): array
    {
        return self::query()->headingOn()
            ->whereHas('employee', fn (Builder $query) => $query->where('user_id', $user->id))
            ->pluck('to_unit_id')->unique()->values()->all();
    }

    /** How it heads the office; OIC unless the order says Caretaker. */
    public function capacity(): HeadCapacity
    {
        return $this->head_capacity ?? HeadCapacity::OfficerInCharge;
    }

    /** Whether it is a running head-of-office designation today. */
    public function isHeadingNow(): bool
    {
        return $this->kind === AssignmentKind::Designation && $this->as_head && ! $this->trashed()
            && $this->effective_date->lte(today()) && ($this->end_date === null || $this->end_date->gte(today()));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function fromUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'from_unit_id');
    }

    public function toUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'to_unit_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeReassignments(Builder $query): void
    {
        $query->where('kind', AssignmentKind::Reassignment);
    }

    /**
     * How audit entries name it ("Reassignment of Juan D. Cruz").
     */
    protected function label(): Attribute
    {
        return Attribute::get(fn () => $this->kind->label().($this->as_head ? ' as '.$this->capacity()->label() : '').' of '.$this->loadMissing('employee.user')->employee?->user?->name);
    }
}
