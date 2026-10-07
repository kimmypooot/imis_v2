<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EmploymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\NonPlantillaContract;
use App\Models\OrganizationalUnit;
use App\Models\PlantillaItem;
use App\Support\HR\NonPlantilla\NonPlantilla;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Human Resources → Non-Plantilla: casual, contractual, Job Order and
 * Contract of Service staff (who hold no plantilla item) and their
 * contract periods. Same access as the plantilla; nobody records their
 * own contracts.
 */
class NonPlantillaController extends Controller
{
    public function index(Request $request, NonPlantilla $nonPlantilla): Response
    {
        $this->authorize('viewAny', PlantillaItem::class);

        $canManage = $request->user()->can('manage', PlantillaItem::class);
        $rows = $nonPlantilla->rows()->map(fn (array $row) => [
            ...$row,
            'can_manage' => $canManage && $request->user()->can('appoint', [PlantillaItem::class, Employee::query()->find($row['employee_id'])]),
        ]);

        return Inertia::render('Admin/HR/NonPlantilla/Index', [
            'rows' => $rows,
            'statusOptions' => EmploymentStatus::nonPlantillaOptions(),
            'expiringDays' => NonPlantilla::EXPIRING_DAYS,
            'canManage' => $canManage,
            'contractForm' => $canManage ? [
                'employeeOptions' => $nonPlantilla->candidates(),
                'officeOptions' => OrganizationalUnit::query()->active()->orderBy('name')->get()
                    ->map(fn (OrganizationalUnit $unit) => ['value' => $unit->id, 'label' => $unit->name]),
            ] : null,
        ]);
    }

    public function store(Request $request, NonPlantilla $nonPlantilla): RedirectResponse
    {
        $data = $request->validate($nonPlantilla->rules(choosingEmployee: true));
        $employee = Employee::query()->with('user')->findOrFail($data['employee_id']);
        $this->authorize('appoint', [PlantillaItem::class, $employee]);

        $nonPlantilla->record($employee, collect($data)->except('employee_id')->all());

        return back()->with('status', "Contract of {$employee->user->name} recorded.");
    }

    public function update(Request $request, NonPlantillaContract $contract, NonPlantilla $nonPlantilla): RedirectResponse
    {
        $this->authorize('appoint', [PlantillaItem::class, $contract->employee]);

        $nonPlantilla->update($contract, $request->validate($nonPlantilla->rules(choosingEmployee: false)));

        return back()->with('status', 'Contract saved.');
    }

    public function destroy(NonPlantillaContract $contract, NonPlantilla $nonPlantilla): RedirectResponse
    {
        $this->authorize('appoint', [PlantillaItem::class, $contract->employee]);

        $nonPlantilla->remove($contract);

        return back()->with('status', 'Contract removed.');
    }
}
