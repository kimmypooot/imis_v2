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
 * moves (reassignments only) and, for a designation as head of office,
 * when the office's staff are handed over to the designee and back.
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
            array_filter([
                'kind' => $assignment->kind->value,
                'as_head' => $assignment->as_head ?: null,
                'effective_date' => $assignment->effective_date->toDateString(),
                'office_moved' => $result['office'],
                'supervision' => $result['supervision'],
            ]),
        );

        return back()->with('status', $this->message("{$assignment->kind->label()} recorded.", $result['office'], $result['supervision']));
    }

    public function update(Request $request, Employee $employee, EmployeeAssignment $assignment, EmployeeAssignments $assignments): RedirectResponse
    {
        $this->authorize('reassign', $employee);
        $employee->load('user');

        $result = $assignments->update($assignment, $this->validated($request, $employee, $assignments), $request->user());

        $this->auditScan($result['document']);
        if ($result['changed'] !== [] || $result['office'] || $result['supervision']) {
            $this->audit()->record(
                AuditEvent::EmployeeAssignmentUpdated,
                "Corrected the {$assignment->label}",
                $assignment,
                array_filter(['fields' => $result['changed'], 'office_moved' => $result['office'], 'supervision' => $result['supervision']]),
            );
        }

        return back()->with('status', $this->message("{$assignment->kind->label()} saved.", $result['office'], $result['supervision']));
    }

    public function destroy(Request $request, Employee $employee, EmployeeAssignment $assignment, EmployeeAssignments $assignments): RedirectResponse
    {
        $this->authorize('reassign', $employee);

        $result = $assignments->delete($assignment);

        $this->audit()->record(
            AuditEvent::EmployeeAssignmentDeleted,
            "Removed the {$assignment->label}",
            $assignment,
            array_filter(['kind' => $assignment->kind->value, 'office_moved' => $result['office'], 'supervision' => $result['supervision']]),
        );

        return back()->with('status', $this->message("{$assignment->kind->label()} removed.", $result['office'], $result['supervision']));
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
     * @param  ?array<string, mixed>  $supervision  a head-of-office handover or give-back
     */
    private function message(string $done, ?array $office, ?array $supervision = null): string
    {
        $message = $office ? "{$done} Office changed from ".($office['from'] ?? 'none')." to {$office['to']}." : $done;

        if ($supervision) {
            $staff = fn (int $count) => $count.' '.($count === 1 ? 'staff member' : 'staff');
            $message .= $supervision['action'] === 'handed'
                ? " They are now the immediate supervisor of {$staff($supervision['count'])} of {$supervision['office']}."
                : " Previous immediate supervisors given back to {$staff($supervision['count'])} of {$supervision['office']}."
                    .($supervision['kept'] ? " {$staff($supervision['kept'])} kept the supervisor HR set since." : '');
        }

        return $message;
    }
}
