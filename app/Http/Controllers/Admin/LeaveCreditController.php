<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Support\HR\Leave\LeaveCredits;
use App\Support\HR\Leave\Supervisors;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-6a: everyone's leave credits (legacy hrd/leave-cards.php and
 * hrd/accrual.php) — balances per type, and the monthly accrual and
 * year-start runs (also scheduled; re-running is safe).
 */
class LeaveCreditController extends Controller
{
    public function index(Request $request, LeaveCredits $credits, Supervisors $supervisors): Response
    {
        $this->authorize('viewAny', LeaveType::class);

        $types = $credits->creditTypes();
        $employees = Employee::query()->with(['user.organizationalUnit', 'immediateSupervisor:id,name,position'])->get()
            ->sortBy(fn (Employee $employee) => $employee->user->name, SORT_NATURAL | SORT_FLAG_CASE)->values();

        $lastMonth = today()->subMonthNoOverflow();

        return Inertia::render('Admin/HR/Leave/Credits', [
            'types' => $types->map(fn (LeaveType $type) => ['id' => $type->id, 'code' => $type->code, 'name' => $type->name])->values(),
            'employees' => $employees->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'name' => $employee->user->name,
                'office' => $employee->user->organizationalUnit?->short_name ?: $employee->user->organizationalUnit?->name,
                'status' => $employee->employment_status?->label() ?? 'Not Set',
                'separated' => $employee->isSeparated(),
                'supervisor' => ($supervisor = $supervisors->for($employee))['name'],
                'supervisor_position' => $supervisor['position'],
                'supervisor_source' => $supervisor['source'],
                'balances' => $credits->balances($employee)->pluck('balance', 'type_id'),
            ]),
            'defaults' => ['year' => $lastMonth->year, 'month' => $lastMonth->month, 'start_year' => (int) today()->year],
            'canManage' => $request->user()->can('manage', LeaveType::class),
        ]);
    }

    public function accrue(Request $request, LeaveCredits $credits): RedirectResponse
    {
        $this->authorize('manage', LeaveType::class);

        $data = $request->validate(['year' => ['required', 'integer', 'between:2000,2100'], 'month' => ['required', 'integer', 'between:1,12']]);
        $period = Carbon::create($data['year'], $data['month'], 1);

        if ($period->gt(today()->startOfMonth())) {
            return back()->with('error', 'You can\'t accrue a month that hasn\'t started.');
        }

        $result = $credits->accrueMonth((int) $data['year'], (int) $data['month'], $request->user());
        $this->audit()->record(AuditEvent::LeaveAccrualRun, 'Ran leave accrual for '.$period->format('F Y'), null, $data + $result);

        return back()->with('status', "Accrual for {$period->format('F Y')}: {$result['posted']} credited, {$result['already']} already done, {$result['not_eligible']} not eligible.");
    }

    public function startYear(Request $request, LeaveCredits $credits): RedirectResponse
    {
        $this->authorize('manage', LeaveType::class);

        $data = $request->validate(['year' => ['required', 'integer', 'between:2000,'.(today()->year)]]);
        $result = $credits->startYear((int) $data['year'], $request->user());
        $this->audit()->record(AuditEvent::LeaveYearStartRun, "Ran the leave year start for {$data['year']}", null, $data + $result);

        return back()->with('status', "Year {$data['year']}: {$result['granted']} grants posted, {$result['lapsed']} lapsed balances, {$result['already']} already done, {$result['not_eligible']} not eligible.");
    }
}
