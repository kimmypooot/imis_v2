<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One staff member whose immediate supervisor became the designee of a
 * head-of-office designation, and who their supervisor was before (to give
 * back when it ends). Written only by App\Support\HR\EmployeeAssignments.
 */
class SupervisorHandover extends Model
{
    protected $fillable = ['assignment_id', 'employee_id', 'previous_supervisor_id', 'restored_at'];

    protected function casts(): array
    {
        return ['restored_at' => 'datetime'];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeAssignment::class, 'assignment_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
