<?php

namespace App\Support\HR\Leave;

use App\Models\Employee;
use App\Models\FwaArrangement;
use App\Models\FwaReport;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * HR-6c: the only writer of flexible work arrangements and field activity
 * reports. Ports the legacy FwaService (CSC MC No. 06, s. 2022):
 *
 * - An employee asks for flexitime, a compressed work week, work from
 *   home, or a combination, from a date (open-ended or to a date), with
 *   a weekly schedule; no two pending or approved arrangements may
 *   overlap. HR approves or rejects (with a reason), and can end an
 *   approved one early. A pending one can be cancelled by the employee.
 * - Under an approved arrangement, the employee files a weekly report
 *   (Monday to Friday): targets and accomplishments. It can be edited
 *   until HR acknowledges it.
 * - An approved compressed work week's rest days aren't counted as leave
 *   days (WorkingDays).
 */
class FlexibleWork
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function arrangementRules(): array
    {
        return [
            'type' => ['required', Rule::in(array_keys(FwaArrangement::TYPES))],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'schedule' => ['nullable', 'array'],
            'schedule.*' => ['in:office,wfh,off'],
            'daily_hours' => ['nullable', 'string', 'max:50'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data  validated
     */
    public function apply(Employee $employee, array $data): FwaArrangement
    {
        $from = Carbon::parse($data['effective_from']);
        $to = isset($data['effective_to']) ? Carbon::parse($data['effective_to']) : null;

        $overlaps = FwaArrangement::query()->where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved'])
            ->whereDate('effective_from', '<=', $to ?? '9999-12-31')
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from))
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages(['effective_from' => 'You already have a pending or approved arrangement for part of that period.']);
        }

        $schedule = collect($data['schedule'] ?? [])->only(FwaArrangement::DAYS)->all();
        if ($data['type'] === 'cww' && ! in_array('off', $schedule, true)) {
            throw ValidationException::withMessages(['schedule' => 'A compressed work week needs a rest day in the schedule.']);
        }

        $arrangement = new FwaArrangement;
        $arrangement->forceFill([
            'employee_id' => $employee->id,
            'type' => $data['type'],
            'effective_from' => $from->toDateString(),
            'effective_to' => $to?->toDateString(),
            'schedule' => $schedule ?: null,
            'daily_hours' => $data['daily_hours'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'status' => 'pending',
        ])->save();

        return $arrangement;
    }

    public function cancel(FwaArrangement $arrangement): void
    {
        $this->assertStatus($arrangement, 'pending');
        $arrangement->forceFill(['status' => 'cancelled'])->save();
    }

    public function approve(FwaArrangement $arrangement, User $by, ?string $remarks): void
    {
        $this->assertStatus($arrangement, 'pending');
        $arrangement->forceFill(['status' => 'approved', 'decided_by' => $by->id, 'decided_at' => now(), 'decision_remarks' => $remarks])->save();
    }

    public function reject(FwaArrangement $arrangement, User $by, string $reason): void
    {
        $this->assertStatus($arrangement, 'pending');
        $arrangement->forceFill(['status' => 'rejected', 'decided_by' => $by->id, 'decided_at' => now(), 'decision_remarks' => $reason])->save();
    }

    /** Ends an approved arrangement early, on the given last day. */
    public function terminate(FwaArrangement $arrangement, Carbon $lastDay, ?string $remarks): void
    {
        $this->assertStatus($arrangement, 'approved');
        if ($lastDay->lt($arrangement->effective_from)) {
            throw ValidationException::withMessages(['effective_to' => 'The last day can\'t be before it started.']);
        }

        $arrangement->forceFill(['status' => 'terminated', 'effective_to' => $lastDay->toDateString(), 'decision_remarks' => $remarks ?? $arrangement->decision_remarks])->save();
    }

    // ── Weekly reports ───────────────────────────────────────────────

    /**
     * @return array<string, array<int, mixed>>
     */
    public function reportRules(): array
    {
        return [
            'week_start' => ['required', 'date'],
            'targets' => ['required', 'string', 'max:5000'],
            'accomplishments' => ['required', 'string', 'max:5000'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Files (or, until acknowledged, updates) the week's report.
     *
     * @param  array<string, mixed>  $data  validated
     */
    public function submitReport(Employee $employee, array $data): FwaReport
    {
        $monday = Carbon::parse($data['week_start'])->startOfWeek(Carbon::MONDAY);
        $friday = $monday->copy()->addDays(4);

        $arrangement = FwaArrangement::query()->where('employee_id', $employee->id)->inEffect($monday, $friday)->latest('effective_from')->first();
        if (! $arrangement) {
            throw ValidationException::withMessages(['week_start' => 'You have no approved flexible work arrangement that week.']);
        }

        $report = FwaReport::query()->where('employee_id', $employee->id)->whereDate('week_start', $monday)->first() ?? new FwaReport;
        if ($report->status === 'acknowledged') {
            throw ValidationException::withMessages(['week_start' => 'That week\'s report was already acknowledged.']);
        }

        $report->forceFill([
            'fwa_arrangement_id' => $arrangement->id,
            'employee_id' => $employee->id,
            'week_start' => $monday->toDateString(),
            'week_end' => $friday->toDateString(),
            'targets' => $data['targets'],
            'accomplishments' => $data['accomplishments'],
            'remarks' => $data['remarks'] ?? null,
            'status' => 'submitted',
        ])->save();

        return $report;
    }

    public function acknowledge(FwaReport $report, User $by, ?string $remarks): void
    {
        if ($report->status === 'acknowledged') {
            throw ValidationException::withMessages(['status' => 'Already acknowledged.']);
        }

        $report->forceFill(['status' => 'acknowledged', 'acknowledged_by' => $by->id, 'acknowledged_at' => now(), 'acknowledgement_remarks' => $remarks])->save();
    }

    // ── Reading ──────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function presentArrangement(FwaArrangement $arrangement): array
    {
        $arrangement->loadMissing(['employee.user', 'decider:id,name']);

        return [
            'id' => $arrangement->id,
            'employee_id' => $arrangement->employee_id,
            'name' => $arrangement->employee->user->name,
            'type' => $arrangement->type,
            'type_label' => $arrangement->typeLabel(),
            'effective_from' => $arrangement->effective_from->toDateString(),
            'effective_to' => $arrangement->effective_to?->toDateString(),
            'schedule' => $arrangement->schedule,
            'daily_hours' => $arrangement->daily_hours,
            'remarks' => $arrangement->remarks,
            'status' => $arrangement->status,
            'decision_remarks' => $arrangement->decision_remarks,
            'decided_by' => $arrangement->decider?->name,
            'decided_at' => $arrangement->decided_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentReport(FwaReport $report): array
    {
        $report->loadMissing(['employee.user', 'acknowledger:id,name']);

        return [
            'id' => $report->id,
            'employee_id' => $report->employee_id,
            'name' => $report->employee->user->name,
            'week_start' => $report->week_start->toDateString(),
            'week_end' => $report->week_end->toDateString(),
            'targets' => $report->targets,
            'accomplishments' => $report->accomplishments,
            'remarks' => $report->remarks,
            'status' => $report->status,
            'acknowledged_by' => $report->acknowledger?->name,
            'acknowledged_at' => $report->acknowledged_at?->toIso8601String(),
            'acknowledgement_remarks' => $report->acknowledgement_remarks,
        ];
    }

    private function assertStatus(FwaArrangement $arrangement, string $status): void
    {
        if ($arrangement->status !== $status) {
            throw ValidationException::withMessages(['status' => "Only a {$status} arrangement can be changed this way."]);
        }
    }
}
