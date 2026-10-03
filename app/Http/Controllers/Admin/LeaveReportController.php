<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeaveType;
use App\Support\HR\Leave\LeaveReports;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-6c: leave reports (legacy hrd/reports.php) — one at a time, for a
 * year (or a day, for who's on leave). Print and CSV from the table.
 */
class LeaveReportController extends Controller
{
    public function index(Request $request, LeaveReports $reports): Response
    {
        $this->authorize('viewAny', LeaveType::class);

        $filters = $request->validate([
            'report' => ['nullable', Rule::in(array_keys(LeaveReports::REPORTS))],
            'year' => ['nullable', 'integer', 'between:1990,2100'],
            'day' => ['nullable', 'date'],
        ]);
        $report = $filters['report'] ?? 'on_leave';
        $year = (int) ($filters['year'] ?? today()->year);
        $day = Carbon::parse($filters['day'] ?? today());

        return Inertia::render('Admin/HR/Leave/Reports', [
            'reports' => collect(LeaveReports::REPORTS)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
            'filters' => ['report' => $report, 'year' => $year, 'day' => $day->toDateString()],
            'rows' => match ($report) {
                'on_leave' => $reports->onLeave($day),
                'utilization' => $reports->utilization($year),
                'forced_leave' => $reports->forcedLeave($year),
                'without_pay' => $reports->withoutPay($year),
                'adjustments' => $reports->adjustments($year),
            },
        ]);
    }
}
