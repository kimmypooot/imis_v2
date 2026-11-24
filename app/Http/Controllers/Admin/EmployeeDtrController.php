<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\DtrAdjustment;
use App\Models\Employee;
use App\Support\HR\Dtr\DtrAdjustments;
use App\Support\HR\Dtr\DtrDownload;
use App\Support\HR\Dtr\DtrSheets;
use App\Support\HR\Dtr\DtrWorkbook;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * DTR for HRD: someone's monthly Daily Time Record (`dtr.view` or
 * `dtr.manage`), one sheet per office served that month, and HRD's
 * adjustments (`dtr.manage`, never on their own record). Opening or
 * printing anyone else's is audited.
 */
class EmployeeDtrController extends Controller
{
    public function show(Request $request, Employee $employee, DtrSheets $sheets): Response
    {
        $this->authorize('viewDtr', $employee);
        $month = DtrSheets::parseMonth($request->query('month'));
        $this->trail($request, $employee, AuditEvent::DtrViewed, 'Opened', ['month' => $month->format('Y-m')]);

        return Inertia::render('Admin/HR/Employees/Dtr', [
            ...$sheets->forMonth($employee, $month),
            'canManage' => $request->user()->can('manageDtr', $employee),
            'printBase' => url("/admin/hr/employees/{$employee->id}/dtr/print"),
        ]);
    }

    public function print(Request $request, Employee $employee, DtrSheets $sheets): Response
    {
        $this->authorize('viewDtr', $employee);
        $range = $request->validate(DtrSheets::rangeRules($request->query('from')));
        $layout = $request->query('layout') === 'double' ? 'double' : 'single';
        $this->trail($request, $employee, AuditEvent::DtrPrinted, 'Printed', ['from' => $range['from'], 'to' => $range['to'], 'layout' => $layout]);

        return Inertia::render('HR/DtrPrint', [
            ...$sheets->header($employee),
            'sheet' => $sheets->forRange($employee, Carbon::parse($range['from']), Carbon::parse($range['to'])),
            'generated_at' => now()->format('Y-m-d H:i'),
            'layout' => $layout,
        ]);
    }

    /** The same sheet as the print, as an Excel workbook (CS Form 48 layout, no signature image). */
    public function export(Request $request, Employee $employee, DtrSheets $sheets, DtrWorkbook $workbook): HttpResponse
    {
        $this->authorize('viewDtr', $employee);
        $range = $request->validate(DtrSheets::rangeRules($request->query('from')));
        $this->trail($request, $employee, AuditEvent::DtrExported, 'Exported to Excel', ['from' => $range['from'], 'to' => $range['to']]);

        $sheet = $sheets->forRange($employee, Carbon::parse($range['from']), Carbon::parse($range['to']));

        return DtrDownload::xlsx($workbook->build([['employee' => $employee->user->name, 'sheet' => $sheet]], now()->format('n/j/Y g:i:s A')), "CS Form 48 - {$employee->user->name} - {$sheet['period']}");
    }

    public function store(Request $request, Employee $employee, DtrAdjustments $adjustments): RedirectResponse
    {
        $this->authorize('manageDtr', $employee);

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'kind' => ['required', 'string', 'in:'.implode(',', DtrAdjustment::KINDS)],
            'time_in' => ['nullable', 'string', 'max:5'],
            'time_out' => ['nullable', 'string', 'max:5'],
            'remark' => ['required', 'string', 'max:255'],
        ]);

        $adjustment = $adjustments->save($employee, Carbon::parse($data['date']), $data['kind'], $data['time_in'] ?? null, $data['time_out'] ?? null, $data['remark'], $request->user());

        $this->audit()->record(AuditEvent::DtrAdjustmentSaved, "Adjusted the DTR of {$employee->user->name} for {$adjustment->date->format('M j, Y')}", $employee, [
            'date' => $adjustment->date->toDateString(),
            'kind' => $adjustment->kind,
            'time_in' => $adjustment->time_in,
            'time_out' => $adjustment->time_out,
            'remark' => $adjustment->remark,
        ]);

        return back()->with('status', "Saved the adjustment for {$adjustment->date->format('M j')}.");
    }

    public function destroy(Employee $employee, DtrAdjustment $adjustment, DtrAdjustments $adjustments): RedirectResponse
    {
        $this->authorize('manageDtr', $employee);
        abort_unless($adjustment->employee_id === $employee->id, 404);

        $adjustments->remove($adjustment);

        $this->audit()->record(AuditEvent::DtrAdjustmentRemoved, "Removed the DTR adjustment of {$employee->user->name} for {$adjustment->date->format('M j, Y')}", $employee, [
            'date' => $adjustment->date->toDateString(),
            'kind' => $adjustment->kind,
            'remark' => $adjustment->remark,
        ]);

        return back()->with('status', 'Adjustment removed.');
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function trail(Request $request, Employee $employee, AuditEvent $event, string $verb, array $metadata = []): void
    {
        if ($employee->user_id !== $request->user()->id) {
            $this->audit()->record($event, "{$verb} the DTR of {$employee->user->name}", $employee, $metadata);
        }
    }
}
