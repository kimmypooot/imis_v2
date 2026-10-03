<?php

namespace App\Models;

use App\Enums\AssignmentKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * HR-1b: one movement in an employee's assignment history. Written only
 * by App\Support\HR\EmployeeAssignments. Removing one soft-deletes it.
 */
class EmployeeAssignment extends Model
{
    use SoftDeletes;

    protected $fillable = ['kind', 'from_unit_id', 'to_unit_id', 'effective_date', 'end_date', 'order_number', 'order_date', 'remarks'];

    protected function casts(): array
    {
        return [
            'kind' => AssignmentKind::class,
            'effective_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'order_date' => 'date:Y-m-d',
            'applied_at' => 'datetime',
        ];
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
        return Attribute::get(fn () => $this->kind->label().' of '.$this->loadMissing('employee.user')->employee?->user?->name);
    }
}
