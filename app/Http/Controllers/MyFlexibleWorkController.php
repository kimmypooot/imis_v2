<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Models\Employee;
use App\Models\FwaArrangement;
use App\Models\FwaReport;
use App\Support\HR\Leave\FlexibleWork;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-6c self-service: your flexible work arrangements (legacy leave/fwa.php)
 * and weekly field activity reports.
 */
class MyFlexibleWorkController extends Controller
{
    public function show(Request $request, FlexibleWork $work): Response
    {
        $employee = $this->employee($request);
        $current = FwaArrangement::query()->where('employee_id', $employee->id)->inEffect(today())->latest('effective_from')->first();

        return Inertia::render('Portal/MyFlexibleWork', [
            'types' => collect(FwaArrangement::TYPES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'days' => FwaArrangement::DAYS,
            'current' => $current ? $work->presentArrangement($current) : null,
            'arrangements' => FwaArrangement::query()->where('employee_id', $employee->id)->latest('effective_from')->get()
                ->map(fn (FwaArrangement $arrangement) => $work->presentArrangement($arrangement)),
            'reports' => FwaReport::query()->where('employee_id', $employee->id)->latest('week_start')->limit(26)->get()
                ->map(fn (FwaReport $report) => $work->presentReport($report)),
            'thisWeek' => today()->startOfWeek()->toDateString(),
        ]);
    }

    public function store(Request $request, FlexibleWork $work): RedirectResponse
    {
        $arrangement = $work->apply($this->employee($request), $request->validate($work->arrangementRules(), [], ['effective_from' => 'start', 'effective_to' => 'end']));
        $this->audit()->record(AuditEvent::FwaRequested, "Requested the {$arrangement->label}", $arrangement, ['type' => $arrangement->type]);

        return back()->with('status', 'Your request was sent to HR.');
    }

    public function cancel(Request $request, FwaArrangement $arrangement, FlexibleWork $work): RedirectResponse
    {
        abort_unless($arrangement->employee_id === $this->employee($request)->id, 403);

        $work->cancel($arrangement);
        $this->audit()->record(AuditEvent::FwaCancelled, "Cancelled the {$arrangement->label}", $arrangement);

        return back()->with('status', 'Request cancelled.');
    }

    public function report(Request $request, FlexibleWork $work): RedirectResponse
    {
        $report = $work->submitReport($this->employee($request), $request->validate($work->reportRules(), [], ['week_start' => 'week']));
        $this->audit()->record(AuditEvent::FwaReportSubmitted, "Submitted the {$report->label}", $report);

        return back()->with('status', 'Weekly report submitted.');
    }

    private function employee(Request $request): Employee
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 403, 'HR hasn\'t set up your employee record yet.');

        return $employee;
    }
}
