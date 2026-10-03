<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeDocument;
use App\Support\HR\EmployeeAssignments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * HR-1b: HR records an employee's movements between offices
 * (`employees.reassign`, never their own). The history shows on the
 * employee record; EmployeeAssignments decides when the account's office
 * moves (reassignments only).
 */
class EmployeeAssignmentController extends Controller
{
    public function store(Request $request, Employee $employee, EmployeeAssignments $assignments): RedirectResponse
    {
        $this->authorize('reassign', $employee);
        $employee->load('user');

        $result = $assignments->record($employee, $this->validated($request, $employee, $assignments), $request->user());
        $assignment = $result['assignment'];

        $this->auditScan($result['document']);
        $this->audit()->record(
            AuditEvent::EmployeeAssignmentRecorded,
            "Recorded the {$assignment->label} to {$assignment->toUnit?->name}",
            $assignment,
            array_filter(['kind' => $assignment->kind->value, 'effective_date' => $assignment->effective_date->toDateString(), 'office_moved' => $result['office']]),
        );

        return back()->with('status', $this->message("{$assignment->kind->label()} recorded.", $result['office']));
    }

    public function update(Request $request, Employee $employee, EmployeeAssignment $assignment, EmployeeAssignments $assignments): RedirectResponse
    {
        $this->authorize('reassign', $employee);
        $employee->load('user');

        $result = $assignments->update($assignment, $this->validated($request, $employee, $assignments), $request->user());

        $this->auditScan($result['document']);
        if ($result['changed'] !== [] || $result['office']) {
            $this->audit()->record(
                AuditEvent::EmployeeAssignmentUpdated,
                "Corrected the {$assignment->label}",
                $assignment,
                array_filter(['fields' => $result['changed'], 'office_moved' => $result['office']]),
            );
        }

        return back()->with('status', $this->message("{$assignment->kind->label()} saved.", $result['office']));
    }

    public function destroy(Request $request, Employee $employee, EmployeeAssignment $assignment, EmployeeAssignments $assignments): RedirectResponse
    {
        $this->authorize('reassign', $employee);

        $office = $assignments->delete($assignment);

        $this->audit()->record(
            AuditEvent::EmployeeAssignmentDeleted,
            "Removed the {$assignment->label}",
            $assignment,
            array_filter(['kind' => $assignment->kind->value, 'office_moved' => $office]),
        );

        return back()->with('status', $this->message("{$assignment->kind->label()} removed.", $office));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Employee $employee, EmployeeAssignments $assignments): array
    {
        return $request->validate($assignments->rules($employee), [], EmployeeAssignments::attributes());
    }

    private function auditScan(?EmployeeDocument $document): void
    {
        if ($document) {
            $this->audit()->record(AuditEvent::EmployeeDocumentUploaded, "Uploaded {$document->label}", $document);
        }
    }

    /**
     * @param  ?array{from: ?string, to: ?string}  $office
     */
    private function message(string $done, ?array $office): string
    {
        return $office ? "{$done} Office changed from ".($office['from'] ?? 'none')." to {$office['to']}." : $done;
    }
}
