<?php

namespace App\Support\Legacy;

use App\Enums\EmploymentStatus;
use App\Enums\HolidayType;
use App\Enums\LeaveApplicationStatus;
use App\Enums\LeaveStepAction;
use App\Enums\LedgerEntryKind;
use App\Models\Employee;
use App\Models\FwaArrangement;
use App\Models\FwaReport;
use App\Models\Holiday;
use App\Models\LeaveApplication;
use App\Models\LeaveDesignation;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveMonetization;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * HR-7: carries the legacy LMS (`old-imis/leave`, the current `lms_*`
 * tables) into HR-6. The legacy tables exist only on the live server, so
 * the column names here are the ones the legacy code itself reads and
 * writes (its services and APIs); a part whose table is missing is
 * skipped and reported, never guessed.
 *
 * - People are matched by `users.legacy_id` (imis:import-legacy); only
 *   someone with an employee record is imported. Leave types by code.
 * - Each record keeps its legacy id, so re-running skips what's in.
 * - The credit ledger is imported as it stands (legacy already posted
 *   the deductions of approved leave), so balances carry over exactly.
 * - Decided applications are imported with their approval history;
 *   applications still open in legacy are reported, to be finished there
 *   or re-filed — they can't join a workflow mid-way.
 * - Attachments (medical certificates) are not copied; their count is
 *   reported. Legacy reference numbers keep an "LMS-" prefix so they
 *   never collide with new ones.
 * - Legacy "SPL" was its solo-parent leave; its entries are imported as
 *   SPL and counted, for HR to review (decided 2026-10-03).
 */
class LegacyLeaveImporter
{
    /** @var array<string, int> */
    private array $counts = [];

    /** @var array<string, array<int, string>> */
    private array $notes = [];

    /** @var array<int, int> legacy user id => user id */
    private array $users = [];

    /** @var array<int, Employee> legacy user id => employee */
    private array $employees = [];

    /** @var array<int, ?LeaveType> legacy leave type id => type */
    private array $types = [];

    /** @var array<int, int> legacy application id => application id */
    private array $applications = [];

    /** @var array<int, int> legacy arrangement id => arrangement id */
    private array $arrangements = [];

    public function __construct(private readonly ConnectionInterface $legacy) {}

