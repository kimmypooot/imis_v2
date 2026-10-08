<?php

namespace App\Console\Commands;

use App\Enums\AuditEvent;
use App\Enums\LdApprovalStatus;
use App\Enums\LdType;
use App\Models\Employee;
use App\Models\Pds\PdsLearningDevelopment;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\HR\LearningDevelopment\LdRecords;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * HR-3c: carries ldms's trainings (`ld_cscro8`) into the L&D log.
 *
 * People are matched by their legacy account id (`users.legacy_id`, set by
 * `imis:import-legacy`), and only someone with an employee record can
 * receive trainings, so run that import and HR's "Set up records for
 * staff" first. Anyone who can't be matched is reported, never guessed.
 *
 * Re-running is safe: each record keeps its ldms id (`legacy_id`), and
 * one already imported is skipped, so HR's later corrections survive.
 * A training the person already logged here (same title and start date)
 * is skipped too. Modality comes in as "not recorded": ldms's column
 * default made it unreliable. Removed ldms records stay removed
 * (soft-deleted).
 */
class ImportLegacyLd extends Command
{
    protected $signature = 'imis:import-legacy-ld
        {--connection= : Database connection holding ld_cscro8 (default: the legacy connection)}
        {--dry-run : Show what would be imported without saving anything}';

    protected $description = 'Import Learning & Development records from the legacy ldms (ld_cscro8)';

    /** @var array<string, int> */
    private array $counts = ['imported' => 0, 'already_imported' => 0, 'duplicate' => 0, 'removed' => 0, 'no_account' => 0, 'no_employee_record' => 0, 'incomplete' => 0];

