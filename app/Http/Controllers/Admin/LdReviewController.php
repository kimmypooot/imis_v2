<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Enums\LdApprovalStatus;
use App\Http\Controllers\Controller;
use App\Models\Pds\PdsLearningDevelopment;
use App\Support\HR\LearningDevelopment\LdRecords;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-3b: the L&D review queue (ldms's approve_training.php). Employees'
 * pending entries, oldest first; HRD approves, or rejects with a reason
 * the employee sees and can act on. Never their own entries.
 */
class LdReviewController extends Controller
{
    public function index(Request $request, LdRecords $records): Response
    {
        $this->authorize('viewQueue', PdsLearningDevelopment::class);

        $viewer = $request->user();

        $pending = PdsLearningDevelopment::query()
            ->where('approval_status', LdApprovalStatus::Pending)
            ->with(['employee.user.organizationalUnit', 'reviewer:id,name'])
            ->oldest('created_at')
            ->oldest('id')
            ->get()
            ->map(fn (PdsLearningDevelopment $record) => [
                ...$records->present($record),
                'employee' => [
                    'id' => $record->employee->id,
                    'name' => $record->employee->user->name,
                    'position' => $record->employee->user->position,
                    'unit' => $record->employee->user->organizationalUnit?->short_name ?: $record->employee->user->organizationalUnit?->name,
                ],
                'submitted_at' => $record->created_at?->toIso8601String(),
                // False only for the viewer's own entries.
                'can_review' => $viewer->can('review', $record),
            ]);

        return Inertia::render('Admin/HR/LearningDevelopment/Reviews', [
            'pending' => $pending,
            // Whether the names link to each person's whole log.
            'canViewLogs' => $viewer->can('viewAny', PdsLearningDevelopment::class),
        ]);
    }

    public function approve(Request $request, PdsLearningDevelopment $training, LdRecords $records): RedirectResponse
    {
        $this->authorize('review', $training);

        $records->review($training, $request->user(), LdApprovalStatus::Approved);

        $name = $training->employee->user->name;
        $this->audit()->record(AuditEvent::LdRecordApproved, "Approved the training \"{$training->title}\" of {$name}", $training);

        return back()->with('status', "Approved \"{$training->title}\" of {$name}.");
    }

    public function reject(Request $request, PdsLearningDevelopment $training, LdRecords $records): RedirectResponse
    {
        $this->authorize('review', $training);

        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];

        $records->review($training, $request->user(), LdApprovalStatus::Rejected, $reason);

        $name = $training->employee->user->name;
        // The reason is HRD's own words about the entry, not personal data.
        $this->audit()->record(AuditEvent::LdRecordRejected, "Rejected the training \"{$training->title}\" of {$name}", $training, ['reason' => $reason]);

        return back()->with('status', "Rejected \"{$training->title}\" of {$name}. They can fix and resubmit it.");
    }
}
