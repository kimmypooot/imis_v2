<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Enums\EmploymentStatus;
use App\Enums\UnitType;
use App\Http\Controllers\Controller;
use App\Models\BiometricPunch;
use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Support\HR\Dtr\DtrDownload;
use App\Support\HR\Dtr\DtrSheets;
use App\Support\HR\Dtr\DtrWorkbook;
use App\Support\HR\Dtr\UploadMonitor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * DTR for HRD: one office's Daily Time Records for a month in one Excel
 * workbook (a CS Form 48 sheet per employee). The Regional Support Units
 * are one office, the Regional Office, as on the biometric logs page. Each
 * employee's record is audited like a print of it.
 */
class OfficeDtrExportController extends Controller
{
    public function __invoke(Request $request, DtrSheets $sheets, DtrWorkbook $workbook): Response
    {
        $this->authorize('viewAny', BiometricPunch::class);

        $data = $request->validate([
            'office' => ['required', 'string', 'regex:/^(regional|\d+)$/'],
            'month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'layout' => ['nullable', 'in:single,double'],
        ]);
        $layout = ($data['layout'] ?? null) === 'double' ? DtrWorkbook::DOUBLE : DtrWorkbook::SINGLE;
        $month = DtrSheets::parseMonth($data['month']);

        if ($data['office'] === UploadMonitor::REGIONAL) {
            $label = 'Regional Office';
            $unitIds = OrganizationalUnit::query()->where('unit_type', UnitType::RegionalSupportUnit)->pluck('id');
            $withoutOffice = true;
        } else {
            $unit = OrganizationalUnit::query()->findOrFail((int) $data['office']);
            abort_if($unit->unit_type === UnitType::RegionalSupportUnit, 404);
            $label = $unit->name;
            $unitIds = collect([$unit->id]);
            $withoutOffice = false;
        }

        $employees = Employee::query()->with('user.organizationalUnit')->whereNull('separation_date')->where('employment_status', EmploymentStatus::Permanent)
            ->whereHas('user', fn ($query) => $query->where(fn ($inner) => $inner->whereIn('organizational_unit_id', $unitIds)->when($withoutOffice, fn ($q) => $q->orWhereNull('organizational_unit_id'))))
            ->get()->sortBy(fn (Employee $employee) => mb_strtolower($employee->user->name))->values();

        abort_if($employees->isEmpty(), 404, 'No employees in this office.');

        $entries = [];
        foreach ($employees as $employee) {
            foreach ($sheets->forMonth($employee, $month)['sheets'] as $sheet) {
                $entries[] = ['employee' => $employee->user->name, 'sheet' => $sheet];
            }
            if ($employee->user_id !== $request->user()->id) {
                $this->audit()->record(AuditEvent::DtrExported, "Exported the DTR of {$employee->user->name} to Excel", $employee, ['month' => $month->format('Y-m'), 'office' => $label, 'bulk' => true]);
            }
        }

        return DtrDownload::xlsx($workbook->build($entries, now()->format('n/j/Y g:i:s A'), $layout, $request->user()->username), "CS Form 48 - {$label} - {$month->format('F Y')}".($layout === DtrWorkbook::DOUBLE ? ' (double)' : ''));
    }
}
