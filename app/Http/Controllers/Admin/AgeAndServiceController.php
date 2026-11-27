<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Support\HR\AgeAndService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Human Resources → Age & Service: everyone in service with their age,
 * length of service, next birthday, retirement dates and next loyalty
 * award. HR and top management only (`employees.view`), like the
 * dashboard's birthday and retirement lists it expands on.
 */
class AgeAndServiceController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Employee::class);

        $filters = $request->validate([
            'view' => ['nullable', Rule::in(AgeAndService::VIEWS)],
            'office' => ['nullable', 'integer', 'exists:organizational_units,id'],
        ]);

        $view = $filters['view'] ?? 'all';
        $office = isset($filters['office']) ? (int) $filters['office'] : null;

        $staff = Employee::query()->visibleTo($request->user())->with('user.organizationalUnit')->get()
            ->reject(fn (Employee $employee) => $employee->isSeparated() || ! $employee->user->isActive())
            ->values();

        $roster = new AgeAndService;
        $rows = $roster->rows($staff)->when($office, fn ($rows) => $rows->where('office_id', $office));

        // Everyone's birthday and age: audited like the HR dashboard, once a day per viewer.
        if (Cache::add("age-and-service-viewed:{$request->user()->id}:".today()->toDateString(), true, now()->endOfDay())) {
            $this->audit()->record(AuditEvent::AgeAndServiceViewed, 'Viewed Age & Service');
        }

        return Inertia::render('Admin/HR/AgeAndService/Index', [
            'filters' => ['view' => $view, 'office' => $office],
            'asOf' => today()->toDateString(),
            'people' => $roster->view($rows, $view),
            'officeOptions' => OrganizationalUnit::query()->workingOffices()->inOrder()->get()
                ->map(fn (OrganizationalUnit $unit) => ['value' => $unit->id, 'label' => $unit->name]),
        ]);
    }
}
