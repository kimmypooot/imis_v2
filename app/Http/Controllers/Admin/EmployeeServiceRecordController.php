<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\ServiceRecordEntry;
use App\Support\HR\ServiceRecords;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-5: someone's Service Record for HR (`service_records.view`) — on
 * screen, and as the printable, certified form. Opening or printing
 * anyone else's is audited. HR (`service_records.manage`) adds service in
 * other agencies as lines of its own, never on their own record.
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
            'canManage' => $request->user()->can('manageServiceRecord', $employee),
        ]);
    }

    /** Adds service in another agency. */
    public function store(Request $request, Employee $employee, ServiceRecords $records): RedirectResponse
    {
        $this->authorize('manageServiceRecord', $employee);

        $entry = $records->saveEntry($employee, $request->validate($records->entryRules(), [], ServiceRecords::entryAttributes()), $request->user());
        $this->audit()->record(AuditEvent::ServiceRecordLineAdded, "Added a {$entry->label} to the Service Record of {$employee->user->name}", $entry);

        return back()->with('status', 'Service line added.');
    }

    public function update(Request $request, Employee $employee, ServiceRecordEntry $serviceRecordEntry, ServiceRecords $records): RedirectResponse
    {
        $this->authorize('manageServiceRecord', $employee);

        $records->saveEntry($employee, $request->validate($records->entryRules(), [], ServiceRecords::entryAttributes()), $request->user(), $serviceRecordEntry);
        if ($changed = array_keys($serviceRecordEntry->getChanges())) {
            $this->audit()->record(AuditEvent::ServiceRecordLineUpdated, "Corrected a {$serviceRecordEntry->label} on the Service Record of {$employee->user->name}", $serviceRecordEntry,
                ['fields' => array_values(array_diff($changed, ['updated_at']))]);
        }

        return back()->with('status', 'Service line saved.');
    }

    public function destroy(Employee $employee, ServiceRecordEntry $serviceRecordEntry, ServiceRecords $records): RedirectResponse
    {
        $this->authorize('manageServiceRecord', $employee);

        $records->removeEntry($serviceRecordEntry);
        $this->audit()->record(AuditEvent::ServiceRecordLineRemoved, "Removed a {$serviceRecordEntry->label} from the Service Record of {$employee->user->name}", $serviceRecordEntry);

        return back()->with('status', 'Service line removed.');
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
