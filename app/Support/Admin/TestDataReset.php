<?php

namespace App\Support\Admin;

use App\Support\PermissionCache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Development only: deletes test activity and keeps the people. A Super
 * Administrator ticks the categories to delete (System Settings → Danger
 * zone); everything not listed here is never touched:
 *
 * - kept: accounts and their pictures, employee records, the PDS (but
 *   its training records, which are a category), service history and the
 *   whole plantilla (step increments included: they are real records,
 *   and a test one is removed on its item page), e-signatures, loans and
 *   pay items, and all setup (offices, positions, salary schedules, leave
 *   types, roles and permissions, systems, settings, What's New).
 *
 * Rows are deleted children first with foreign keys on, in one
 * transaction; links from kept tables are SET NULL, so nothing is left
 * pointing at a deleted row. Files the deleted rows pointed to are removed
 * from the private disk afterwards, except any still used by a kept record
 * (a profile or PDS photo, a saved signature, a brand asset).
 */
class TestDataReset
{
    /** The private disk HR files are stored on. */
    private const DISK = 'local';

    /**
     * Each category: what it is, its tables (children first) and the
     * columns holding file paths.
     *
     * @var array<string, array{label: string, description: string, tables: array<int, string>, files?: array<string, array<int, string>>}>
     */
    public const CATEGORIES = [
        'salary_notices' => [
            'label' => 'NOSA & NOSI',
            'description' => 'Generated Notices of Salary Adjustment and of Step Increment.',
            'tables' => ['salary_notices'],
        ],
        'saln' => [
            'label' => 'SALN',
            'description' => 'Statements, their versions and history, and exemptions.',
            'tables' => ['saln_events', 'saln_versions', 'salns', 'saln_exemptions'],
        ],
        'leave_applications' => [
            'label' => 'Leave applications and approvals',
            'description' => 'Applications with their approval steps and attachments, monetizations, OIC designations and approval delegations.',
            'tables' => ['leave_application_steps', 'leave_applications', 'leave_monetizations', 'leave_designations', 'approval_delegations'],
            'files' => [
                'leave_application_steps' => ['signature_path'],
                'leave_applications' => ['signature_path', 'clearance_path', 'attachment_path'],
            ],
        ],
        'leave_credits' => [
            'label' => 'Leave cards and leave credits',
            'description' => 'The credit ledger, the monthly leave card and tardiness charges. Re-import them from the Excel leave cards.',
            'tables' => ['dtr_leave_deductions', 'leave_ledger_entries', 'leave_card_months'],
        ],
        'flexible_work' => [
            'label' => 'Flexible work',
            'description' => 'Flexible work arrangements and their weekly reports.',
            'tables' => ['fwa_reports', 'fwa_arrangements'],
        ],
        'dtr' => [
            'label' => 'Biometric punches and DTR',
            'description' => 'Imported biometric logs, DTR adjustments, chosen signatories and signed DTRs.',
            'tables' => ['dtr_adjustments', 'dtr_signatories', 'dtr_signatures', 'biometric_punches', 'biometric_imports'],
            'files' => ['dtr_signatures' => ['signature_path']],
        ],
        'payroll' => [
            'label' => 'Payroll runs and payslips',
            'description' => 'Monthly payrolls and payslip requests. Loans and pay items stay.',
            'tables' => ['payslip_requests', 'payroll_lines', 'payroll_entries', 'payroll_runs'],
        ],
        'training' => [
            'label' => 'Training records (L&D)',
            'description' => 'Learning & Development records and their certificates (PDS Section VI).',
            'tables' => ['pds_learning_developments'],
            'files' => ['pds_learning_developments' => ['certificate_path']],
        ],
        'documents' => [
            'label' => '201 File documents',
            'description' => 'Every uploaded 201 document and its file.',
            'tables' => ['employee_documents'],
            'files' => ['employee_documents' => ['file_path']],
        ],
        'pds_submissions' => [
            'label' => 'PDS submissions and record corrections',
            'description' => 'Signed PDS submissions (verified history) and data-correction requests. The PDS itself stays.',
            'tables' => ['pds_submissions', 'data_correction_requests'],
            'files' => ['pds_submissions' => ['photo_path']],
        ],
        'selections' => [
            'label' => 'Plantilla selections',
            'description' => 'Recorded selections for vacant items.',
            'tables' => ['plantilla_selections'],
        ],
        'portal' => [
            'label' => 'Announcements, feedback and notifications',
            'description' => 'Portal announcements, feedback with replies, bell notifications and push subscriptions.',
            'tables' => ['announcements', 'feedback_replies', 'feedback', 'notifications', 'push_subscriptions'],
        ],
        'logs' => [
            'label' => 'Logs and dashboard snapshots',
            'description' => 'Audit trail, login history, system access logs and Executive Dashboard snapshots. The reset itself is logged afterwards.',
            'tables' => ['audit_logs', 'login_histories', 'system_access_logs', 'executive_snapshots'],
        ],
        'sessions' => [
            'label' => 'Sessions, tokens, cache and queue',
            'description' => 'Everyone else is signed out (you stay signed in); SSO and password-reset tokens, the cache, queued and failed jobs, and paused scheduled tasks.',
            'tables' => ['sessions', 'sso_tokens', 'sso_sessions', 'password_reset_tokens', 'cache_locks', 'cache', 'job_batches', 'jobs', 'failed_jobs', 'scheduled_task_pauses'],
        ],
    ];

    /** File columns of records that are always kept. */
    private const KEPT_FILES = [
        'users' => ['profile_photo_path', 'signature_path'],
        'pds_profiles' => ['photo_path'],
        'brand_assets' => ['path'],
    ];

    /**
     * The checklist: each category with how many rows it would delete now.
     *
     * @return array<int, array{key: string, label: string, description: string, count: int}>
     */
    public function checklist(): array
    {
        return collect(self::CATEGORIES)->map(fn (array $category, string $key) => [
            'key' => $key,
            'label' => $category['label'],
            'description' => $category['description'],
            'count' => $this->count($key),
        ])->values()->all();
    }

    /**
     * Deletes the chosen categories.
     *
     * @param  array<int, string>  $keys
     * @return array{rows: array<string, int>, files: int}
     */
    public function reset(array $keys, ?string $keepSessionId = null): array
    {
        $keys = array_values(array_intersect(array_keys(self::CATEGORIES), $keys));
        $files = $this->filesOf($keys);
        $rows = [];

        DB::transaction(function () use ($keys, $keepSessionId, &$rows) {
            foreach ($keys as $key) {
                $rows[$key] = $this->deleteTables($key, $keepSessionId);
            }
        });

        $kept = $this->keptFiles();
        $deleted = 0;

        foreach ($files->diff($kept)->unique() as $path) {
            if (Storage::disk(self::DISK)->exists($path) && Storage::disk(self::DISK)->delete($path)) {
                $deleted++;
            }
        }

        PermissionCache::flush();

        return ['rows' => $rows, 'files' => $deleted];
    }

    private function count(string $key): int
    {
        return collect(self::CATEGORIES[$key]['tables'])
            ->filter(fn (string $table) => Schema::hasTable($table))
            ->sum(fn (string $table) => DB::table($table)->count());
    }

    private function deleteTables(string $key, ?string $keepSessionId): int
    {
        $deleted = 0;

        foreach (self::CATEGORIES[$key]['tables'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $query = DB::table($table);
            if ($table === 'sessions' && $keepSessionId !== null) {
                $query->where('id', '!=', $keepSessionId);
            }

            $deleted += $query->delete();
        }

        return $deleted;
    }

    /**
     * The files the chosen categories' rows point to.
     *
     * @param  array<int, string>  $keys
     * @return Collection<int, string>
     */
    private function filesOf(array $keys): Collection
    {
        return collect($keys)->flatMap(fn (string $key) => collect(self::CATEGORIES[$key]['files'] ?? [])
            ->flatMap(fn (array $columns, string $table) => $this->paths($table, $columns)));
    }

    /**
     * @return Collection<int, string>
     */
    private function keptFiles(): Collection
    {
        return collect(self::KEPT_FILES)->flatMap(fn (array $columns, string $table) => $this->paths($table, $columns));
    }

    /**
     * @param  array<int, string>  $columns
     * @return Collection<int, string>
     */
    private function paths(string $table, array $columns): Collection
    {
        if (! Schema::hasTable($table)) {
            return collect();
        }

        $columns = array_values(array_filter($columns, fn (string $column) => Schema::hasColumn($table, $column)));

        return collect($columns)->flatMap(fn (string $column) => DB::table($table)->whereNotNull($column)->pluck($column))
            ->filter(fn ($path) => is_string($path) && $path !== '')->values();
    }
}
