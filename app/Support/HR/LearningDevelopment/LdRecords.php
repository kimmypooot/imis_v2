<?php

namespace App\Support\HR\LearningDevelopment;

use App\Enums\LdApprovalStatus;
use App\Enums\LdModality;
use App\Enums\LdType;
use App\Models\Employee;
use App\Models\Pds\PdsLearningDevelopment;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Enum;

/**
 * HR-3: the only writer of Learning & Development records (the PDS
 * Section VI rows). Carries over ldms's rules — hours above zero, end not
 * before start, no duplicate (same title and start date for the same
 * person) — plus certificates on the private disk.
 */
class LdRecords
{
    public const DISK = 'local';

    public const CERTIFICATE_KILOBYTES = 10240;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(Employee $employee, ?PdsLearningDevelopment $ignore = null): array
    {
        return [
            'title' => ['required', 'string', 'max:512', $this->notDuplicate($employee, $ignore)],
            'date_from' => ['required', 'date', 'after:1900-01-01'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'hours' => ['required', 'numeric', 'gt:0', 'max:9999'],
            'ld_type' => ['required', new Enum(LdType::class)],
            'ld_type_other' => ['nullable', 'required_if:ld_type,other', 'string', 'max:100'],
            'modality' => ['required', new Enum(LdModality::class)],
            'sponsor' => ['required', 'string', 'max:512'],
            'venue' => ['nullable', 'string', 'max:255'],
            'resource_speaker' => ['nullable', 'string', 'max:512'],
            'certificate' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.self::CERTIFICATE_KILOBYTES],
            'remove_certificate' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'title' => 'title',
            'date_from' => 'start date',
            'date_to' => 'end date',
            'ld_type' => 'type',
            'ld_type_other' => 'type (specify)',
            'sponsor' => 'conducted/sponsored by',
            'resource_speaker' => 'resource speaker',
        ];
    }

    /**
     * @param  array<string, mixed>  $data  validated
     */
    public function create(Employee $employee, array $data, User $actor, bool $approved): PdsLearningDevelopment
    {
        return DB::transaction(function () use ($employee, $data, $actor, $approved) {
            $record = new PdsLearningDevelopment;
            $record->fill($this->fields($data));
            $record->forceFill([
                'employee_id' => $employee->id,
                'created_by' => $actor->id,
                'approval_status' => $approved ? LdApprovalStatus::Approved : LdApprovalStatus::Pending,
                'reviewed_by' => $approved ? $actor->id : null,
                'reviewed_at' => $approved ? now() : null,
            ])->save();

            $this->applyCertificate($record, $data);

            return $record;
        });
    }

    /**
     * Saves changes. When the owner edits a rejected entry it goes back to
     * pending for HRD to look at again.
     *
     * @param  array<string, mixed>  $data  validated
     * @return array<int, string> changed field names (for the audit log)
     */
    public function update(PdsLearningDevelopment $record, array $data, bool $resubmit): array
    {
        return DB::transaction(function () use ($record, $data, $resubmit) {
            $record->fill($this->fields($data));

            if ($resubmit && $record->approval_status === LdApprovalStatus::Rejected) {
                $record->forceFill(['approval_status' => LdApprovalStatus::Pending, 'rejection_reason' => null, 'reviewed_by' => null, 'reviewed_at' => null]);
            }

            $changed = array_keys($record->getDirty());
            $record->save();

            if ($this->applyCertificate($record, $data)) {
                $changed[] = 'certificate';
            }

            return array_values(array_diff($changed, ['updated_at', 'rejection_reason', 'reviewed_by', 'reviewed_at']));
        });
    }

    /**
     * HR-3c: one record carried over from ldms as it was — status, review
     * and timestamps included (`imis:import-legacy-ld`). No validation:
     * the importer maps and checks the legacy row first.
     *
     * @param  array<string, mixed>  $attributes  columns of pds_learning_developments
     */
    public function import(Employee $employee, array $attributes): PdsLearningDevelopment
    {
        $record = new PdsLearningDevelopment;
        $record->timestamps = false;
        $record->forceFill(['employee_id' => $employee->id, ...$attributes])->save();

        return $record;
    }

    /**
     * The logged training that `$row` repeats, if any. Same start date and
     * the same title once case and punctuation are ignored; or the same
     * start and end dates and hours with a mostly shared title (the same
     * seminar typed a little differently).
     *
     * @param  Collection<int, PdsLearningDevelopment>  $logged
     * @param  array<string, mixed>  $row  title, date_from, date_to, hours
     */
    public function similarTo(Collection $logged, array $row): ?PdsLearningDevelopment
    {
        if (! ($row['title'] ?? null) || ! ($row['date_from'] ?? null)) {
            return null;
        }

        $title = $this->normalized($row['title']);

        return $logged->first(function (PdsLearningDevelopment $other) use ($row, $title) {
            if ($other->date_from?->toDateString() !== $row['date_from']) {
                return false;
            }

            if ($this->normalized($other->title) === $title) {
                return true;
            }

            return $other->date_to?->toDateString() === ($row['date_to'] ?? null)
                && abs((float) $other->hours - (float) ($row['hours'] ?? -1)) < 0.01
                && $this->sharedWords($other->title, $row['title']) >= 0.5;
        });
    }

