<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Enums\LdApprovalStatus;
use App\Enums\LdModality;
use App\Enums\LdType;
use App\Models\Employee;
use App\Models\Pds\PdsLearningDevelopment;
use App\Support\HR\LearningDevelopment\LdRecords;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HR-3a self-service (ldms's "My L&D Interventions"): your own Learning &
 * Development log by year — summary cards, every training, add / edit /
 * remove until HRD approves, and certificates. Entries start pending HRD
 * review, and appear on your PDS (Section VI) unless rejected.
 */
class MyLearningDevelopmentController extends Controller
{
    public function index(Request $request, LdRecords $records): Response
    {
        $employee = $request->user()->employee;

        if (! $employee) {
            return Inertia::render('Portal/MyLearningDevelopment', ['employee' => null]);
        }

        $log = $records->yearLog($employee, (int) $request->integer('year', (int) now()->year));

        $employee->loadMissing('user.organizationalUnit');

        return Inertia::render('Portal/MyLearningDevelopment', [
            'employee' => [
                'name' => $employee->user->name,
                'position' => $employee->user->position,
                'unit' => $employee->user->organizationalUnit?->short_name ?: $employee->user->organizationalUnit?->name,
            ],
            'year' => $log['year'],
            'years' => $log['years'],
            'records' => $log['rows']->map(fn (PdsLearningDevelopment $record) => [
                ...$records->present($record, $log['duplicates'][$record->id] ?? null),
                'can' => [
                    'update' => $request->user()->can('update', $record),
                    'delete' => $request->user()->can('delete', $record),
                ],
            ]),
            'stats' => $log['stats'],
            'typeOptions' => LdType::options(),
            'modalityOptions' => LdModality::options(),
            'certificateKilobytes' => LdRecords::CERTIFICATE_KILOBYTES,
        ]);
    }

    public function store(Request $request, LdRecords $records): RedirectResponse
    {
        $employee = $this->employee($request);
        $validated = $request->validate($records->rules($employee), [], LdRecords::attributes());

        // Your own entry waits for HRD review (ldms behaviour).
        $record = $records->create($employee, $validated, $request->user(), approved: false);

        $this->audit()->record(AuditEvent::LdRecordCreated, "Added the training \"{$record->title}\" to their L&D log", $record);

        return back()->with('status', 'Training added. HRD will review it; it already shows on your PDS.');
    }

    public function update(Request $request, PdsLearningDevelopment $training, LdRecords $records): RedirectResponse
    {
        $this->authorize('update', $training);

        $validated = $request->validate($records->rules($this->employee($request), $training), [], LdRecords::attributes());
        $wasRejected = $training->approval_status === LdApprovalStatus::Rejected;

        $changed = $records->update($training, $validated, resubmit: true);

        if ($changed) {
            $this->audit()->record(AuditEvent::LdRecordUpdated, "Updated the training \"{$training->title}\" in their L&D log", $training, ['changed' => $changed]);
        }

        return back()->with('status', $wasRejected ? 'Training updated and sent back to HRD for review.' : 'Training updated.');
    }

    public function destroy(Request $request, PdsLearningDevelopment $training, LdRecords $records): RedirectResponse
    {
        $this->authorize('delete', $training);

        $records->delete($training);

        $this->audit()->record(AuditEvent::LdRecordDeleted, "Removed the training \"{$training->title}\" from their L&D log", $training);

        return back()->with('status', 'Training removed.');
    }

    /**
     * A training's certificate, for its owner or HRD (`ld.view`).
     */
    public function certificate(Request $request, PdsLearningDevelopment $training): StreamedResponse
    {
        $this->authorize('view', $training);
        abort_unless($training->certificate_path && Storage::disk(LdRecords::DISK)->exists($training->certificate_path), 404);

        if ($training->employee()->value('user_id') !== $request->user()->id) {
            $this->audit()->record(AuditEvent::LdCertificateViewed, "Opened the certificate of the training \"{$training->title}\"", $training);
        }

        return Storage::disk(LdRecords::DISK)->response($training->certificate_path, $training->certificate_name, [
            'Content-Type' => $training->certificate_mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    private function employee(Request $request): Employee
    {
        return $request->user()->employee ?? abort(404);
    }
}