    public function handle(LdRecords $records, AuditLogger $audit): int
    {
        $legacy = DB::connection($this->option('connection') ?: config('legacy.connection'));
        $dryRun = (bool) $this->option('dry-run');

        try {
            $rows = $legacy->table('ld_cscro8')->orderBy('id')->get();
        } catch (Throwable $e) {
            $this->error('Could not read ld_cscro8: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! User::query()->whereNotNull('legacy_id')->exists()) {
            $this->error('No account has a legacy id yet. Run `php artisan imis:import-legacy` first.');

            return self::FAILURE;
        }

        $users = User::query()->whereNotNull('legacy_id')->with('employee')->get()->keyBy('legacy_id');
        $reviewers = $users->map(fn (User $user) => $user->id);
        $imported = PdsLearningDevelopment::withTrashed()->whereIn('legacy_id', $rows->pluck('id'))->pluck('legacy_id')->flip();
        /** @var array<int, int> $unmatched legacy user id => trainings */
        $unmatched = [];
        /** @var array<string, int> $withoutRecord username => trainings */
        $withoutRecord = [];

        DB::beginTransaction();

        try {
            foreach ($rows as $row) {
                if ($imported->has($row->id)) {
                    $this->counts['already_imported']++;

                    continue;
                }

                $user = $users->get((int) $row->user_id);

                if (! $user) {
                    $unmatched[(int) $row->user_id] = ($unmatched[(int) $row->user_id] ?? 0) + 1;
                    $this->counts['no_account']++;

                    continue;
                }

                if (! $user->employee) {
                    $withoutRecord[$user->username] = ($withoutRecord[$user->username] ?? 0) + 1;
                    $this->counts['no_employee_record']++;

                    continue;
                }

                $start = $this->date($row->start_date);
                $title = trim((string) $row->training_name);

                // The log needs a title and a start date; ldms didn't always insist.
                if ($start === null || $title === '') {
                    $this->counts['incomplete']++;

                    continue;
                }

                if ($records->hasDuplicate($user->employee, $title, (string) $start)) {
                    $this->counts['duplicate']++;

                    continue;
                }

                $record = $records->import($user->employee, $this->attributes($row, $title, $start, $reviewers->all()));
                $this->counts['imported']++;

                if ($record->trashed()) {
                    $this->counts['removed']++;
                }
            }

            if ($dryRun) {
                DB::rollBack();
            } else {
                if ($this->counts['imported'] > 0) {
                    $audit->recordFor(null, AuditEvent::LdRecordsImported, "Imported {$this->counts['imported']} L&D records from ldms", null, $this->counts);
                }

                DB::commit();
            }
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        $this->report($dryRun, $unmatched, $withoutRecord);

        return self::SUCCESS;
    }

    /**
     * @param  array<int, int>  $reviewers  legacy user id => user id
     * @return array<string, mixed>
     */
    private function attributes(object $row, string $title, string $start, array $reviewers): array
    {
        [$type, $other] = LdType::fromText($row->training_type);
        $status = LdApprovalStatus::tryFrom(mb_strtolower(trim((string) $row->approval_status))) ?? LdApprovalStatus::Pending;
        $end = $this->date($row->end_date);
        $created = $this->manila($row->created_at) ?? now();
        $reviewer = $reviewers[(int) $row->approved_by] ?? null;

        return [
            'title' => mb_substr($title, 0, 512),
            'date_from' => $start,
            // ldms allowed an end before the start; the log doesn't.
            'date_to' => $end && $end >= $start ? $end : $start,
            'hours' => round((float) ($row->training_hrs ?? 0), 2),
            'ld_type' => $type,
            'ld_type_other' => $other,
            'modality' => null,
            'sponsor' => $this->text($row->training_provider, 512),
            'venue' => $this->text($row->training_venue, 255),
            'resource_speaker' => $this->text($row->resource_speaker, 512),
            'approval_status' => $status,
            'reviewed_by' => $status === LdApprovalStatus::Pending ? null : $reviewer,
            'reviewed_at' => $status === LdApprovalStatus::Pending || ! $reviewer ? null : ($this->manila($row->updated_at) ?? $created),
            'created_by' => null,
            'legacy_id' => (int) $row->id,
            'otrs_training_id' => ((int) $row->otrs_training_id) ?: null,
            'created_at' => $created,
            'updated_at' => $this->manila($row->updated_at) ?? $created,
            'deleted_at' => $this->manila($row->deleted_at),
        ];
    }

    /**
     * @param  array<int, int>  $unmatched
     * @param  array<string, int>  $withoutRecord
     */
    private function report(bool $dryRun, array $unmatched, array $withoutRecord): void
    {
        $this->info($dryRun ? 'Dry run: nothing was saved.' : 'Import finished.');

        $this->table(['Result', 'Records'], [
            [$dryRun ? 'Would be imported' : 'Imported', $this->counts['imported']],
            ['  of which removed in ldms (kept removed)', $this->counts['removed']],
            ['Already imported (skipped)', $this->counts['already_imported']],
            ['Already in the log here (skipped)', $this->counts['duplicate']],
            ['No matching account (skipped)', $this->counts['no_account']],
            ['No employee record yet (skipped)', $this->counts['no_employee_record']],
            ['No title or start date (skipped)', $this->counts['incomplete']],
        ]);

        if ($withoutRecord) {
            $this->warn('These accounts have no employee record; run "Set up records for staff", then import again:');
            foreach ($withoutRecord as $username => $count) {
                $this->line("  {$username} ({$count})");
            }
        }

        if ($unmatched) {
            $this->warn('No account here has these legacy user ids (re-run imis:import-legacy?):');
            foreach ($unmatched as $legacyId => $count) {
                $this->line("  #{$legacyId} ({$count})");
            }
        }
    }

    private function date(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || str_starts_with($value, '0000') ? null : substr($value, 0, 10);
    }

    /** Legacy DATETIMEs are Asia/Manila wall-clock time (as in LegacyImporter). */
    private function manila(mixed $value): ?Carbon
    {
        $value = trim((string) $value);

        return $value === '' || str_starts_with($value, '0000') ? null : Carbon::parse($value, 'Asia/Manila')->setTimezone(config('app.timezone'));
    }

    private function text(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
