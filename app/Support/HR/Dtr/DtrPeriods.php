<?php

namespace App\Support\HR\Dtr;

use App\Enums\AssignmentKind;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * DTR: splits a period wherever the employee was reassigned to another
 * office, so each office's head can verify its own sheet. Only
 * reassignments move the office (details and designations leave the person
 * in their mother unit).
 */
class DtrPeriods
{
    /**
     * @return Collection<int, array{from: Carbon, to: Carbon, unit_id: ?int}>
     */
    public function for(Employee $employee, Carbon $from, Carbon $to): Collection
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();

        $reassignments = EmployeeAssignment::query()
            ->where('employee_id', $employee->id)
            ->where('kind', AssignmentKind::Reassignment)
            ->orderBy('effective_date')->orderBy('id')
            ->get();

        $before = $reassignments->filter(fn (EmployeeAssignment $move) => $move->effective_date->lte($from))->last();
        $within = $reassignments->filter(fn (EmployeeAssignment $move) => $move->effective_date->gt($from) && $move->effective_date->lte($to))->values();

        $unit = $before?->to_unit_id
            ?? $reassignments->first(fn (EmployeeAssignment $move) => $move->effective_date->gt($from))?->from_unit_id
            ?? $employee->user->organizational_unit_id;

        $segments = collect();
        $start = $from;

        foreach ($within as $move) {
            $segments->push(['from' => $start, 'to' => $move->effective_date->copy()->subDay(), 'unit_id' => $unit]);
            $start = $move->effective_date->copy();
            $unit = $move->to_unit_id;
        }

        return $segments->push(['from' => $start, 'to' => $to, 'unit_id' => $unit]);
    }
}
