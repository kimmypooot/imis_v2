<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Support\HR\ServiceRecords;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-5: someone's Service Record for HR (`service_records.view`) — on
 * screen, and as the printable, certified form. Opening or printing
 * anyone else's is audited.
 */
class EmployeeServiceRecordController extends Controller
{
    public function show(Request $request, Employee $employee, ServiceRecords $records): Response
    {
        $this->authorize('viewServiceRecord', $employee);
        $this->trail($request, $employee, AuditEvent::ServiceRecordViewed, 'Viewed');

        return Inertia::render('Admin/HR/Employees/ServiceRecord', [
            'employee' => ['id' => $employee->id, 'name' => $employee->user->name],
            ...$records->build($employee),
            'printUrl' => route('admin.hr.employees.service-record.print', $employee),
        ]);
    }

    public function print(Request $request, Employee $employee, ServiceRecords $records): Response
    {
        $this->authorize('viewServiceRecord', $employee);
        $this->trail($request, $employee, AuditEvent::ServiceRecordPrinted, 'Printed');

        return Inertia::render('HR/ServiceRecordPrint', [
            ...$records->build($employee),
            'certification' => $records->certification(),
            'certified' => true,
        ]);
    }

    private function trail(Request $request, Employee $employee, AuditEvent $event, string $verb): void
    {
        if ($employee->user_id !== $request->user()->id) {
            $this->audit()->record($event, "{$verb} the Service Record of {$employee->user->name}", $employee);
        }
    }
}