    /**
     * Records that look like another, earlier entry of the same person:
     * record id => the entry it resembles. Rejected entries are ignored.
     *
     * @param  Collection<int, PdsLearningDevelopment>  $records
     * @return array<int, array{id: int, title: string}>
     */
    public function possibleDuplicates(Collection $records): array
    {
        $live = $records->where('approval_status', '!==', LdApprovalStatus::Rejected)->sortBy('id')->values();
        $found = [];

        foreach ($live as $index => $record) {
            $match = $this->similarTo($live->take($index), [
                'title' => $record->title,
                'date_from' => $record->date_from?->toDateString(),
                'date_to' => $record->date_to?->toDateString(),
                'hours' => (float) $record->hours,
            ]);

            if ($match) {
                $found[$record->id] = ['id' => $match->id, 'title' => $match->title];
            }
        }

        return $found;
    }

    /**
     * Lower case, punctuation gone, single spaces: "Well-Being" = "WELL BEING".
     */
    public function normalized(string $title): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($title)));
    }

    /**
     * Share of the shorter title's meaningful words that the other one has.
     */
    private function sharedWords(string $a, string $b): float
    {
        $filler = ['a', 'an', 'and', 'for', 'in', 'of', 'on', 'the', 'to', 'with'];
        $words = fn (string $title) => array_values(array_diff(array_unique(explode(' ', $this->normalized($title))), $filler, ['']));
        [$first, $second] = [$words($a), $words($b)];
        $smaller = min(count($first), count($second));

        return $smaller === 0 ? 0.0 : count(array_intersect($first, $second)) / $smaller;
    }

    /**
     * Whether this person already has the training (ldms's duplicate rule).
     */
    public function hasDuplicate(Employee $employee, string $title, string $start): bool
    {
        return $this->duplicates($title, $start)->where('employee_id', $employee->id)->exists();
    }

    /**
     * HR-3b bulk add (ldms's "Add Training for Multiple Employees"): the
     * fields of {@see rules()} without the per-person parts (certificate,
     * duplicate check — duplicates are skipped instead), plus who.
     *
     * @return array<string, array<int, mixed>>
     */
    public function bulkRules(): array
    {
        return [
            ...collect($this->rules(new Employee))->except(['title', 'certificate', 'remove_certificate'])->all(),
            'title' => ['required', 'string', 'max:512'],
            'employee_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'employee_ids.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * Adds the same training, approved, to many people's logs. Anyone who
     * already has it (ldms's duplicate rule) is skipped and reported, as
     * ldms did.
     *
     * @param  Collection<int, Employee>  $employees
     * @param  array<string, mixed>  $data  validated
     * @return array{created: Collection<int, PdsLearningDevelopment>, skipped: Collection<int, Employee>}
     */
    public function createMany(Collection $employees, array $data, User $actor): array
    {
        $already = $this->duplicates($data['title'], $data['date_from'])
            ->whereIn('employee_id', $employees->modelKeys())
            ->pluck('employee_id');

        [$skipped, $adding] = $employees->partition(fn (Employee $employee) => $already->contains($employee->id));

        $created = DB::transaction(fn () => $adding
            ->map(fn (Employee $employee) => $this->create($employee, collect($data)->except('employee_ids')->all(), $actor, approved: true))
            ->values());

        return ['created' => $created, 'skipped' => $skipped->values()];
    }

    /**
     * HRD's verdict on a pending entry. Rejecting needs a reason the
     * employee can act on; they can fix the entry and resubmit it.
     */
    public function review(PdsLearningDevelopment $record, User $reviewer, LdApprovalStatus $verdict, ?string $reason = null): void
    {
        $record->forceFill([
            'approval_status' => $verdict,
            'rejection_reason' => $verdict === LdApprovalStatus::Rejected ? $reason : null,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ])->save();
    }

    /**
     * Soft delete: the record leaves the log and the PDS; the certificate
     * file is kept with it.
     */
    public function delete(PdsLearningDevelopment $record): void
    {
        $record->delete();
    }

    /**
     * One person's log for a year, as ldms's "My L&D Interventions" shows
     * it: the year's records (newest first), the years to pick from, and
     * the summary cards — which, like ldms, don't count rejected entries.
     *
     * @return array{year: int, years: \Illuminate\Support\Collection<int, int>, rows: Collection<int, PdsLearningDevelopment>, stats: array<string, mixed>}
     */
    public function yearLog(Employee $employee, int $year): array
    {
        $all = PdsLearningDevelopment::query()->where('employee_id', $employee->id);

        $rows = (clone $all)
            ->with('reviewer:id,name')
            ->whereYear('date_from', $year)
            ->orderByDesc('date_from')
            ->orderByDesc('id')
            ->get();

        $counted = $rows->where('approval_status', '!==', LdApprovalStatus::Rejected);

        // Years with trainings (computed in PHP: portable across databases),
        // plus this year and the one being viewed.
        $years = (clone $all)->pluck('date_from')->map(fn ($date) => (int) substr((string) $date, 0, 4))
            ->push((int) now()->year)->push($year)->unique()->sortDesc()->values();

        return [
            'year' => $year,
            'years' => $years,
            'rows' => $rows,
            'duplicates' => $this->possibleDuplicates($rows),
            'stats' => [
                'total' => $counted->count(),
                'hours' => (float) $counted->sum('hours'),
                'pending' => $counted->where('approval_status', LdApprovalStatus::Pending)->count(),
                'by_type' => collect(LdType::cases())->mapWithKeys(fn (LdType $type) => [
                    $type->value => $counted->where('ld_type', $type)->count(),
                ]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(PdsLearningDevelopment $record, ?array $duplicateOf = null): array
    {
        return [
            'id' => $record->id,
            'title' => $record->title,
            'date_from' => $record->date_from?->toDateString(),
            'date_to' => $record->date_to?->toDateString(),
            'hours' => $record->hours,
            'ld_type' => $record->ld_type?->value,
            'ld_type_other' => $record->ld_type_other,
            'type_label' => $record->typeLabel() ?? 'Not recorded',
            'modality' => $record->modality?->value,
            'modality_label' => $record->modality?->label() ?? 'Not recorded',
            'sponsor' => $record->sponsor,
            'venue' => $record->venue,
            'resource_speaker' => $record->resource_speaker,
            'possible_duplicate_of' => $duplicateOf,
            'approval_status' => $record->approval_status->value,
            'status_label' => $record->approval_status->label(),
            'rejection_reason' => $record->rejection_reason,
            'reviewer' => $record->reviewer?->name,
            'reviewed_at' => $record->reviewed_at?->toIso8601String(),
            'certificate' => $record->certificate_path
                ? ['name' => $record->certificate_name, 'mime' => $record->certificate_mime, 'size' => $record->certificate_size]
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data): array
    {
        $fields = collect($data)->only(['title', 'date_from', 'date_to', 'hours', 'ld_type', 'ld_type_other', 'modality', 'sponsor', 'venue', 'resource_speaker'])->all();

        // "Specify" only applies to type Other.
        if (($fields['ld_type'] ?? null) !== LdType::Other->value) {
            $fields['ld_type_other'] = null;
        }

        return $fields;
    }

    /**
     * Stores a new certificate (replacing the old one) or removes it.
     * Returns whether anything changed.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyCertificate(PdsLearningDevelopment $record, array $data): bool
    {
        $file = $data['certificate'] ?? null;
        $remove = (bool) ($data['remove_certificate'] ?? false);

        if (! $file instanceof UploadedFile && ! $remove) {
            return false;
        }

        $old = $record->certificate_path;

        if ($file instanceof UploadedFile) {
            $extension = $file->guessExtension() === 'jpeg' ? 'jpg' : $file->guessExtension();
            $record->forceFill([
                'certificate_path' => $file->storeAs("hr/ld/{$record->employee_id}", Str::uuid().'.'.$extension, self::DISK),
                'certificate_name' => Str::limit($file->getClientOriginalName(), 250, ''),
                'certificate_mime' => $file->getMimeType(),
                'certificate_size' => $file->getSize(),
            ]);
        } else {
            $record->forceFill(['certificate_path' => null, 'certificate_name' => null, 'certificate_mime' => null, 'certificate_size' => null]);
        }

        $record->save();

        if ($old) {
            Storage::disk(self::DISK)->delete($old);
        }

        return true;
    }

    /**
     * ldms's duplicate rule: the same title (ignoring case) starting the
     * same day, for the same person.
     */
    private function notDuplicate(Employee $employee, ?PdsLearningDevelopment $ignore): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($employee, $ignore) {
            $start = request()->input('date_from');

            if ($start && $this->duplicates((string) $value, (string) $start, $ignore)->where('employee_id', $employee->id)->exists()) {
                $fail('A training with the same title and start date is already in this log.');
            }
        };
    }

    /**
     * Records matching ldms's duplicate rule (same title ignoring case,
     * same start date) — narrowed to a person by the caller.
     *
     * @return Builder<PdsLearningDevelopment>
     */
    private function duplicates(string $title, string $start, ?PdsLearningDevelopment $ignore = null): Builder
    {
        return PdsLearningDevelopment::query()
            ->whereDate('date_from', $start)
            ->whereRaw('LOWER(title) = ?', [mb_strtolower(trim($title))])
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id));
    }
}
