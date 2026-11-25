<?php

namespace App\Support\HR\Saln;

use App\Enums\AuditEvent;
use App\Enums\EmployeeDocumentType;
use App\Enums\HeadCapacity;
use App\Enums\PermissionName;
use App\Enums\SalnOathMode;
use App\Enums\SalnStatus;
use App\Enums\SalnType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\OrganizationalUnit;
use App\Models\Saln;
use App\Models\SalnExemption;
use App\Models\SalnVersion;
use App\Models\User;
use App\Notifications\HrUpdate;
use App\Support\Audit\AuditLogger;
use App\Support\HR\EmployeeDocuments;
use App\Support\HR\OfficeHeads;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only writer of SALNs. The employee (or HRD on their behalf) encodes
 * and prints numbered versions; HRD receives the hard copy of the latest
 * one; the SALN Committee — or HRD, overriding it — clears or returns it;
 * once sworn before the Regional Director (or a notary) the signed scan is
 * filed in the 201 File. Nobody decides their own SALN.
 */
class SalnFilings
{
    public const ORD_CODE = 'ORD';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EmployeeDocuments $documents,
        private readonly SalnPrefill $prefill,
    ) {}

    public function open(Employee $employee, SalnType $type, CarbonImmutable $asOf, User $by, bool $fromPrevious = false): Saln
    {
        if ($type === SalnType::Annual && $asOf->format('m-d') !== '12-31') {
            throw new InvalidArgumentException('An annual SALN is as of December 31.');
        }

        $exists = Saln::query()->where('employee_id', $employee->id)->where('type', $type)
            ->whereDate('reckoning_date', $asOf->toDateString())->exists();
        if ($exists) {
            throw new InvalidArgumentException('There is already a SALN for that filing.');
        }

        $previous = $fromPrevious
            ? Saln::query()->where('employee_id', $employee->id)->where('status', SalnStatus::Filed)->latest('reckoning_date')->first()
            : null;

        $saln = new Saln;
        $saln->forceFill([
            'employee_id' => $employee->id,
            'type' => $type,
            'reckoning_date' => $asOf->toDateString(),
            'status' => SalnStatus::Draft,
            'oath_mode' => SalnOathMode::RegionalDirector,
            'statement' => $previous ? $this->prefill->fromPrevious($previous, $type, $asOf) : $this->prefill->for($employee, $type, $asOf),
            'created_by' => $by->id,
            'updated_by' => $by->id,
        ])->save();

        return $saln;
    }

    /**
     * Saves the entries. Changing them after printing voids that printout:
     * the SALN goes back to draft until it is printed again, so HRD can
     * only ever receive a printout that matches the record.
     *
     * @param  array<string, mixed>  $statement
     */
    public function save(Saln $saln, array $statement, SalnOathMode $mode, ?string $officerName, ?string $officerTitle, User $by): void
    {
        $this->expect(
            $mode !== SalnOathMode::OtherOfficer || (filled($officerName) && filled($officerTitle)),
            "Enter the administering officer's name and position.",
        );
        $validated = SalnStatement::validate($statement, $saln->reckoning_date);

        DB::transaction(function () use ($saln, $validated, $mode, $officerName, $officerTitle, $by) {
            $fresh = $this->locked($saln);
            $this->expect($fresh->status->isEditable(), 'This SALN is with HRD and can no longer be edited.');
            $this->expect(
                $mode !== SalnOathMode::RegionalDirector || ! $this->headsTheOrd($fresh->employee),
                'You cannot administer your own oath. Choose a notary or another administering officer.',
            );

            $fresh->forceFill([
                'statement' => $validated,
                'oath_mode' => $mode,
                'officer_name' => $mode === SalnOathMode::OtherOfficer ? trim($officerName) : null,
                'officer_title' => $mode === SalnOathMode::OtherOfficer ? trim($officerTitle) : null,
                'updated_by' => $by->id,
            ]);

            if ($fresh->status === SalnStatus::Generated && $this->differsFromPrintout($fresh)) {
                $fresh->status = SalnStatus::Draft;
            }

            $fresh->save();

            if ($fresh->employee->user_id !== $by->id) {
                $this->audit->recordFor($by, AuditEvent::SalnUpdated, "Encoded the SALN {$fresh->reference()} on behalf of ".($fresh->employee->user?->name ?? 'an employee'), $fresh);
            }

            $this->sync($saln, $fresh);
        });
    }

    public function generate(Saln $saln, User $by): SalnVersion
    {
        return DB::transaction(function () use ($saln, $by) {
            $fresh = $this->locked($saln);
            $this->expect($fresh->status->isEditable(), 'This SALN is with HRD and can no longer be printed again.');
            $this->expect(filled($fresh->statement['government_id']['number'] ?? null), 'Save the SALN before printing it.');
            $this->expect(
                $fresh->oath_mode !== SalnOathMode::RegionalDirector || ! $this->headsTheOrd($fresh->employee),
                'You cannot administer your own oath. Choose a notary or another administering officer, save, and print again.',
            );
            [$name, $title] = $this->officer($fresh);

            $version = new SalnVersion;
            $version->forceFill([
                'saln_id' => $fresh->id,
                'version' => $fresh->current_version + 1,
                'reference' => $fresh->reference(),
                'statement' => SalnStatement::withTotals($fresh->statement),
                'oath_mode' => $fresh->oath_mode,
                'officer_name' => $name,
                'officer_title' => $title,
                'generated_by' => $by->id,
            ])->save();

            $fresh->forceFill(['current_version' => $version->version, 'status' => SalnStatus::Generated, 'updated_by' => $by->id])->save();
            $this->event($fresh, 'generated', $by, $version->version);
            $this->audit->recordFor($by, AuditEvent::SalnGenerated, "Printed {$version->printedReference()}", $fresh, ['version' => $version->version]);
            $this->sync($saln, $fresh);

            return $version;
        });
    }

    public function receive(Saln $saln, int $version, User $by): void
    {
        DB::transaction(function () use ($saln, $version, $by) {
            $fresh = $this->locked($saln);
            $this->expect($fresh->status === SalnStatus::Generated, 'Only a printed SALN awaiting HRD can be received. If it was changed after printing, it has to be printed again.');
            $this->expect($version === $fresh->current_version, "Only the latest printout, {$fresh->reference()} v{$fresh->current_version}, can be received.");

            $fresh->forceFill(['status' => SalnStatus::Received, 'received_version' => $version])->save();
            $this->event($fresh, 'received', $by, $version);
            $this->audit->recordFor($by, AuditEvent::SalnReceived, "Received the hard copy of {$fresh->reference()} v{$version}", $fresh);
            $this->sync($saln, $fresh);
        });
    }

    public function decide(Saln $saln, bool $clear, ?string $remarks, User $by): void
    {
        $remarks = trim((string) $remarks);
        $this->expect($clear || $remarks !== '', 'Say what needs correcting.');
        $override = ! $by->hasPermission(PermissionName::SalnReview->value);

        $fresh = DB::transaction(function () use ($saln, $clear, $remarks, $override, $by) {
            $fresh = $this->locked($saln);
            $this->expect($fresh->status === SalnStatus::Received, 'Only a SALN HRD has received can be cleared or returned. It may have been decided already.');
            $this->expect($fresh->employee->user_id !== $by->id, 'Nobody clears or returns their own SALN.');

            $fresh->forceFill(['status' => $clear ? SalnStatus::Cleared : SalnStatus::Returned])->save();
            $this->event($fresh, $clear ? 'cleared' : 'returned', $by, $fresh->received_version, $remarks ?: null, $override);
            $this->audit->recordFor(
                $by,
                $clear ? AuditEvent::SalnCleared : AuditEvent::SalnReturned,
                ($clear ? 'Cleared ' : 'Returned ').$fresh->reference().($override ? ' (HRD override)' : ''),
                $fresh,
                ['override' => $override],
            );
            $this->sync($saln, $fresh);

            return $fresh;
        });

        $fresh->employee->user?->notify(new HrUpdate(
            $clear ? 'SALN cleared' : 'SALN returned for correction',
            $clear
                ? "Your SALN ({$fresh->reference()}) is cleared. Sign it before the administering officer, then upload the signed copy."
                : "Your SALN ({$fresh->reference()}) was returned for correction. Open it to see the Committee's remarks.",
            url("/my/saln/{$fresh->id}"),
        ));
    }

    public function file(Saln $saln, UploadedFile $scan, User $by): EmployeeDocument
    {
        return DB::transaction(function () use ($saln, $scan, $by) {
            $fresh = $this->locked($saln);
            $this->expect($fresh->status === SalnStatus::Cleared, 'Only a cleared SALN can be filed. It may have been filed already.');

            $date = $fresh->type === SalnType::Annual ? $fresh->reckoning_date->format('Y') : $fresh->reckoning_date->toDateString();
            $document = $this->documents->store($fresh->employee, $scan, [
                'document_type' => EmployeeDocumentType::Saln,
                'title' => "SALN — {$fresh->type->label()} {$date}",
                'document_date' => $fresh->reckoning_date->toDateString(),
            ], $by);

            $fresh->forceFill(['status' => SalnStatus::Filed, 'employee_document_id' => $document->id])->save();
            $this->event($fresh, 'filed', $by, $fresh->current_version);
            $this->audit->recordFor($by, AuditEvent::SalnFiled, "Filed the signed {$fresh->reference()} in the 201 File", $fresh, ['document_id' => $document->id]);
            $this->sync($saln, $fresh);

            return $document;
        });
    }

    public function exempt(Employee $employee, int $year, string $reason, User $by): SalnExemption
    {
        $this->expect($employee->user_id !== $by->id, 'Nobody exempts themselves from filing.');

        $exemption = SalnExemption::query()->where('employee_id', $employee->id)->where('year', $year)->first() ?? new SalnExemption;
        $exemption->forceFill(['employee_id' => $employee->id, 'year' => $year, 'reason' => trim($reason), 'created_by' => $by->id])->save();

        $this->audit->recordFor($by, AuditEvent::SalnExempted, "Exempted {$employee->label} from filing a SALN for {$year}", $employee, ['year' => $year, 'reason' => $exemption->reason]);

        return $exemption;
    }

    /**
     * The SALN as it is now, locked for the rest of the transaction, so two
     * people acting at once can't both pass the same check.
     */
    private function locked(Saln $saln): Saln
    {
        return Saln::query()->lockForUpdate()->with('employee.user')->findOrFail($saln->id);
    }

    /**
     * Brings the caller's copy up to date after a change.
     */
    private function sync(Saln $saln, Saln $fresh): void
    {
        $saln->setRawAttributes($fresh->getAttributes(), true);
        $saln->unsetRelation('versions');
        $saln->unsetRelation('events');
    }

    /**
     * Whether the entries or the oath differ from the latest printout.
     */
    private function differsFromPrintout(Saln $saln): bool
    {
        $printed = $saln->latestVersion();
        if (! $printed) {
            return true;
        }

        $without = fn (array $statement) => collect($statement)->except('totals')->all();

        return $without($printed->statement) != $without($saln->statement)
            || $printed->oath_mode !== $saln->oath_mode
            || ($saln->oath_mode === SalnOathMode::OtherOfficer && ($printed->officer_name !== $saln->officer_name || $printed->officer_title !== $saln->officer_title));
    }

    /**
     * Whether the employee is the one who would administer the oath: the
     * head of the Office of the Regional Director today, an OIC included.
     */
    public function headsTheOrd(Employee $employee): bool
    {
        $head = $this->ordHead();

        return $head !== null && $employee->user_id === $head['user']->id;
    }

    /**
     * @return ?array{user: User, capacity: ?HeadCapacity}
     */
    private function ordHead(): ?array
    {
        $ord = OrganizationalUnit::query()->where('code', self::ORD_CODE)->value('id');

        // A fresh look each time: OfficeHeads remembers what it found, and a
        // designation can start between saving and printing.
        return $ord ? (app(OfficeHeads::class)->all()[$ord] ?? null) : null;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function officer(Saln $saln): array
    {
        return match ($saln->oath_mode) {
            SalnOathMode::Notary => [null, null],
            SalnOathMode::OtherOfficer => [$saln->officer_name, $saln->officer_title],
            SalnOathMode::RegionalDirector => ($head = $this->ordHead())
                ? [$head['user']->name, $head['capacity'] ? $head['capacity']->label().', Office of the Regional Director' : 'Regional Director']
                : [null, null],
        };
    }

    private function event(Saln $saln, string $action, User $by, ?int $version, ?string $remarks = null, bool $override = false): void
    {
        $saln->events()->forceCreate([
            'action' => $action,
            'version' => $version,
            'remarks' => $remarks,
            'override' => $override,
            'user_id' => $by->id,
        ]);
    }

    private function expect(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new InvalidArgumentException($message);
        }
    }
}
