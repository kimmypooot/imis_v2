<?php

namespace App\Http\Controllers;

use App\Enums\ActivityMode;
use App\Models\Activity;
use App\Models\OrganizationalUnit;
use App\Support\Activities\ActivityWorkbook;
use App\Support\Activities\MonthSheet;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Calendar of Activities: the monthly sheet in the reference layout,
 * printed from the browser or downloaded as Excel (a month, or a whole
 * year with a tab per month), optionally for one office and one mode. The layout is
 * built by MonthSheet and ActivityWorkbook.
 */
class ActivitySheetController extends Controller
{
    public function print(Request $request): Response
    {
        $this->authorize('viewAny', Activity::class);

        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'office' => ['nullable', 'integer', Rule::exists('organizational_units', 'id')],
            'mode' => ['nullable', Rule::enum(ActivityMode::class)],
        ]);
        $month = $this->month($filters['month'] ?? null);
        $officeId = isset($filters['office']) ? (int) $filters['office'] : null;
        $mode = isset($filters['mode']) ? ActivityMode::from($filters['mode']) : null;

        return Inertia::render('Activities/SheetPrint', [
            'sheet' => MonthSheet::build($month->year, $month->month, $officeId, $mode),
            'month' => $month->format('Y-m'),
            'office' => $officeId === null ? null : OrganizationalUnit::query()->whereKey($officeId)->value('name'),
        ]);
    }

    public function excel(Request $request, ActivityWorkbook $workbook): HttpResponse
    {
        $this->authorize('viewAny', Activity::class);

        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'office' => ['nullable', 'integer', Rule::exists('organizational_units', 'id')],
            'mode' => ['nullable', Rule::enum(ActivityMode::class)],
        ]);
        $officeId = isset($filters['office']) ? (int) $filters['office'] : null;
        $mode = isset($filters['mode']) ? ActivityMode::from($filters['mode']) : null;

        if (isset($filters['year']) && ! isset($filters['month'])) {
            $year = (int) $filters['year'];
            $sheets = array_map(fn (int $month) => MonthSheet::build($year, $month, $officeId, $mode), range(1, 12));
            $name = (string) $year;
        } else {
            $month = $this->month($filters['month'] ?? null);
            $sheets = [MonthSheet::build($month->year, $month->month, $officeId, $mode)];
            $name = $month->format('F Y');
        }

        return response($workbook->build($sheets), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="CSC RO VIII Calendar of Activities - '.$name.'.xlsx"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The first of the asked month (`Y-m`), or of the current month.
     */
    private function month(?string $value): Carbon
    {
        return $value === null
            ? today()->startOfMonth()
            : Carbon::createFromFormat('!Y-m', $value)->startOfMonth();
    }
}
