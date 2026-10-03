<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AssignmentKind;
use App\Enums\AuditEvent;
use App\Enums\EmploymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\BulkStoreEmployeesRequest;
use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\PlantillaItem;
use App\Models\User;
use App\Support\HR\EmployeeAssignments;
use App\Support\HR\EmployeeDocuments;
use App\Support\HR\EmployeeRecords;
use App\Support\HR\Plantilla\Plantilla;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-1: employee records (Human Resources → Employees). HR sees everyone;
 * a supervisor with `employees.view_unit` sees only their office. Opening
 * someone else's record is audited (Data Privacy Act access trail).
 */
class EmployeeController extends Controller
{
    public function index(Request $request, EmployeeRecords $records): Response
    {
        $this->authorize('viewAny', Employee::class);

        $viewer = $request->user();

        $employees = Employee::query()
            ->visibleTo($viewer)
            ->with('user.organizationalUnit')
            ->get()
            ->sortBy(fn (Employee $employee) => $employee->user->name, SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return Inertia::render('Admin/HR/Employees/Index', [
            'employees' => EmployeeResource::collection($employees),
            'employmentStatusOptions' => EmploymentStatus::options(),
            // People who can still be given a record: active accounts with
            // none yet, minus the viewer themself (EmployeeRecords::candidates).
            'candidates' => fn () => $viewer->can('create', Employee::class)
                ? $records->candidates($viewer)
                    ->with('organizationalUnit')
                    ->orderBy('name')
                    ->get()
                    ->map(fn (User $user) => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'username' => $user->username,
                        'position' => $user->position,
                        'unit' => $user->organizationalUnit?->short_name ?: $user->organizationalUnit?->name,
                    ])
                : [],
        ]);
    }

    public function store(StoreEmployeeRequest $request, EmployeeRecords $records): RedirectResponse
    {
        $employee = $records->setUp(User::query()->findOrFail($request->integer('user_id')), $request->employmentData());

        return to_route('admin.hr.employees.show', $employee)->with('status', "Employee record of {$employee->user->name} was set up.");
    }

    /**
     * Sets up records for many existing accounts at once (employment
     * status "Not set" for HR to fill in), each audited like a single one.
     */
    public function storeMany(BulkStoreEmployeesRequest $request, EmployeeRecords $records): RedirectResponse
    {
        $users = User::query()->whereKey($request->validated('user_ids'))->get();

        DB::transaction(fn () => $users->each(fn (User $user) => $records->setUp($user)));

        $count = $users->count();

        return back()->with('status', "Set up {$count} employee ".($count === 1 ? 'record' : 'records').'. Fill in their employment details next.');
    }

    public function show(Request $request, Employee $employee, EmployeeAssignments $assignments): Response
    {
        $this->authorize('view', $employee);

        $employee->load('user.organizationalUnit');

        // The access trail: every time someone other than the employee
        // opens their record.
        if ($employee->user_id !== $request->user()->id) {
            $this->audit()->record(AuditEvent::EmployeeViewed, "Viewed the {$employee->label}", $employee);
        }

        $canReassign = $request->user()->can('reassign', $employee);

        return Inertia::render('Admin/HR/Employees/Show', [
            'employee' => EmployeeResource::make($employee),
            'employmentStatusOptions' => EmploymentStatus::options(),
            // HR-1b: the assignment history, and what HR needs to record one.
            'assignments' => $assignments->timeline($employee, $request->user()),
            // HR-4: the items they've held (plantilla viewers and the person
            // themselves; supervisors don't see salaries).
            'plantilla' => $request->user()->can('viewAny', PlantillaItem::class) || $employee->user_id === $request->user()->id
                ? app(Plantilla::class)->historyOf($employee)
                : null,
            'assignmentForm' => $canReassign ? [
                'kindOptions' => AssignmentKind::options(),
                'officeOptions' => OrganizationalUnit::query()->active()->orderBy('name')->get()
                    ->map(fn (OrganizationalUnit $unit) => ['value' => $unit->id, 'label' => $unit->name]),
                'scanKilobytes' => EmployeeDocuments::MAX_KILOBYTES,
            ] : null,
        ]);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): RedirectResponse
    {
        $employee->update($request->employmentData());

        $this->audit()->recordChanges(AuditEvent::EmployeeUpdated, "Updated the {$employee->label}", $employee);

        return back()->with('status', "Employee record of {$employee->user->name} was saved.");
    }
}
