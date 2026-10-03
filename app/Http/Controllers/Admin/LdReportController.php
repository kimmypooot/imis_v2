<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\OrganizationalUnit;
use App\Models\Pds\PdsLearningDevelopment;
use App\Support\HR\LearningDevelopment\LdReports;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-3c: HRD's L&D dashboard (ldms hrd/fetch_hrd_dashboard.php) and
 * training report (ldms hrd/training_report.php). Approved trainings
 * only. Readable with `ld.view` or `ld.manage`, like the overview.
 */
class LdReportController extends Controller
{
    public function dashboard(Request $request, LdReports $reports): Response
    {
        $this->authorize('viewAny', PdsLearningDevelopment::class);

        $year = $this->year($request);

        return Inertia::render('Admin/HR/LearningDevelopment/Dashboard', [
            'year' => $year,
            'years' => $reports->years($year),
            ...$reports->dashboard($year),
        ]);
    }

    public function report(Request $request, LdReports $reports): Response
    {
        $this->authorize('viewAny', PdsLearningDevelopment::class);

        $filters = $request->validate([
            'year' => ['nullable', 'integer', 'between:1900,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'office' => ['nullable', 'integer', 'exists:organizational_units,id'],
        ]);

        $year = (int) ($filters['year'] ?? now()->year);
        $month = isset($filters['month']) ? (int) $filters['month'] : null;
        $office = isset($filters['office']) ? (int) $filters['office'] : null;

        $rows = $reports->report($year, $month, $office);

        // The report lists everyone's names, sex and positions, so opening
        // it is part of the personal-data access trail.
        $this->audit()->record(AuditEvent::LdReportViewed, "Viewed the L&D training report for {$this->period($year, $month)}", null, array_filter([
            'year' => $year,
            'month' => $month,
            'office' => $office ? OrganizationalUnit::query()->whereKey($office)->value('name') : null,
            'rows' => $rows->count(),
        ], fn ($value) => $value !== null));

        return Inertia::render('Admin/HR/LearningDevelopment/Report', [
            'filters' => ['year' => $year, 'month' => $month, 'office' => $office],
            'period' => $this->period($year, $month),
            'years' => $reports->years($year),
            'officeOptions' => $reports->officeOptions(),
            'rows' => $rows,
        ]);
    }

    private function year(Request $request): int
    {
        $year = $request->integer('year', (int) now()->year);

        return $year >= 1900 && $year <= 2100 ? $year : (int) now()->year;
    }

    /** "March 2026", or "All months 2026" — the report's title, as in ldms. */
    private function period(int $year, ?int $month): string
    {
        return ($month ? now()->setDate($year, $month, 1)->format('F') : 'All months').' '.$year;
    }
}
