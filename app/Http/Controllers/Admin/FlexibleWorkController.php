<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\FwaArrangement;
use App\Models\FwaReport;
use App\Models\LeaveType;
use App\Notifications\HrUpdate;
use App\Support\HR\Leave\FlexibleWork;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-6c: flexible work for HR (legacy hrd/fwa.php) — requests to decide,
 * arrangements in effect (end one early), and weekly reports to
 * acknowledge. Never on their own.
 */
class FlexibleWorkController extends Controller
{
    public function index(Request $request, FlexibleWork $work): Response
    {
        $this->authorize('viewAny', LeaveType::class);
        $viewer = $request->user();

        $present = fn (FwaArrangement $arrangement) => [...$work->presentArrangement($arrangement), 'own' => $arrangement->employee->user_id === $viewer->id];

        return Inertia::render('Admin/HR/Leave/FlexibleWork', [
            'pending' => FwaArrangement::query()->where('status', 'pending')->with('employee.user')->oldest()->get()->map($present),
            'active' => FwaArrangement::query()->inEffect(today())->with('employee.user')->get()->map($present),
            'reports' => FwaReport::query()->where('status', 'submitted')->with('employee.user')->oldest('week_start')->get()
                ->map(fn (FwaReport $report) => [...$work->presentReport($report), 'own' => $report->employee->user_id === $viewer->id]),
            'canManage' => $viewer->can('manage', LeaveType::class),
        ]);
    }

    public function decide(Request $request, FwaArrangement $arrangement, FlexibleWork $work): RedirectResponse
    {
        $this->authorize('manage', LeaveType::class);
        abort_if($arrangement->employee->user_id === $request->user()->id, 403);

        $data = $request->validate(['decision' => ['required', 'in:approved,rejected'], 'remarks' => ['nullable', 'required_if:decision,rejected', 'string', 'max:1000']]);

        $data['decision'] === 'approved'
            ? $work->approve($arrangement, $request->user(), $data['remarks'] ?? null)
            : $work->reject($arrangement, $request->user(), $data['remarks']);

        $this->audit()->record(AuditEvent::FwaDecided, ucfirst($data['decision'])." the {$arrangement->label}", $arrangement, ['decision' => $data['decision']]);
        $arrangement->employee->user->notify(new HrUpdate(
            "Flexible work arrangement {$data['decision']}",
            "Your {$arrangement->typeLabel()} request was {$data['decision']}.".(! empty($data['remarks']) ? " Remarks: {$data['remarks']}" : ''),
            '/my/flexible-work',
        ));

        return back()->with('status', "Arrangement {$data['decision']}.");
    }

    public function terminate(Request $request, FwaArrangement $arrangement, FlexibleWork $work): RedirectResponse
    {
        $this->authorize('manage', LeaveType::class);
        abort_if($arrangement->employee->user_id === $request->user()->id, 403);

        $data = $request->validate(['effective_to' => ['required', 'date'], 'remarks' => ['nullable', 'string', 'max:1000']]);
        $work->terminate($arrangement, Carbon::parse($data['effective_to']), $data['remarks'] ?? null);

        $this->audit()->record(AuditEvent::FwaTerminated, "Ended the {$arrangement->label}", $arrangement, ['last_day' => $data['effective_to']]);
        $arrangement->employee->user->notify(new HrUpdate('Flexible work arrangement ended', "Your {$arrangement->typeLabel()} ends on {$data['effective_to']}.", '/my/flexible-work'));

        return back()->with('status', 'Arrangement ended.');
    }

    public function acknowledge(Request $request, FwaReport $report, FlexibleWork $work): RedirectResponse
    {
        $this->authorize('manage', LeaveType::class);
        abort_if($report->employee->user_id === $request->user()->id, 403);

        $data = $request->validate(['remarks' => ['nullable', 'string', 'max:1000']]);
        $work->acknowledge($report, $request->user(), $data['remarks'] ?? null);
        $this->audit()->record(AuditEvent::FwaReportAcknowledged, "Acknowledged the {$report->label}", $report);

        return back()->with('status', 'Report acknowledged.');
    }
}
