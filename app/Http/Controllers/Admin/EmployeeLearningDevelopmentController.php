<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Enums\LdModality;
use App\Enums\LdType;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Pds\PdsLearningDevelopment;
use App\Support\HR\LearningDevelopment\LdRecords;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-3b: one employee's L&D log for HRD — the same year view the employee
 * has, plus adding (approved at once), editing and removing on their
 * behalf (`ld.manage`, approved records included) and reviewing pending
 * entries (`ld.approve`). Never on your own log. Opening it is audited.
 */
class EmployeeLearningDevelopmentController extends Controller
{
    public function index(Request $request, Employee $employee, LdRecords $records): Response
    {
        $this->authorize('viewLd', $employee);

        $viewer = $request->user();
        $log = $records->yearLog($employee, (int) $request->integer('year', (int) now()->year));
        $employee->load('user.organizationalUnit');

        if ($employee->user_id !== $viewer->id) {
            $this->audit()->record(AuditEvent::LdLogViewed, "Viewed the L&D log of {$employee->user->name}", $employee, ['year' => $log['year']]);
        }

        return Inertia::render('Admin/HR/Employees/LearningDevelopment', [
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->user->name,
                'position' => $employee->user->position,
                'unit' => $employee->user->organizationalUnit?->short_name ?: $employee->user->organizationalUnit?->name,
            ],
            'year' => $log['year'],
            'years' => $log['years'],
            'records' => $log['rows']->map(fn (PdsLearningDevelopment $record) => [
                ...$records->present($record, $log['duplicates'][$record->id] ?? null),
                'can' => [
                    'update' => $viewer->can('manage', $record),
                    'delete' => $viewer->can('manage', $record),
                    'review' => $viewer->can('review', $record),
                ],
            ]),
            'stats' => $log['stats'],
            'canAdd' => $viewer->can('manageLd', $employee),
            'typeOptions' => LdType::options(),
            'modalityOptions' => LdModality::options(),
            'certificateKilobytes' => LdRecords::CERTIFICATE_KILOBYTES,
        ]);
    }

    public function store(Request $request, Employee $employee, LdRecords $records): RedirectResponse
    {
        $this->authorize('manageLd', $employee);

        $validated = $request->validate($records->rules($employee), [], LdRecords::attributes());

        // HRD's entries need no review (ldms behaviour).
        $record = $records->create($employee, $validated, $request->user(), approved: true);

        $this->audit()->record(AuditEvent::LdRecordCreated, "Added the training \"{$record->title}\" to the L&D log of {$employee->user->name}", $record, ['on_behalf' => true]);

        return back()->with('status', 'Training added and approved.');
    }

    public function update(Request $request, Employee $employee, PdsLearningDevelopment $training, LdRecords $records): RedirectResponse
    {
        $this->authorize('manage', $training);

        $validated = $request->validate($records->rules($employee, $training), [], LdRecords::attributes());

        // HRD's edit keeps the entry's status (a rejected one stays rejected
        // until the employee resubmits it or HRD reviews it again).
        $changed = $records->update($training, $validated, resubmit: false);

        if ($changed) {
            $this->audit()->record(AuditEvent::LdRecordUpdated, "Updated the training \"{$training->title}\" in the L&D log of {$employee->user->name}", $training, ['changed' => $changed, 'on_behalf' => true]);
        }

        return back()->with('status', 'Training updated.');
    }

    public function destroy(Employee $employee, PdsLearningDevelopment $training, LdRecords $records): RedirectResponse
    {
        $this->authorize('manage', $training);

        $records->delete($training);

        $this->audit()->record(AuditEvent::LdRecordDeleted, "Removed the training \"{$training->title}\" from the L&D log of {$employee->user->name}", $training, ['on_behalf' => true]);

        return back()->with('status', 'Training removed.');
    }
}
