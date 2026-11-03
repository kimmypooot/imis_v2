<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AssignmentKind;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\OrganizationalUnit;
use App\Support\HR\EmployeeAssignments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-1b reports: who was assigned where on a given date, and the
 * movements in a period. Limited to the records the viewer may see
 * (Employee::scopeVisibleTo): everyone for HR, their office for
 * supervisors.
 */
class ReassignmentReportController extends Controller
{
    public function index(Request $request, EmployeeAssignments $assignments): Response
    {
        $this->authorize('viewAny', Employee::class);

        $filters = $request->validate([
            'as_of' => ['nullable', 'date'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'kind' => ['nullable', Rule::enum(AssignmentKind::class)],
            'office' => ['nullable', 'integer', 'exists:organizational_units,id'],
        ]);

        $asOf = Carbon::parse($filters['as_of'] ?? today())->startOfDay();
        $from = Carbon::parse($filters['from'] ?? today()->startOfYear())->startOfDay();
        $to = Carbon::parse($filters['to'] ?? today()->endOfYear())->startOfDay();
        $office = isset($filters['office']) ? (int) $filters['office'] : null;

        $employees = Employee::query()->visibleTo($request->user())->with('user.organizationalUnit')->get();

        $stations = $assignments->stationsOn($employees, $asOf)
            ->reject(fn (array $row) => $row['separated'])
            ->when($office, fn ($rows) => $rows->where('office_id', $office))
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $movements = EmployeeAssignment::query()
            ->whereIn('employee_id', $employees->modelKeys())
            ->whereDate('effective_date', '>=', $from)
            ->whereDate('effective_date', '<=', $to)
            ->when($filters['kind'] ?? null, fn (Builder $query, string $kind) => $query->where('kind', $kind))
            ->when($office, fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('from_unit_id', $office)->orWhere('to_unit_id', $office)))
            ->with(['employee.user', 'fromUnit', 'toUnit', 'document', 'recorder:id,name'])
            ->orderByDesc('effective_date')->orderByDesc('id')
            ->get()
            ->map(fn (EmployeeAssignment $entry) => [
                ...$assignments->present($entry, $request->user()),
                'employee_id' => $entry->employee_id,
                'name' => $entry->employee->user->name,
            ]);

        return Inertia::render('Admin/HR/Reassignments/Index', [
            'filters' => [
                'as_of' => $asOf->toDateString(),
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'kind' => $filters['kind'] ?? null,
                'office' => $office,
            ],
            'stations' => $stations,
            'movements' => $movements,
            'kindOptions' => AssignmentKind::options(),
            'officeOptions' => OrganizationalUnit::query()->orderBy('name')->get()
                ->map(fn (OrganizationalUnit $unit) => ['value' => $unit->id, 'label' => $unit->name]),
        ]);
    }
}
