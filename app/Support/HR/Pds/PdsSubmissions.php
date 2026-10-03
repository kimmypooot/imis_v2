<?php

namespace App\Support\HR\Pds;

use App\Enums\PdsSubmissionStatus;
use App\Models\Employee;
use App\Models\Pds\PdsSubmission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * HR-2b: signing, reviewing, and the state of an employee's PDS.
 *
 * A submission freezes the whole PDS as the employee e-signed it. HR
 * verifies or returns that frozen copy. Whether the live PDS has changed
 * since is decided by comparing fingerprints — an HMAC over the content
 * (row ids excluded, so reordering-free saves don't count as changes).
 */
class PdsSubmissions
{
    public const FORM = 'CS Form No. 212 (Revised 2026)';

    public function __construct(private readonly PdsReader $reader, private readonly PdsPhotos $photos) {}

    /**
     * E-signs and submits the live PDS. An earlier submission still
     * awaiting review is superseded — HR only ever reviews the latest.
     */
    public function submit(Employee $employee, User $signer, ?string $ip): PdsSubmission
    {
        return DB::transaction(function () use ($employee, $signer, $ip) {
            $employee->submissions()
                ->where('status', PdsSubmissionStatus::Submitted)
                ->update(['status' => PdsSubmissionStatus::Superseded]);

            $data = $this->reader->read($employee);
            $photo = $employee->pdsProfile?->photo_path;

            $submission = new PdsSubmission;
            $submission->forceFill([
                'employee_id' => $employee->id,
                'status' => PdsSubmissionStatus::Submitted,
                'snapshot' => [...$data, 'form' => self::FORM],
                'fingerprint' => $this->fingerprint($data, $photo),
                'photo_path' => $this->photos->copyForSubmission($photo),
                'signed_by' => $signer->id,
                'signed_at' => now(),
                'signed_ip' => $ip,
            ])->save();

            return $submission;
        });
    }

    public function review(PdsSubmission $submission, User $reviewer, PdsSubmissionStatus $verdict, ?string $remarks): void
    {
        if (! in_array($verdict, [PdsSubmissionStatus::Verified, PdsSubmissionStatus::Returned], true) || ! $submission->isPending()) {
            throw new InvalidArgumentException('Only a pending submission can be verified or returned.');
        }

        $submission->forceFill([
            'status' => $verdict,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'remarks' => $remarks,
        ])->save();
    }

    /**
     * Where the employee's PDS stands, for the status card:
     * draft · submitted · changed_since_submitted · returned · verified ·
     * changed_since_verified.
     *
     * @return array<string, mixed>
     */
    public function status(Employee $employee): array
    {
        $latest = $employee->submissions()->where('status', '!=', PdsSubmissionStatus::Superseded)->latest('id')->first();
        $lastVerified = $employee->submissions()->where('status', PdsSubmissionStatus::Verified)->latest('id')->first();
        $current = $latest ? $this->currentFingerprint($employee) : null;

        $state = match (true) {
            $latest === null => 'draft',
            $latest->status === PdsSubmissionStatus::Returned => 'returned',
            $latest->status === PdsSubmissionStatus::Submitted => $latest->fingerprint === $current ? 'submitted' : 'changed_since_submitted',
            default => $latest->fingerprint === $current ? 'verified' : 'changed_since_verified',
        };

        return [
            'state' => $state,
            'latest' => $latest ? $this->summary($latest) : null,
            'last_verified_at' => $lastVerified?->reviewed_at?->toIso8601String(),
        ];
    }

    /**
     * The submission history (newest first), without the snapshots.
     *
     * @return array<int, array<string, mixed>>
     */
    public function history(Employee $employee): array
    {
        return $employee->submissions()
            ->with('reviewer:id,name')
            ->latest('id')
            ->get()
            ->map(fn (PdsSubmission $submission) => $this->summary($submission))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(PdsSubmission $submission): array
    {
        return [
            'id' => $submission->id,
            'status' => $submission->status->value,
            'status_label' => $submission->status->label(),
            'signed_at' => $submission->signed_at->toIso8601String(),
            'reviewed_at' => $submission->reviewed_at?->toIso8601String(),
            'reviewer' => $submission->reviewer?->name,
            'remarks' => $submission->remarks,
        ];
    }

    /**
     * Sections that differ between two PDS copies (e.g. a submission and
     * the last verified one), so HR can see what to look at.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array<int, string>
     */
    public function changedSections(array $a, array $b): array
    {
        $changed = collect(PdsSchema::sectionKeys())
            ->filter(fn (string $key) => $this->withoutIds($a['sections'][$key] ?? null) != $this->withoutIds($b['sections'][$key] ?? null))
            ->values()
            ->all();

        if (($a['identity'] ?? null) != ($b['identity'] ?? null)) {
            array_unshift($changed, 'identity');
        }

        return $changed;
    }

    public function currentFingerprint(Employee $employee): string
    {
        $employee->unsetRelation('pdsProfile');

        return $this->fingerprint($this->reader->read($employee), $employee->pdsProfile?->photo_path);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fingerprint(array $data, ?string $photo): string
    {
        $content = json_encode([
            'identity' => $data['identity'],
            'sections' => array_map(fn ($section) => $this->withoutIds($section), $data['sections']),
            'photo' => $photo,
        ]);

        return hash_hmac('sha256', (string) $content, (string) config('app.key'));
    }

    /**
     * A list section's rows without their database ids (content only).
     */
    private function withoutIds(mixed $section): mixed
    {
        if (! is_array($section) || ! array_is_list($section)) {
            return $section;
        }

        return array_map(fn ($row) => is_array($row) ? array_diff_key($row, ['id' => true]) : $row, $section);
    }
}
