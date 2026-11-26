<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Http\Controllers\Concerns\SavesPdsSections;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Support\HR\Pds\PdsPrintout;
use App\Support\HR\Pds\PdsReader;
use App\Support\HR\Pds\PdsSchema;
use App\Support\HR\Pds\PdsSubmissions;
use App\Support\HR\Pds\PdsWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-2: HR reading (pds.view) and, on someone's behalf, editing
 * (pds.update) an employee's PDS, with its verification status and
 * history. Supervisors never reach it. Opening or printing someone
 * else's PDS is audited.
 */
class EmployeePdsController extends Controller
{
    use SavesPdsSections;

    public function show(Request $request, Employee $employee, PdsReader $reader, PdsSubmissions $submissions): Response
    {
        $this->authorize('viewPds', $employee);

        $employee->load('user');

        if ($employee->user_id !== $request->user()->id) {
            $this->audit()->record(AuditEvent::PdsViewed, "Viewed the PDS of {$employee->user->name}", $employee);
        }

        return Inertia::render('Admin/HR/Employees/Pds', [
            'employee' => ['id' => $employee->id, 'name' => $employee->user->name],
            'schema' => PdsSchema::forEditor(),
            'pds' => $reader->read($employee),
            'canEdit' => $request->user()->can('updatePds', $employee),
            'status' => $submissions->status($employee),
            'history' => $submissions->history($employee),
            'photoUrl' => $this->photoUrl($employee),
        ]);
    }

    public function update(Request $request, Employee $employee, string $section, PdsWriter $writer): RedirectResponse
    {
        $this->authorize('updatePds', $employee);

        return $this->savePdsSection($request, $employee, $section, $writer);
    }

    public function print(Request $request, Employee $employee, PdsReader $reader, PdsPrintout $printout): Response
    {
        $this->authorize('viewPds', $employee);

        if ($employee->user_id !== $request->user()->id) {
            $this->audit()->record(AuditEvent::PdsPrinted, "Printed the current PDS of {$employee->user->name}", $employee);
        }

        return Inertia::render('HR/PdsPrint', $printout->page(
            $reader->read($employee),
            $this->photoUrl($employee),
            null,
            route('admin.hr.employees.pds.print.xlsx', $employee, false),
        ));
    }

    /** The current PDS on CSC's own workbook (CS Form No. 212). */
    public function workbook(Request $request, Employee $employee, PdsReader $reader, PdsPrintout $printout): HttpResponse
    {
        $this->authorize('viewPds', $employee);

        if ($employee->user_id !== $request->user()->id) {
            $this->audit()->record(AuditEvent::PdsPrinted, "Downloaded the current PDS (Excel) of {$employee->user->name}", $employee);
        }

        return $printout->download($reader->read($employee), $employee->pdsProfile?->photo_path, null);
    }

    private function photoUrl(Employee $employee): ?string
    {
        $path = $employee->pdsProfile?->photo_path;

        return $path ? "/admin/hr/employees/{$employee->id}/pds/photo?v=".substr(md5($path), 0, 8) : null;
    }
}
