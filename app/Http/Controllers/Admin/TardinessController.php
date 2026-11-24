<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DtrLeaveDeduction;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Support\HR\Dtr\DtrEntryVerification;
use App\Support\HR\Dtr\DtrSheets;
use App\Support\HR\Dtr\TardinessConversion;
use App\Support\HR\Dtr\TardinessDeductions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * DTR: HR reviews each month's tardiness and undertime and charges it to
 * leave credits (CSC Table IV; Vacation Leave, or Sick Leave only for
 * health reasons with a medical certificate). Reading needs `leave.view`
 * or `leave.manage`; posting and reversing need `leave.manage`, never on
 * your own leave.
 */
class TardinessController extends Controller
{
    public function index(Request $request, TardinessDeductions $deductions, TardinessConversion $conversion): Response
    {
        $this->authorize('viewAny', LeaveType::class);

        $month = $request->query('month') ? DtrSheets::parseMonth($request->query('month')) : now()->subMonthNoOverflow()->startOfMonth();

        return Inertia::render('Admin/HR/Leave/Tardiness', [
            'month' => $month->format('Y-m'),
            'label' => $month->format('F Y'),
            'finished' => $month->copy()->endOfMonth()->startOfDay()->lt(today()),
            'rows' => $deductions->forMonth($month)->all(),
            // CSC Table IV, shown on the page for HR's reference.
            'conversionTable' => $conversion->table(),
            'canManage' => $request->user()->can('manage', LeaveType::class),
        ]);
    }

    public function store(Request $request, TardinessDeductions $deductions): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'month' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'charge' => ['required', 'string', 'in:VL,SL'],
            'health_certified' => ['nullable', 'boolean'],
            'post_anyway' => ['nullable', 'boolean'],
        ]);

        $employee = Employee::query()->with('user')->findOrFail($data['employee_id']);
        $this->authorize('manageLeave', $employee);

        $deduction = $deductions->post($employee, Carbon::createFromFormat('!Y-m', $data['month']), $data['charge'], (bool) ($data['health_certified'] ?? false), $request->user(), (bool) ($data['post_anyway'] ?? false));

        return back()->with('status', sprintf('Charged %s day to %s for %s.', number_format((float) $deduction->days, 3), $data['charge'], $employee->user->name));
    }

    /** Charges every row that is ready: verified, nothing unusual, Vacation Leave. */
    public function storeReviewed(Request $request, TardinessDeductions $deductions): RedirectResponse
    {
        abort_unless($request->user()->can('manage', LeaveType::class), 403);

        $data = $request->validate(['month' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        $month = Carbon::createFromFormat('!Y-m', $data['month']);

        $result = $deductions->postReviewed($month, $request->user(), fn (Employee $employee) => $request->user()->can('manageLeave', $employee));

        return back()->with('status', $result['posted']
            ? sprintf('Charged %d %s to Vacation Leave. %d left for you to review.', $result['posted'], $result['posted'] === 1 ? 'employee' : 'employees', $result['left'])
            : 'Nothing was ready to charge. The rest need a decision.');
    }

    /** Syncs the leave cards with the month's DTR: charges what is ready and re-charges what the DTR changed. */
    public function sync(Request $request, TardinessDeductions $deductions): RedirectResponse
    {
        abort_unless($request->user()->can('manage', LeaveType::class), 403);

        $data = $request->validate(['month' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        $month = Carbon::createFromFormat('!Y-m', $data['month']);

        if (! $month->copy()->endOfMonth()->startOfDay()->lt(today())) {
            return back()->withErrors(['month' => 'A month can be synced once it has ended.']);
        }

        $result = $deductions->sync($month, $request->user(), fn (Employee $employee) => $request->user()->can('manageLeave', $employee));

        if (! $result['posted'] && ! $result['updated']) {
            return back()->with('status', $result['left']
                ? "Nothing could be synced. {$result['left']} still need a decision."
                : 'Already in sync with the DTR.');
        }

        return back()->with('status', sprintf(
            'Synced %s: %d newly charged, %d re-charged after DTR changes, %d left for you to review.',
            $month->format('F Y'), $result['posted'], $result['updated'], $result['left'],
        ));
    }

    /** HR's fallback: verify the days an employee entered in the month, when their supervisor can't. */
    public function verify(Request $request, DtrEntryVerification $verification): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'month' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        $employee = Employee::query()->with('user')->findOrFail($data['employee_id']);
        $this->authorize('manageLeave', $employee);

        $count = $verification->verifyMonth($employee, Carbon::createFromFormat('!Y-m', $data['month']), $request->user());

        return back()->with('status', "Verified {$count} ".($count === 1 ? 'day' : 'days')." for {$employee->user->name}.");
    }

    public function reverse(Request $request, DtrLeaveDeduction $deduction, TardinessDeductions $deductions): RedirectResponse
    {
        $this->authorize('manageLeave', $deduction->employee);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $deductions->reverse($deduction, $data['reason'], $request->user());

        return back()->with('status', 'The charge was reversed and the credits restored.');
    }
}