    /**
     * @return array{counts: array<string, int>, notes: array<string, array<int, string>>}
     */
    public function run(bool $dryRun = false): array
    {
        $this->loadPeople();
        $this->loadTypes();

        DB::beginTransaction();

        try {
            LegacyBridge::withoutSyncing(function () {
                $this->importProfiles();
                $this->importHolidays();
                $this->importDesignations();
                $this->importLedger();
                $this->importApplications();
                $this->importArrangements();
                $this->importReports();
                $this->importMonetizations();
            });

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return ['counts' => $this->counts, 'notes' => $this->notes];
    }

    // ── Parts ────────────────────────────────────────────────────────

    /**
     * lms_employee_profiles → leave settings; and, where HR left them
     * blank, the employment status and first day in service.
     */
    private function importProfiles(): void
    {
        foreach ($this->rows('lms_employee_profiles', 'profiles') as $row) {
            if (! (int) ($row->is_active ?? 1) || ! ($employee = $this->employee($row->user_id ?? null, 'profiles'))) {
                continue;
            }

            $updates = array_filter([
                'immediate_supervisor_id' => $employee->immediate_supervisor_id ? null : $this->user($row->immediate_supervisor_id ?? null),
                'employment_status' => $employee->employment_status ? null : EmploymentStatus::tryFrom(strtolower(trim((string) ($row->appointment_type ?? '')))),
                'original_appointment_date' => $employee->original_appointment_date ? null : $this->date($row->first_day_in_service ?? null),
            ], fn ($value) => $value !== null);

            if ((int) ($row->is_solo_parent ?? 0) && ! $employee->is_solo_parent) {
                $updates += [
                    'is_solo_parent' => true,
                    'solo_parent_id_number' => $this->text($row->solo_parent_id_number ?? null, 50),
                    'solo_parent_id_expiry' => $this->date($row->solo_parent_id_expiry ?? null),
                ];
            }

            if ($updates !== []) {
                $employee->forceFill($updates)->save();
                $this->count('profiles updated');
            }
        }
    }

    private function importHolidays(): void
    {
        foreach ($this->rows('lms_holidays_v2', 'holidays') as $row) {
            if (! (int) ($row->is_active ?? 1) || $this->exists(Holiday::class, $row->id)) {
                $this->skip('holidays');

                continue;
            }

            $date = $this->date($row->holiday_date ?? null);
            $name = $this->text($row->holiday_name ?? $row->name ?? null, 255);
            if (! $date || ! $name || Holiday::query()->whereDate('date', $date)->where('name', $name)->exists()) {
                $this->count('holidays skipped (incomplete or already here)');

                continue;
            }

            $type = match (strtolower((string) ($row->type ?? $row->holiday_type ?? ''))) {
                'regular' => HolidayType::Regular,
                'special_working' => HolidayType::SpecialWorking,
                default => HolidayType::SpecialNonWorking,
            };

            $holiday = new Holiday(['date' => $date, 'name' => $name, 'type' => $type]);
            $holiday->forceFill(['legacy_id' => $row->id])->save();
            $this->count('holidays');
        }
    }

    /** lms_agency_designations → officers-in-charge for the Head. */
    private function importDesignations(): void
    {
        foreach ($this->rows('lms_agency_designations', 'designations') as $row) {
            if (! (int) ($row->is_active ?? 1) || $this->exists(LeaveDesignation::class, $row->id)) {
                $this->skip('designations');

                continue;
            }

            $designee = $this->user($row->designee_id ?? null);
            $from = $this->date($row->effective_from ?? null);
            $to = $this->date($row->effective_to ?? null);
            if (! $designee || ! $from || ! $to) {
                $this->count('designations skipped (unknown person or dates)');

                continue;
            }

            $designation = new LeaveDesignation(['designee_id' => $designee, 'acting_for_id' => $this->user($row->designated_by ?? null), 'date_from' => $from, 'date_to' => $to]);
            $designation->forceFill(['legacy_id' => $row->id])->save();
            $this->count('designations');
        }
    }

    /** lms_credit_ledger → the credit ledger, entry for entry. */
    private function importLedger(): void
    {
        foreach ($this->rows('lms_credit_ledger', 'ledger') as $row) {
            if ($this->exists(LeaveLedgerEntry::class, $row->id)) {
                $this->count('ledger already imported');

                continue;
            }

            $employee = $this->employee($row->user_id ?? null, 'ledger');
            $type = $this->type($row->leave_type_id ?? null, 'ledger');
            $date = $this->date($row->entry_date ?? null);
            if (! $employee || ! $type || ! $date) {
                continue;
            }

            $kind = match (strtolower((string) ($row->transaction_type ?? ''))) {
                'accrual' => LedgerEntryKind::Accrual,
                'grant' => LedgerEntryKind::Grant,
                'deduction' => LedgerEntryKind::Deduction,
                'monetization' => LedgerEntryKind::Monetization,
                'opening', 'balance_forward', 'forward' => LedgerEntryKind::Opening,
                'lapse', 'forfeiture' => LedgerEntryKind::Lapse,
                default => LedgerEntryKind::Adjustment,
            };
            $periodic = in_array($kind, [LedgerEntryKind::Accrual, LedgerEntryKind::Grant], true);

            $entry = new LeaveLedgerEntry;
            $entry->forceFill([
                'legacy_id' => $row->id,
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'entry_date' => $date,
                'kind' => $kind,
                'amount' => round((float) ($row->amount ?? 0), 3),
                'particulars' => $this->text($row->particulars ?? null, 480) ?? 'Carried over from the legacy LMS',
                'period_year' => $periodic ? ((int) ($row->entry_year ?? 0) ?: Carbon::parse($date)->year) : null,
                'period_month' => $kind === LedgerEntryKind::Accrual ? ((int) ($row->entry_month ?? 0) ?: Carbon::parse($date)->month) : null,
                'recorded_by' => $this->user($row->performed_by ?? null),
            ])->save();

            $this->count('ledger entries');
            if ($type->code === 'SPL') {
                $this->count('SPL ledger entries to review (legacy SPL was the solo-parent leave)');
            }
        }
    }

    /** lms_applications (decided ones) with their lms_workflow_steps. */
    private function importApplications(): void
    {
        $steps = $this->rows('lms_workflow_steps', null)->groupBy('application_id');

        foreach ($this->rows('lms_applications', 'applications') as $row) {
            if ($existing = LeaveApplication::query()->where('legacy_id', $row->id)->value('id')) {
                $this->applications[(int) $row->id] = $existing;
                $this->count('applications already imported');

                continue;
            }

            $status = match (strtolower((string) ($row->status ?? ''))) {
                'approved' => LeaveApplicationStatus::Approved,
                'rejected', 'disapproved' => LeaveApplicationStatus::Disapproved,
                'cancelled', 'withdrawn' => LeaveApplicationStatus::Cancelled,
                default => null,
            };
            if (! $status) {
                $this->count('applications still open in legacy (finish there or re-file)');
                $this->note('open applications', (string) ($row->reference_number ?? "#{$row->id}"));

                continue;
            }

            $employee = $this->employee($row->user_id ?? null, 'applications');
            $type = $this->type($row->leave_type_id ?? null, 'applications');
            $from = $this->date($row->date_from ?? null);
            if (! $employee || ! $type || ! $from) {
                continue;
            }

            $details = collect([$row->details_of_leave ?? null, $row->location ?? null, $row->hospital_physician ?? null, $row->others_specify ?? null])
                ->map(fn ($value) => trim((string) $value))->filter()->implode('; ');

            $application = new LeaveApplication;
            $application->forceFill([
                'legacy_id' => $row->id,
                'reference_number' => mb_substr('LMS-'.($this->text($row->reference_number ?? null, 25) ?? $row->id), 0, 30),
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'date_from' => $from,
                'date_to' => $this->date($row->date_to ?? null) ?? $from,
                'is_half_day' => (bool) ($row->is_half_day ?? false),
                'half_day_period' => in_array($row->half_day_period ?? null, ['AM', 'PM'], true) ? $row->half_day_period : null,
                'days' => round((float) ($row->days_requested ?? 0), 3),
                'days_with_pay' => $status === LeaveApplicationStatus::Approved ? round((float) ($row->days_with_pay ?? 0), 3) : null,
                'days_without_pay' => $status === LeaveApplicationStatus::Approved ? round((float) ($row->days_without_pay ?? 0), 3) : null,
                'detail' => null,
                'detail_text' => $details !== '' ? mb_substr($details, 0, 500) : null,
                'commutation_requested' => ($row->commutation ?? '') === 'requested',
                'remarks' => $this->text($row->reason ?? null, 2000),
                'status' => $status,
                'submitted_at' => $this->datetime($row->created_at ?? null),
                'decided_at' => $this->datetime($row->final_action_at ?? null),
                'created_at' => $this->datetime($row->created_at ?? null) ?? now(),
                'updated_at' => $this->datetime($row->updated_at ?? null) ?? now(),
            ]);
            $application->timestamps = false;
            $application->save();

            $this->applications[(int) $row->id] = $application->id;
            $this->count('applications');
            if (! empty($row->attachment_path)) {
                $this->count('attachments not copied (kept on the legacy server)');
            }

            foreach ($steps->get($row->id, collect())->sortBy('step_number') as $step) {
                $action = LeaveStepAction::tryFrom((string) ($step->action ?? '')) ?? LeaveStepAction::Pending;
                if ($action === LeaveStepAction::Pending) {
                    continue;
                }

                $application->steps()->create([
                    'round' => 1,
                    'step' => (int) $step->step_number,
                    // Legacy parts: B supervisor, A HR, "C/D" the Head.
                    'part' => $part = in_array($step->form_part ?? '', ['A', 'B'], true) ? $step->form_part : 'C',
                    'role' => match ($part) {
                        'B' => 'supervisor', 'A' => 'hr', default => 'head'
                    },
                    'assigned_to' => $this->user($step->assigned_to ?? null),
                    'action' => $action,
                    'remarks' => $this->text($step->remarks ?? null, 1000),
                    'acted_by' => $this->user($step->assigned_to ?? null),
                    'acted_for' => $this->user($step->designated_for ?? null),
                    'acted_at' => $this->datetime($step->performed_at ?? null),
                ]);
            }
        }
    }

    private function importArrangements(): void
    {
        foreach ($this->rows('lms_fwa_arrangements', 'arrangements') as $row) {
            if ($existing = FwaArrangement::query()->where('legacy_id', $row->id)->value('id')) {
                $this->arrangements[(int) $row->id] = $existing;
                $this->count('arrangements already imported');

                continue;
            }

            $employee = $this->employee($row->user_id ?? null, 'arrangements');
            $from = $this->date($row->effective_from ?? null);
            $type = strtolower((string) ($row->fwa_type ?? ''));
            if (! $employee || ! $from || ! array_key_exists($type, FwaArrangement::TYPES)) {
                $this->count('arrangements skipped (unknown person, type or date)');

                continue;
            }

            $schedule = collect(json_decode((string) ($row->work_schedule ?? ''), true) ?: [])
                ->only(FwaArrangement::DAYS)->map(fn ($mode) => match (strtolower((string) $mode)) {
                    'off' => 'off', 'wfh', 'home' => 'wfh', default => 'office'
                })->all();

            $arrangement = new FwaArrangement;
            $arrangement->forceFill([
                'legacy_id' => $row->id,
                'employee_id' => $employee->id,
                'type' => $type,
                'effective_from' => $from,
                'effective_to' => $this->date($row->effective_to ?? null),
                'schedule' => $schedule ?: null,
                'daily_hours' => isset($row->compressed_hours) && $row->compressed_hours ? "{$row->compressed_hours} hours" : null,
                'remarks' => $this->text($row->remarks ?? null, 2000),
                'status' => in_array($row->status ?? '', ['pending', 'approved', 'rejected', 'cancelled', 'terminated'], true) ? $row->status : 'rejected',
                'decision_remarks' => $this->text($row->rejection_reason ?? null, 1000),
                'decided_by' => $this->user($row->approved_by ?? null),
                'decided_at' => $this->datetime($row->approved_at ?? null),
            ])->save();

            $this->arrangements[(int) $row->id] = $arrangement->id;
            $this->count('arrangements');
        }
    }

    private function importReports(): void
    {
        foreach ($this->rows('lms_fwa_far', 'weekly reports') as $row) {
            if ($this->exists(FwaReport::class, $row->id)) {
                $this->count('weekly reports already imported');

                continue;
            }

            $employee = $this->employee($row->user_id ?? null, 'weekly reports');
            $arrangement = $this->arrangements[(int) ($row->arrangement_id ?? 0)] ?? null;
            $start = $this->date($row->week_start ?? null);
            if (! $employee || ! $arrangement || ! $start || FwaReport::query()->where('employee_id', $employee->id)->whereDate('week_start', $start)->exists()) {
                $this->count('weekly reports skipped (unknown arrangement or week already here)');

                continue;
            }

            $report = new FwaReport;
            $report->forceFill([
                'legacy_id' => $row->id,
                'fwa_arrangement_id' => $arrangement,
                'employee_id' => $employee->id,
                'week_start' => $start,
                'week_end' => $this->date($row->week_end ?? null) ?? Carbon::parse($start)->addDays(4)->toDateString(),
                'targets' => (string) ($row->targets ?? ''),
                'accomplishments' => (string) ($row->accomplishments ?? ''),
                'remarks' => $this->text($row->remarks ?? null, 2000),
                'status' => ($row->status ?? '') === 'acknowledged' ? 'acknowledged' : 'submitted',
                'acknowledged_by' => $this->user($row->acknowledged_by ?? null),
                'acknowledged_at' => $this->datetime($row->acknowledged_at ?? null),
            ])->save();

            $this->count('weekly reports');
        }
    }

    private function importMonetizations(): void
    {
        foreach ($this->rows('lms_monetization_requests', 'monetizations') as $row) {
            if ($this->exists(LeaveMonetization::class, $row->id)) {
                $this->count('monetizations already imported');

                continue;
            }

            $employee = $this->employee($row->user_id ?? null, 'monetizations');
            $type = $this->type($row->leave_type_id ?? null, 'monetizations');
            if (! $employee || ! $type) {
                continue;
            }

            $terminal = in_array($type->code, ['TL'], true);
            $request = new LeaveMonetization;
            $request->forceFill([
                'legacy_id' => $row->id,
                'reference_number' => mb_substr('LMS-'.($this->text($row->reference_number ?? null, 25) ?? $row->id), 0, 30),
                'employee_id' => $employee->id,
                'kind' => $terminal ? 'terminal' : 'partial',
                // Legacy kept one figure; it's VL for monetization, VL + SL for terminal leave.
                'vl_days' => round((float) ($row->days_to_monetize ?? 0), 3),
                'sl_days' => 0,
                'vl_balance' => round((float) ($row->balance_before ?? 0), 3),
                'sl_balance' => 0,
                'monthly_salary' => round((float) ($row->monthly_salary ?? 0), 2),
                'amount' => round((float) ($row->computed_amount ?? 0), 2),
                'calendar_year' => (int) ($row->calendar_year ?? 0) ?: (int) substr((string) ($row->created_at ?? now()), 0, 4),
                'status' => in_array($row->status ?? '', ['pending', 'approved', 'rejected', 'released'], true) ? $row->status : 'rejected',
                'remarks' => $this->text($row->remarks ?? null, 2000),
                'decided_by' => $this->user($row->approved_by ?? null),
                'decided_at' => $this->datetime($row->approved_at ?? null),
                'released_at' => $this->datetime($row->released_at ?? null),
            ])->save();

            $this->count('monetizations');
        }
    }

    // ── Lookups ──────────────────────────────────────────────────────

    private function loadPeople(): void
    {
        $users = User::query()->whereNotNull('legacy_id')->with('employee')->get();
        foreach ($users as $user) {
            $this->users[(int) $user->legacy_id] = $user->id;
            if ($user->employee) {
                $this->employees[(int) $user->legacy_id] = $user->employee;
            }
        }
    }

    private function loadTypes(): void
    {
        $byCode = LeaveType::query()->get()->keyBy(fn (LeaveType $type) => strtoupper($type->code));

        foreach ($this->rows('lms_leave_types', 'leave types') as $row) {
            $code = strtoupper(trim((string) ($row->code ?? '')));
            $this->types[(int) $row->id] = $byCode[$code] ?? null;
            if (! isset($byCode[$code])) {
                $this->note('leave types with no match here (by code)', "{$code} — ".($row->name ?? ''));
            }
        }
    }

    private function employee(mixed $legacyUserId, string $part): ?Employee
    {
        $employee = $this->employees[(int) $legacyUserId] ?? null;
        if (! $employee) {
            $this->count("{$part} skipped (no employee record for the person)");
        }

        return $employee;
    }

    private function user(mixed $legacyUserId): ?int
    {
        return $this->users[(int) $legacyUserId] ?? null;
    }

    private function type(mixed $legacyTypeId, string $part): ?LeaveType
    {
        $type = $this->types[(int) $legacyTypeId] ?? null;
        if (! $type) {
            $this->count("{$part} skipped (leave type with no match here)");
        }

        return $type;
    }

    /**
     * @return Collection<int, object>
     */
    private function rows(string $table, ?string $part): Collection
    {
        if (! $this->legacy->getSchemaBuilder()->hasTable($table)) {
            if ($part) {
                $this->note('legacy tables not found (parts skipped)', "{$table} ({$part})");
            }

            return collect();
        }

        return $this->legacy->table($table)->orderBy('id')->get();
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function exists(string $model, mixed $legacyId): bool
    {
        return $model::query()->where('legacy_id', $legacyId)->exists();
    }

    private function skip(string $part): void
    {
        $this->count("{$part} skipped (inactive or already imported)");
    }

    private function count(string $key): void
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
    }

    private function note(string $key, string $value): void
    {
        $this->notes[$key][] = $value;
    }

    private function date(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || str_starts_with($value, '0000') ? null : substr($value, 0, 10);
    }

    /** Legacy DATETIMEs are Asia/Manila wall-clock time (as in LegacyImporter). */
    private function datetime(mixed $value): ?Carbon
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
