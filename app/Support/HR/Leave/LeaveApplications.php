<?php

namespace App\Support\HR\Leave;

use App\Enums\LeaveApplicationStatus;
use App\Enums\LeaveDetail;
use App\Enums\LeaveStepAction;
use App\Enums\PermissionName;
use App\Models\ApprovalDelegation;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveApplicationStep;
use App\Models\LeaveDesignation;
use App\Models\LeaveType;
use App\Models\User;
use App\Notifications\LeaveApplicationUpdate;
use App\Support\ApprovalTrail;
use App\Support\HR\Signatures;
use Carbon\CarbonPeriod;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * HR-6b: the only writer of leave applications — filing on CS Form No. 6
 * and its Section 7 approval chain. Ports the legacy LeaveApplicationServiceV2,
 * LeavePolicyService and LeaveWorkflowService:
 *
 * - Filing checks eligibility (sex, status, service, Solo Parent ID), that
 *   the dates hold working days, half-day and advance-filing rules, the
 *   per-filing and yearly limits, overlaps, a medical certificate past the
 *   type's threshold, and — for leave paid from credits — that the balance
 *   less what's already pending covers it.
 * - Route: standard leave goes supervisor (Part B) → HR (Part A) → Head of
 *   Agency (Parts C/D); special leave goes HR → Head. The supervisor is
 *   named at submission; HR (`leave.certify`) and the Head (`leave.approve`,
 *   or an officer-in-charge designated for the day) act as a role. Nobody
 *   acts on their own application.
 * - Any step can return it for correction; the employee fixes it and
 *   resubmits (a new round), or cancels. "Not recommended" still goes on to
 *   HR, as in the legacy LMS.
 * - On approval the days with pay come off the credits (dated the day of
 *   approval, as in the legacy LMS); the rest is leave without pay (accrual is pro-rated, and
 *   the Service Record shows it).
 */
class LeaveApplications
{
    public const DISK = 'local';

    public const ATTACHMENT_KILOBYTES = 10240;

    /** Leave this long or longer (calendar days) needs a clearance (CSC MC No. 2, s. 1985). */
    public const CLEARANCE_CALENDAR_DAYS = 30;

    /** Sec. 49, Rule XVI: an application not acted on within this many working days is deemed approved. */
    public const DEEMED_APPROVED_WORKING_DAYS = 5;

    /** The most dates one filing can choose one by one. */
    public const MAX_CHOSEN_DATES = 60;

    public function __construct(
        private readonly LeaveCredits $credits,
        private readonly WorkingDays $workingDays,
        private readonly Supervisors $supervisors,
    ) {}

    // ── Filing ───────────────────────────────────────────────────────

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'leave_type_id' => ['required', 'integer', Rule::exists('leave_types', 'id')->where('is_active', true)],
            // Either a from–to range, or dates chosen one by one.
            'date_from' => ['nullable', 'required_without:dates', 'date', 'after:1990-01-01'],
            'date_to' => ['nullable', 'required_without:dates', 'date', 'after_or_equal:date_from'],
            'dates' => ['nullable', 'array', 'min:1', 'max:'.self::MAX_CHOSEN_DATES],
            'dates.*' => ['required', 'date', 'distinct', 'after:1990-01-01'],
            'is_half_day' => ['boolean'],
            'half_day_period' => ['nullable', 'required_if:is_half_day,true', 'in:AM,PM'],
            'detail' => ['nullable', Rule::enum(LeaveDetail::class)],
            'detail_text' => ['nullable', 'string', 'max:500'],
            'commutation_requested' => ['boolean'],
            'is_emergency' => ['boolean'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.self::ATTACHMENT_KILOBYTES],
            'clearance' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.self::ATTACHMENT_KILOBYTES],
            'late_reason' => ['nullable', 'string', 'max:1000'],
            'event_date' => ['nullable', 'date', 'after:1990-01-01'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return ['leave_type_id' => 'type of leave', 'date_from' => 'start date', 'date_to' => 'end date', 'dates' => 'dates', 'dates.*' => 'date', 'half_day_period' => 'AM or PM', 'detail_text' => 'details', 'late_reason' => 'reason for filing late', 'event_date' => 'date'];
    }

    /**
     * What the form shows as the dates change: the days counted, the credits
     * left after what's pending, and every rule the filing breaks (none:
     * ready to submit). The dates are a from–to range, or (`dates`) dates
     * chosen one by one, e.g. five separate days of VL.
     *
     * @param  array<string, mixed>  $input
     * @return array{days: float, credit: ?array{code: string, balance: float, pending: float, available: float}, issues: array<string, string>, date_from?: string, date_to?: string, dates?: array<int, string>}
     */
    public function preview(Employee $employee, array $input, ?LeaveApplication $ignore = null): array
    {
        $type = LeaveType::query()->with(['chargedTo', 'excessChargedTo'])->find($input['leave_type_id'] ?? null);
        $chosen = LeaveDates::normalize(array_filter((array) ($input['dates'] ?? []), fn ($date) => $this->date($date) !== null));
        $from = $chosen ? Carbon::parse($chosen[0]) : $this->date($input['date_from'] ?? null);
        $to = $chosen ? Carbon::parse(end($chosen)) : ($this->date($input['date_to'] ?? null) ?? $from);

        if (! $type || ! $from || ! $to || $to->lt($from)) {
            return ['days' => 0.0, 'credit' => null, 'issues' => [], 'notices' => [], 'needs' => [], 'pay' => null];
        }

        $halfDay = (bool) ($input['is_half_day'] ?? false);
        $days = $chosen
            ? (float) collect($chosen)->sum(fn (string $date) => $this->workingDays->count($date, $date, false, $type->counts_calendar_days, $employee))
            : $this->workingDays->count($from, $to, $halfDay, $type->counts_calendar_days, $employee);
        $credit = $type->requires_balance ? $this->available($employee, $type, $ignore) : null;
        $pay = $this->payPlan($employee, $type, $days, $from, fn (LeaveType $credits) => $this->available($employee, $credits, $ignore)['available']);
        $needs = $this->needs($type, $from, $to, $chosen, $input);

        return [
            'days' => $days,
            'credit' => $credit,
            'pay' => $pay,
            'needs' => $needs,
            'issues' => $this->issues($employee, $type, $from, $to, $chosen, $halfDay, $days, $input, $credit, $ignore, $needs),
            'notices' => $this->notices($type, $pay, $input),
            'date_from' => $from->toDateString(),
            'date_to' => $halfDay && ! $chosen ? $from->toDateString() : $to->toDateString(),
            'dates' => $chosen,
        ];
    }

    /**
     * Files a new application, or resubmits a returned one (a new round of
     * approvals).
     *
     * @param  array<string, mixed>  $data  validated
     */
    public function file(Employee $employee, array $data, User $actor, ?LeaveApplication $resubmitting = null): LeaveApplication
    {
        $preview = $this->preview($employee, $data, $resubmitting);
        if ($preview['issues'] !== []) {
            throw ValidationException::withMessages($preview['issues']);
        }

        $type = LeaveType::query()->findOrFail($data['leave_type_id']);
        $supervisor = $type->workflow === 'standard' ? $this->supervisors->idFor($employee) : null;

        return DB::transaction(function () use ($employee, $data, $actor, $resubmitting, $type, $preview, $supervisor) {
            $application = $resubmitting ?? new LeaveApplication;
            $detail = isset($data['detail']) ? LeaveDetail::from($data['detail']) : null;

            $application->forceFill([
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'date_from' => $preview['date_from'],
                'date_to' => $preview['date_to'],
                'dates' => $preview['dates'] ?: null,
                'is_half_day' => (bool) ($data['is_half_day'] ?? false),
                'half_day_period' => ($data['is_half_day'] ?? false) ? $data['half_day_period'] : null,
                'days' => $preview['days'],
                'days_with_pay' => null,
                'days_without_pay' => null,
                'detail' => $detail,
                'detail_text' => $detail?->takesText() || $detail === null ? ($data['detail_text'] ?? null) : null,
                'commutation_requested' => (bool) ($data['commutation_requested'] ?? false),
                'is_emergency' => $this->filedLate($type, Carbon::parse($preview['date_from'])) && $this->emergency($type, $data),
                'remarks' => $data['remarks'] ?? null,
                'late_reason' => $preview['needs']['late_reason'] ? trim((string) ($data['late_reason'] ?? '')) : null,
                'event_date' => $type->event_label ? ($data['event_date'] ?? null) : null,
                'charges' => null,
                'deadline_notices' => 0,
                'status' => LeaveApplicationStatus::ForReview,
                'filed_by' => $actor->id,
                'submitted_at' => now(),
                // Optional e-signature of the applicant; a resubmission is signed afresh or not at all.
                'signed_at' => ($data['signed'] ?? false) ? now() : null,
                'signed_ip' => ($data['signed'] ?? false) ? ($data['signed_ip'] ?? null) : null,
                // A copy of the saved signature image, so changing it later doesn't alter this filing.
                'signature_path' => ($data['signed'] ?? false) ? app(Signatures::class)->snapshot($actor->signature_path) : null,
                'decided_at' => null,
            ])->save();

            if (! $application->reference_number) {
                $application->forceFill(['reference_number' => sprintf('LV-%d-%05d', now()->year, $application->id)])->save();
            }

            $this->storeAttachment($application, $data['attachment'] ?? null);
            $this->storeAttachment($application, $data['clearance'] ?? null, 'clearance');

            // A new round of steps; earlier rounds stay as the history.
            $round = $resubmitting ? $resubmitting->currentRound() + 1 : 1;
            $roles = $type->workflow === 'standard' ? ['supervisor' => 'B', 'hr' => 'A', 'head' => 'C'] : ['hr' => 'A', 'head' => 'C'];
            $number = 0;

            foreach ($roles as $role => $part) {
                $application->steps()->create([
                    'round' => $round,
                    'step' => ++$number,
                    'part' => $part,
                    'role' => $role,
                    'assigned_to' => $role === 'supervisor' ? $supervisor : null,
                ]);
            }

            $application->forceFill(['current_step' => 1])->save();
            $this->notifyStep($application->fresh());

            return $application;
        });
    }

    /**
     * The employee withdraws an application still under review or returned.
     */
    public function cancel(LeaveApplication $application): void
    {
        if (! $application->status->isOpen()) {
            throw ValidationException::withMessages(['status' => 'Only an application under review or returned can be cancelled.']);
        }

        $application->forceFill(['status' => LeaveApplicationStatus::Cancelled, 'current_step' => null])->save();
        $application->currentSteps()->where('action', LeaveStepAction::Pending)->delete();
    }

    // ── Approving ────────────────────────────────────────────────────

    /**
     * Whether this person may act on the step now — and, for the Head's
     * step, for whom (an officer-in-charge acts for the Head).
     *
     * @return array{can: bool, acting_for: ?int}
     */
    public function authority(User $user, LeaveApplicationStep $step): array
    {
        $application = $step->application;
        $no = ['can' => false, 'acting_for' => null];

        if ($step->action !== LeaveStepAction::Pending || $application->status !== LeaveApplicationStatus::ForReview
            || $application->current_step !== $step->step || $application->employee->user_id === $user->id) {
            return $no;
        }

        return match ($step->role) {
            'supervisor' => match (true) {
                $step->assigned_to === $user->id => ['can' => true, 'acting_for' => null],
                $step->assigned_to !== null && ApprovalDelegation::query()->activeOn()
                    ->where('delegator_id', $step->assigned_to)->where('delegate_id', $user->id)->exists() => ['can' => true, 'acting_for' => $step->assigned_to],
                default => $no,
            },
            'hr' => ['can' => $user->hasPermission(PermissionName::LeaveCertify->value), 'acting_for' => null],
            'head' => $user->hasPermission(PermissionName::LeaveApprove->value)
                ? ['can' => true, 'acting_for' => null]
                : (($designation = LeaveDesignation::query()->activeOn()->where('designee_id', $user->id)->latest('id')->first())
                    ? ['can' => true, 'acting_for' => $designation->acting_for_id]
                    : $no),
            default => $no,
        };
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function actionRules(LeaveApplicationStep $step): array
    {
        return [
            'action' => ['required', Rule::in(array_map(fn (LeaveStepAction $action) => $action->value, LeaveStepAction::forRole($step->role)))],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'days_with_pay' => ['nullable', 'numeric', 'min:0', 'max:999'],
            // Optional e-signature: ticked, it needs the account password (as when signing the PDS).
            'sign' => ['nullable', 'boolean'],
            'password' => ['required_if_accepted:sign', 'nullable', 'current_password'],
        ];
    }

    /**
     * Records the step's action and moves the application along.
     *
     * @param  array<string, mixed>  $data  validated
     */
    public function act(LeaveApplicationStep $step, User $user, array $data): LeaveApplication
    {
        $authority = $this->authority($user, $step);
        if (! $authority['can']) {
            throw ValidationException::withMessages(['action' => 'This application isn\'t waiting for you.']);
        }

        $action = LeaveStepAction::from($data['action']);
        $remarks = trim((string) ($data['remarks'] ?? ''));
        if ($action->needsRemarks() && $remarks === '') {
            throw ValidationException::withMessages(['remarks' => 'Give the reason, so the employee knows.']);
        }

        $application = $step->application;

        return DB::transaction(function () use ($step, $user, $action, $remarks, $data, $authority, $application) {
            $step->forceFill([
                'action' => $action,
                'remarks' => $remarks !== '' ? $remarks : null,
                'acted_by' => $user->id,
                'acted_for' => $authority['acting_for'],
                'acted_at' => now(),
                'signed_at' => ($data['signed'] ?? false) ? now() : null,
                'signed_ip' => ($data['signed'] ?? false) ? ($data['signed_ip'] ?? null) : null,
                'signature_path' => ($data['signed'] ?? false) ? app(Signatures::class)->snapshot($user->signature_path) : null,
                // 7.A: freeze the credits as HR certified them.
                'certified_credits' => $step->role === 'hr' ? $this->credits->balances($application->employee)->pluck('balance', 'code')->all() : null,
            ])->save();

            match ($action) {
                LeaveStepAction::Returned => $this->returnToEmployee($application, $remarks),
                LeaveStepAction::Disapproved, LeaveStepAction::InsufficientCredits => $this->decide($application, LeaveApplicationStatus::Disapproved, $user, $remarks),
                LeaveStepAction::Approved => $this->approve($application, $user, $data),
                default => $this->advance($application, $action, $remarks),
            };

            return $application->fresh();
        });
    }

    /**
     * Pending steps this person can act on now.
     *
     * @return Collection<int, LeaveApplicationStep>
     */
    public function queueFor(User $user): Collection
    {
        return LeaveApplicationStep::query()
            ->where('action', LeaveStepAction::Pending)
            ->whereHas('application', fn ($query) => $query->where('status', LeaveApplicationStatus::ForReview)->whereColumn('leave_applications.current_step', 'leave_application_steps.step'))
            ->with(['application.employee.user.organizationalUnit', 'application.leaveType'])
            ->orderBy('created_at')
            ->get()
            ->filter(fn (LeaveApplicationStep $step) => $this->authority($user, $step)['can'])
            ->values();
    }

    /**
     * Whether anything (now) or anyone (as their supervisor) could come to
     * this person for action — shows "Leave Approvals" in their menu.
     */
    public function isApprover(User $user): bool
    {
        return $user->hasAnyPermission([PermissionName::LeaveCertify->value, PermissionName::LeaveApprove->value])
            || Employee::query()->where('immediate_supervisor_id', $user->id)->exists()
            || $this->supervisors->supervisesByRule($user)
            || LeaveDesignation::query()->activeOn()->where('designee_id', $user->id)->exists()
            || ApprovalDelegation::query()->activeOn()->where('delegate_id', $user->id)->exists();
    }

    // ── Reading ──────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function present(LeaveApplication $application): array
    {
        $application->loadMissing(['leaveType', 'employee.user.organizationalUnit']);

        return [
            'id' => $application->id,
            'reference_number' => $application->reference_number,
            'employee_id' => $application->employee_id,
            'name' => $application->employee->user->name,
            'photo_url' => $application->employee->user->profile_photo_url,
            'office' => $application->employee->user->organizationalUnit?->short_name ?: $application->employee->user->organizationalUnit?->name,
            'leave_type_id' => $application->leave_type_id,
            'type_code' => $application->leaveType->code,
            'type_name' => $application->leaveType->name,
            'date_from' => $application->date_from->toDateString(),
            'date_to' => $application->date_to->toDateString(),
            'dates' => $application->hasChosenDates() ? $application->dates : null,
            'dates_label' => LeaveDates::describe($application),
            'is_half_day' => $application->is_half_day,
            'half_day_period' => $application->half_day_period,
            'days' => (float) $application->days,
            'days_with_pay' => $application->days_with_pay !== null ? (float) $application->days_with_pay : null,
            'days_without_pay' => $application->days_without_pay !== null ? (float) $application->days_without_pay : null,
            'detail' => $application->detail?->value,
            'detail_label' => $application->detail?->label(),
            'detail_text' => $application->detail_text,
            'commutation_requested' => $application->commutation_requested,
            'is_emergency' => $application->is_emergency,
            'remarks' => $application->remarks,
            'status' => $application->status->value,
            'status_label' => $application->status->label(),
            'submitted_at' => $application->submitted_at?->toIso8601String(),
            'signed_at' => $application->signed_at?->toIso8601String(),
            'decided_at' => $application->decided_at?->toIso8601String(),
            'has_attachment' => $application->attachment_path !== null,
            'has_clearance' => $application->clearance_path !== null,
            'clearance_name' => $application->clearance_name,
            'late_reason' => $application->late_reason,
            'event_date' => $application->event_date?->toDateString(),
            'event_label' => $application->leaveType->event_label,
            'charges' => $application->charges,
            'waiting_working_days' => $this->waitingWorkingDays($application),
            'deemed_approved' => $this->isDeemedApproved($application),
            'attachment_name' => $application->attachment_name,
        ];
    }

    /**
     * Every step, all rounds, for the application page and the form.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function presentSteps(LeaveApplication $application): Collection
    {
        return $application->steps()->with(['assignee:id,name', 'actor:id,name,position', 'actingFor:id,name'])->get()
            ->map(fn (LeaveApplicationStep $step) => [
                'id' => $step->id,
                'round' => $step->round,
                'step' => $step->step,
                'part' => $step->part,
                'role' => $step->role,
                'role_label' => $step->roleLabel(),
                'assigned_to' => $step->assignee?->name,
                'action' => $step->action->value,
                'action_label' => $step->action->label(),
                'remarks' => $step->remarks,
                'acted_by' => $step->actor?->name,
                'acted_by_position' => $step->actor?->position,
                'acted_for' => $step->actingFor?->name,
                'acted_at' => $step->acted_at?->toIso8601String(),
                'signed_at' => $step->signed_at?->toIso8601String(),
                'certified_credits' => $step->certified_credits,
            ]);
    }

    /**
     * Where the application is on its way (current round): filed, each
     * approver in turn, then the decision. The step it waits at shows who
     * it's with: the assigned supervisor, else HR or the Head (or an OIC).
     *
     * @return array{summary: ?string, steps: array<int, array{label: string, state: string, detail: ?string}>}
     */
    public function trail(LeaveApplication $application): array
    {
        $steps = $application->currentSteps()->with(['assignee:id,name', 'actor:id,name'])->get();
        $status = $application->status;
        $waiting = $status === LeaveApplicationStatus::ForReview ? $steps->firstWhere('action', LeaveStepAction::Pending) : null;
        $labels = ['supervisor' => 'Supervisor', 'hr' => 'HR', 'head' => 'Head of Agency'];
        $holder = fn (LeaveApplicationStep $step): string => $step->assignee?->name ?? match ($step->role) {
            'hr' => 'HR',
            'head' => 'the Head of Agency (or OIC)',
            default => $step->roleLabel(),
        };

        $trail = [ApprovalTrail::step('Filed', ApprovalTrail::Done, ApprovalTrail::date($application->submitted_at))];
        $since = $application->submitted_at;

        foreach ($steps as $step) {
            $state = match (true) {
                $step->action === LeaveStepAction::Returned => ApprovalTrail::Returned,
                $step->action === LeaveStepAction::Disapproved => ApprovalTrail::Declined,
                $step->action !== LeaveStepAction::Pending => ApprovalTrail::Done,
                $waiting?->is($step) ?? false => ApprovalTrail::Current,
                default => ApprovalTrail::Upcoming,
            };

            if ($state === ApprovalTrail::Done) {
                $since = $step->acted_at ?? $since;
            }

            $trail[] = ApprovalTrail::step($labels[$step->role] ?? $step->roleLabel(), $state, match ($state) {
                ApprovalTrail::Current => $holder($step),
                ApprovalTrail::Upcoming => null,
                default => ApprovalTrail::byOn($step->actor?->name, $step->acted_at),
            });
        }

        $trail[] = match ($status) {
            LeaveApplicationStatus::Approved => ApprovalTrail::step('Approved', ApprovalTrail::Done, ApprovalTrail::date($application->decided_at)),
            LeaveApplicationStatus::Disapproved => ApprovalTrail::step('Disapproved', ApprovalTrail::Declined, ApprovalTrail::date($application->decided_at)),
            LeaveApplicationStatus::Cancelled => ApprovalTrail::step('Cancelled', ApprovalTrail::Declined),
            default => ApprovalTrail::step('Decision', ApprovalTrail::Upcoming),
        };

        $summary = match ($status) {
            LeaveApplicationStatus::ForReview => $waiting ? 'Waiting for '.$holder($waiting).' since '.ApprovalTrail::date($since).'.' : null,
            LeaveApplicationStatus::Returned => 'Returned to the employee for correction. Once it is fixed and resubmitted, it starts again from the first step.',
            LeaveApplicationStatus::Approved => 'Approved on '.ApprovalTrail::date($application->decided_at).'.',
            LeaveApplicationStatus::Disapproved => 'Disapproved on '.ApprovalTrail::date($application->decided_at).'.',
            LeaveApplicationStatus::Cancelled => 'Cancelled by the employee.',
        };

        return ApprovalTrail::make($trail, $summary);
    }

    // ── Internals ────────────────────────────────────────────────────

    /** Within the advance-filing period (or after the start): too late unless an emergency. */
    private function filedLate(LeaveType $type, Carbon $from): bool
    {
        return $type->advance_filing_days > 0 && today()->gt($from->copy()->subDays($type->advance_filing_days));
    }

    /**
     * Ticked "Emergency", on a leave that allows filing on return (e.g.
     * Wellness Leave, CSC MC No. 01, s. 2026, 3.e).
     *
     * @param  array<string, mixed>  $input
     */
    private function emergency(LeaveType $type, array $input): bool
    {
        return $type->allows_emergency_filing && filter_var($input['is_emergency'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * How a filing's days are paid: from its credits; once those run out,
     * from the credits it falls back on (SL → VL, MC 41, s. 1998, Sec. 56);
     * the rest without pay. Leave without credits is paid up to its paid
     * limit (maternity: 105 days, 120 for a solo parent), the extension
     * beyond it without pay.
     *
     * @param  callable(LeaveType): float  $balanceOf  the credits that can be used of a type
     * @return array{charges: array<string, float>, with_pay: float, without_pay: float, fallback: ?string}
     */
    public function payPlan(Employee $employee, LeaveType $type, float $days, Carbon $from, callable $balanceOf): array
    {
        if ($type->code === 'LWOP') {
            return ['charges' => [], 'with_pay' => 0.0, 'without_pay' => $days, 'fallback' => null];
        }

        if (! $type->requires_balance) {
            $paid = $type->max_days_per_filing !== null && $type->unpaid_extension_days
                ? min($days, $this->paidLimit($employee, $type, $from))
                : $days;

            return ['charges' => [], 'with_pay' => round($paid, 3), 'without_pay' => round($days - $paid, 3), 'fallback' => null];
        }

        $charges = [];
        $left = $days;
        $fallback = null;
        foreach (array_filter([$type->creditType(), $type->excessChargedTo?->creditType()]) as $index => $credits) {
            $take = round(min($left, max(0, $balanceOf($credits))), 3);
            if ($take > 0) {
                $charges[$credits->code] = round(($charges[$credits->code] ?? 0) + $take, 3);
                $left = round($left - $take, 3);
                $fallback = $index > 0 ? $credits->code : $fallback;
            }
        }

        return ['charges' => $charges, 'with_pay' => round($days - $left, 3), 'without_pay' => $left, 'fallback' => $fallback];
    }

    /** The days a filing of a type with a per-filing cap is paid for (maternity: +15 for a solo parent). */
    private function paidLimit(Employee $employee, LeaveType $type, Carbon $on): float
    {
        $extra = $type->solo_parent_extra_days && $employee->hasValidSoloParentId($on) ? (float) $type->solo_parent_extra_days : 0.0;

        return (float) $type->max_days_per_filing + $extra;
    }

    /**
     * What else this filing needs: a reason for filing late ("whenever
     * possible"), a clearance for 30 calendar days or more, a required
     * supporting document, a date the leave depends on.
     *
     * @param  array<int, string>  $chosen
     * @param  array<string, mixed>  $input
     * @return array{late_reason: bool, clearance: bool, attachment: bool, event_label: ?string}
     */
    private function needs(LeaveType $type, Carbon $from, Carbon $to, array $chosen, array $input): array
    {
        $span = $chosen ? count($chosen) : $from->diffInDays($to) + 1;

        return [
            'late_reason' => $type->late_filing_with_reason && $this->filedLate($type, $from) && ! $this->emergency($type, $input),
            'clearance' => $span >= self::CLEARANCE_CALENDAR_DAYS,
            'attachment' => $type->requires_attachment,
            'event_label' => $type->event_label,
        ];
    }

    /**
     * What the employee should know but that doesn't stop the filing.
     *
     * @param  array{charges: array<string, float>, with_pay: float, without_pay: float, fallback: ?string}  $pay
     * @param  array<string, mixed>  $input
     * @return array<int, string>
     */
    private function notices(LeaveType $type, array $pay, array $input): array
    {
        $notices = [];
        if ($pay['fallback']) {
            $notices[] = "Your {$type->creditType()->code} credits don't cover it all: {$pay['charges'][$pay['fallback']]} day(s) would be charged to {$pay['fallback']}.";
        }
        if ($pay['without_pay'] > 0 && $type->code !== 'LWOP') {
            $notices[] = $type->requires_balance
                ? "{$pay['without_pay']} day(s) are beyond your credits and would be without pay."
                : "{$pay['without_pay']} day(s) are beyond the paid {$type->name} and would be without pay.";
        }
        if (($input['detail'] ?? null) === LeaveDetail::Abroad->value) {
            $notices[] = 'Leave abroad needs a travel authority: secure it with this application, and attach it if you already have it.';
        }

        return $notices;
    }

    /**
     * Working days an application under review has waited since it was
     * filed (the day it was filed not counted).
     */
    public function waitingWorkingDays(LeaveApplication $application): ?int
    {
        if ($application->status !== LeaveApplicationStatus::ForReview || ! $application->submitted_at) {
            return null;
        }

        $from = $application->submitted_at->copy()->timezone(config('app.timezone'))->startOfDay()->addDay();

        return $from->gt(today()) ? 0 : (int) $this->workingDays->count($from, today());
    }

    /**
     * Sec. 49, Rule XVI of the Omnibus Rules on Leave: not acted on within
     * five working days of receipt, an application is deemed approved.
     * IMIS flags it (and reminds the approvers); it doesn't approve it on
     * its own, since approval charges the credits.
     */
    public function isDeemedApproved(LeaveApplication $application): bool
    {
        return ($this->waitingWorkingDays($application) ?? 0) >= self::DEEMED_APPROVED_WORKING_DAYS;
    }

    /**
     * Reminds whoever must act as the five working days run out: once on
     * the last day, once when they have passed. Returns how many were sent.
     */
    public function remindOverdue(): int
    {
        $sent = 0;
        LeaveApplication::query()->where('status', LeaveApplicationStatus::ForReview)->where('deadline_notices', '<', 2)
            ->with(['employee.user', 'leaveType'])->get()
            ->each(function (LeaveApplication $application) use (&$sent) {
                $waited = $this->waitingWorkingDays($application) ?? 0;
                $level = match (true) {
                    $waited >= self::DEEMED_APPROVED_WORKING_DAYS => 2,
                    $waited >= self::DEEMED_APPROVED_WORKING_DAYS - 1 => 1,
                    default => 0,
                };
                if ($level <= $application->deadline_notices) {
                    return;
                }

                $application->forceFill(['deadline_notices' => $level])->save();
                $name = $application->employee->user->name;
                $message = $level === 2
                    ? "{$name}'s {$application->leaveType->name} ({$application->reference_number}) has waited ".self::DEEMED_APPROVED_WORKING_DAYS.' working days without action and is deemed approved under Sec. 49, Rule XVI of the Omnibus Rules on Leave. Act on it now.'
                    : "{$name}'s {$application->leaveType->name} ({$application->reference_number}) must be acted on today: after ".self::DEEMED_APPROVED_WORKING_DAYS.' working days it is deemed approved (Sec. 49, Rule XVI, Omnibus Rules on Leave).';
                $this->notifyStep($application, $level === 2 ? 'Leave application deemed approved: act now' : 'Leave application due today', $message, alsoHr: true);
                $sent++;
            });

        return $sent;
    }

    /**
     * The longest run of this leave in a row (no working day between),
     * counting the same leave already filed or taken on either side, for
     * the "days in a row" cap. Works the same for a range and for chosen
     * dates (only the days that run together count).
     *
     * @param  array<string, float>  $own  date => days (1, or 0.5 for a half day)
     * @return array{run: float, own: float}
     */
    private function longestRun(Employee $employee, LeaveType $type, array $own, ?LeaveApplication $ignore): array
    {
        if ($own === []) {
            return ['run' => 0.0, 'own' => 0.0];
        }

        $first = Carbon::parse(array_key_first($own));
        $last = Carbon::parse(array_key_last($own));
        $held = [];
        LeaveApplication::query()->holdingDays()
            ->where('employee_id', $employee->id)->where('leave_type_id', $type->id)
            ->whereDate('date_from', '<=', $last->copy()->addDays(31))->whereDate('date_to', '>=', $first->copy()->subDays(31))
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))->get()
            ->each(function (LeaveApplication $other) use (&$held, $type, $employee) {
                foreach ($other->coveredDates() as $date) {
                    if ($this->workingDays->count($date, $date, false, $type->counts_calendar_days, $employee) > 0) {
                        $held[$date] = $other->is_half_day ? 0.5 : 1.0;
                    }
                }
            });

        $all = array_merge($held, $own);
        $best = ['run' => 0.0, 'own' => 0.0];
        $seen = [];

        foreach (array_keys($own) as $date) {
            if (isset($seen[$date])) {
                continue;
            }
            $run = ['run' => $all[$date], 'own' => $own[$date]];
            $seen[$date] = true;
            foreach ([-1, 1] as $direction) {
                $edge = Carbon::parse($date);
                for ($hops = 0; $hops < 40; $hops++) {
                    $next = $this->nextWorkingDay($edge, $direction, $type, $employee)->toDateString();
                    if (! isset($all[$next])) {
                        break;
                    }
                    $run['run'] += $all[$next];
                    $run['own'] += $own[$next] ?? 0.0;
                    $seen[$next] = true;
                    $edge = Carbon::parse($next);
                }
            }
            if ($run['run'] > $best['run']) {
                $best = $run;
            }
        }

        return $best;
    }

    /**
     * The application this filing's days would overlap, if any: compared
     * day by day, since chosen dates can sit between another filing's.
     *
     * @param  array<int, string>  $dates  every date this filing covers, sorted
     */
    private function overlapping(Employee $employee, array $dates, ?LeaveApplication $ignore): ?string
    {
        $wanted = array_flip($dates);

        return LeaveApplication::query()->holdingDays()->where('employee_id', $employee->id)
            ->whereDate('date_from', '<=', end($dates))->whereDate('date_to', '>=', $dates[0])
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))->get()
            ->first(fn (LeaveApplication $other) => array_intersect_key(array_flip($other->coveredDates()), $wanted) !== [])
            ?->reference_number;
    }

    /**
     * This filing's days, date => days: each working day of the range (a
     * half day 0.5), or each chosen working date.
     *
     * @param  array<int, string>  $chosen
     * @return array<string, float>
     */
    private function ownDays(Employee $employee, LeaveType $type, Carbon $from, Carbon $to, array $chosen, bool $halfDay): array
    {
        $dates = $chosen ?: collect(CarbonPeriod::create($from, $to))->map(fn (Carbon $day) => $day->toDateString())->all();
        $own = [];
        foreach ($dates as $date) {
            if ($this->workingDays->count($date, $date, false, $type->counts_calendar_days, $employee) > 0) {
                $own[$date] = $halfDay && ! $chosen ? 0.5 : 1.0;
            }
        }

        return $own;
    }

    private function nextWorkingDay(Carbon $day, int $direction, LeaveType $type, Employee $employee): Carbon
    {
        $candidate = $day->copy();
        for ($i = 0; $i < 31; $i++) {
            $candidate->addDays($direction);
            if ($this->workingDays->count($candidate, $candidate, false, $type->counts_calendar_days, $employee) > 0) {
                return $candidate;
            }
        }

        return $candidate;
    }

    /**
     * @param  array<int, string>  $chosen  dates chosen one by one (empty: the range)
     * @param  array<string, mixed>  $input
     * @param  ?array{code: string, balance: float, pending: float, available: float}  $credit
     * @param  array{late_reason: bool, clearance: bool, attachment: bool, event_label: ?string}  $needs
     * @return array<string, string> field => message
     */
    private function issues(Employee $employee, LeaveType $type, Carbon $from, Carbon $to, array $chosen, bool $halfDay, float $days, array $input, ?array $credit, ?LeaveApplication $ignore, array $needs): array
    {
        $issues = [];
        // With chosen dates, every date problem is reported on the dates.
        $start = $chosen ? 'dates' : 'date_from';
        $end = $chosen ? 'dates' : 'date_to';

        if ($reasons = $this->credits->ineligibilityReasons($employee, $type, $from)) {
            $issues['leave_type_id'] = implode(' ', $reasons);
        }
        if ($type->workflow === 'standard' && ! ($supervisor = $this->supervisors->for($employee))['id']) {
            $issues['leave_type_id'] = $supervisor['source'] === 'external'
                ? "Your leave is recommended by the {$supervisor['position']}, outside IMIS; file it with HR."
                : 'Your immediate supervisor isn\'t set yet. Ask HR to set it before filing.';
        }
        if ($halfDay && ! $type->allows_half_day) {
            $issues['is_half_day'] = "{$type->name} can't be taken by the half day.";
        } elseif ($halfDay && ($chosen || ! $from->equalTo($to))) {
            $issues['is_half_day'] = 'A half day is for a single date: give it as the start and end date.';
        }
        if ($chosen && $type->counts_calendar_days) {
            $issues['dates'] = "{$type->name} runs on consecutive days: give a start and end date instead.";
        } elseif ($chosen) {
            $off = array_values(array_filter($chosen, fn (string $date) => $this->workingDays->count($date, $date, false, false, $employee) <= 0));
            if ($off !== []) {
                $issues['dates'] = LeaveDates::list($off).(count($off) === 1 ? ' isn\'t a working day.' : ' aren\'t working days.');
            }
        }
        if ($days <= 0) {
            $issues[$end] = 'Those dates have no working days.';
        }
        if ($this->filedLate($type, $from)) {
            if ($needs['late_reason']) {
                // "Whenever possible" (VL): accepted late, with the reason.
                if (blank($input['late_reason'] ?? null)) {
                    $issues['late_reason'] = "{$type->name} is filed {$type->advance_filing_days} days ahead whenever possible: say why it's filed later.";
                }
            } elseif (! $this->emergency($type, $input)) {
                $issues[$start] = "{$type->name} must be filed at least {$type->advance_filing_days} days ahead."
                    .($type->allows_emergency_filing ? ' In an emergency, tick "Emergency" and file on your return.' : '');
            } elseif ($from->gt(today())) {
                $issues[$start] = 'An emergency filing is made on your return, once the leave has started.';
            }
        }
        if ($type->max_days_per_filing !== null && $days > ($limit = $this->paidLimit($employee, $type, $from) + (float) $type->unpaid_extension_days)) {
            $issues[$end] = "{$type->name} is at most {$limit} days per filing".($type->unpaid_extension_days ? ', counting the '.(float) $type->unpaid_extension_days.' days it can be extended without pay' : '').'.';
        }
        if ($type->max_filings !== null) {
            $filed = LeaveApplication::query()->holdingDays()->where('employee_id', $employee->id)->where('leave_type_id', $type->id)
                ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))->count();
            if ($filed >= $type->max_filings) {
                $issues['leave_type_id'] = "{$type->name} can be taken at most {$type->max_filings} times; you have {$filed} on record.";
            }
        }
        if ($type->max_consecutive_days !== null) {
            $run = $this->longestRun($employee, $type, $this->ownDays($employee, $type, $from, $to, $chosen, $halfDay), $ignore);
            if ($run['run'] > $type->max_consecutive_days) {
                $issues[$end] = "{$type->name} is at most {$type->max_consecutive_days} days in a row"
                    .($run['run'] > $run['own'] ? ", counting the {$type->name} right before or after these dates" : '').'.';
            }
        }
        if ($type->max_days_per_year !== null) {
            $taken = (float) LeaveApplication::query()->holdingDays()
                ->where('employee_id', $employee->id)->where('leave_type_id', $type->id)
                ->whereYear('date_from', $from->year)
                ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))
                ->sum('days');
            if ($taken + $days > (float) $type->max_days_per_year) {
                $issues[$end] = "{$type->name} is at most ".(float) $type->max_days_per_year." days a year; {$taken} already filed or taken.";
            }
        }
        // Leave the credits can't cover is refused, unless the excess is without pay (VL, FL, SL).
        if ($credit !== null && $days > $credit['available'] && ! $type->excess_without_pay) {
            $issues[$end] = "Not enough {$credit['code']} credits: {$credit['available']} available (balance {$credit['balance']} less {$credit['pending']} pending), {$days} asked.";
        }
        $covered = $chosen ?: collect(CarbonPeriod::create($from, $to))->map(fn (Carbon $day) => $day->toDateString())->all();
        if ($overlap = $this->overlapping($employee, $covered, $ignore)) {
            $issues[$start] = "Those dates overlap application {$overlap}.";
        }
        $attached = ($input['attachment'] ?? null) instanceof UploadedFile || $ignore?->attachment_path;
        if ($type->medical_certificate_after_days !== null && $days > $type->medical_certificate_after_days && ! $attached) {
            $issues['attachment'] = "Attach a medical certificate: {$type->name} over {$type->medical_certificate_after_days} days needs one.";
        } elseif ($type->medical_certificate_if_advance && $from->gt(today()) && ! $attached) {
            $issues['attachment'] = "Attach a medical certificate: {$type->name} filed in advance needs one.";
        } elseif ($needs['attachment'] && ! $attached) {
            $issues['attachment'] = 'Attach '.($type->supporting_documents ? lcfirst(rtrim($type->supporting_documents, '.')) : 'the supporting document').'.';
        }
        if ($needs['clearance'] && ! ($input['clearance'] ?? null) instanceof UploadedFile && ! $ignore?->clearance_path) {
            $issues['clearance'] = 'Leave of '.self::CLEARANCE_CALENDAR_DAYS.' calendar days or more needs a clearance from money, property and work accountabilities (CSC MC No. 2, s. 1985).';
        }
        if ($needs['event_label']) {
            $event = $this->date($input['event_date'] ?? null);
            $window = (int) $type->event_window_days;
            if (! $event) {
                $issues['event_date'] = "Give the {$needs['event_label']}.";
            } elseif ($event->gt($from)) {
                $issues['event_date'] = "{$type->name} starts on or after the {$needs['event_label']}.";
            } elseif ($window > 0 && $to->gt($event->copy()->addDays($window))) {
                $issues[$end] = "{$type->name} is taken within {$window} days of the {$needs['event_label']} (until ".$event->copy()->addDays($window)->format('F j, Y').').';
            }
        }

        $boxes = LeaveDetail::forCode($type->code);
        $detail = isset($input['detail']) && $input['detail'] !== '' ? LeaveDetail::tryFrom((string) $input['detail']) : null;
        if ($boxes !== [] && ! in_array($detail, $boxes, true)) {
            $issues['detail'] = 'Tick one of the details of leave.';
        } elseif ($detail?->needsText() && blank($input['detail_text'] ?? null)) {
            $issues['detail_text'] = 'Fill in the details.';
        }

        return $issues;
    }

    /**
     * The credits a filing of this type can use: the balance less what
     * open applications on the same credits already hold.
     *
     * @return array{code: string, balance: float, pending: float, available: float}
     */
    private function available(Employee $employee, LeaveType $type, ?LeaveApplication $ignore): array
    {
        $credit = $type->creditType();
        $balance = $this->credits->balance($employee, $type);
        $sharing = LeaveType::query()->where('id', $credit->id)->orWhere('charged_to_id', $credit->id)->pluck('id');
        $pending = round((float) LeaveApplication::query()
            ->where('employee_id', $employee->id)
            ->whereIn('leave_type_id', $sharing)
            ->whereIn('status', [LeaveApplicationStatus::ForReview, LeaveApplicationStatus::Returned])
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))
            ->sum('days'), 3);

        return ['code' => $credit->code, 'balance' => $balance, 'pending' => $pending, 'available' => round(max(0, $balance - $pending), 3)];
    }

    private function advance(LeaveApplication $application, LeaveStepAction $action, string $remarks): void
    {
        $application->forceFill(['current_step' => $application->current_step + 1])->save();

        if ($action === LeaveStepAction::NotRecommended) {
            $this->notifyEmployee($application, 'Leave not recommended by your supervisor',
                "Your application {$application->reference_number} was not recommended by your supervisor and has gone on to HR. Remarks: {$remarks}");
        }

        $this->notifyStep($application->fresh());
    }

    private function returnToEmployee(LeaveApplication $application, string $remarks): void
    {
        $application->forceFill(['status' => LeaveApplicationStatus::Returned, 'current_step' => null])->save();
        $application->currentSteps()->where('action', LeaveStepAction::Pending)->delete();

        $this->notifyEmployee($application, 'Leave application returned', "Your application {$application->reference_number} was returned for correction: {$remarks}");
    }

    private function decide(LeaveApplication $application, LeaveApplicationStatus $status, User $by, string $remarks): void
    {
        $application->forceFill(['status' => $status, 'current_step' => null, 'decided_at' => now()])->save();
        $application->currentSteps()->where('action', LeaveStepAction::Pending)->delete();

        $this->notifyEmployee($application, 'Leave application disapproved', "Your application {$application->reference_number} was disapproved: {$remarks}");
    }

    /**
     * 7.C: approved for so many days with pay (off the credits) and the
     * rest without pay. The Head may approve fewer days with pay than the
     * credits allow; never more.
     *
     * @param  array<string, mixed>  $data
     */
    private function approve(LeaveApplication $application, User $by, array $data): void
    {
        $type = $application->leaveType()->with(['chargedTo', 'excessChargedTo'])->first();
        $employee = $application->employee;
        $days = (float) $application->days;

        $plan = $this->payPlan($employee, $type, $days, $application->date_from, fn (LeaveType $credits) => $this->credits->balance($employee, $credits));
        $withPay = $plan['with_pay'];
        $charges = $plan['charges'];

        if (isset($data['days_with_pay']) && $data['days_with_pay'] !== '') {
            $asked = (float) $data['days_with_pay'];
            if ($asked > $withPay) {
                throw ValidationException::withMessages(['days_with_pay' => "At most {$withPay} days can be with pay."]);
            }
            // Fewer days with pay: taken off the credits used last (VL after SL) first.
            $cut = round($withPay - $asked, 3);
            foreach (array_reverse(array_keys($charges)) as $code) {
                $less = min($cut, $charges[$code]);
                $charges[$code] = round($charges[$code] - $less, 3);
                $cut = round($cut - $less, 3);
            }
            $charges = array_filter($charges, fn (float $charged) => $charged > 0);
            $withPay = $asked;
        }

        $application->forceFill([
            'status' => LeaveApplicationStatus::Approved,
            'current_step' => null,
            'decided_at' => now(),
            'days_with_pay' => $withPay,
            'days_without_pay' => round($days - $withPay, 3),
            'charges' => $charges ?: null,
        ])->save();

        // Dated the day it's approved, as the legacy LMS did: a balance "as
        // of today" must already exclude approved leave still to come.
        $when = $application->hasChosenDates()
            ? LeaveDates::list($application->dates, short: true)
            : $application->date_from->format('M j').($application->date_to->equalTo($application->date_from) ? '' : '–'.$application->date_to->format('M j'));
        foreach ($charges as $code => $charged) {
            $credits = LeaveType::query()->where('code', $code)->first();
            if ($credits) {
                $this->credits->deductLeave($employee, $credits, $charged, today(),
                    "{$type->code} taken".($code !== $type->creditType()->code ? ", charged to {$code}" : '').", {$application->reference_number} ({$when})", $by);
            }
        }

        // The Head's own note (the same one printed on the form, under 7.D).
        $note = trim((string) $application->steps()->where('role', 'head')->latest('id')->value('remarks'));
        $this->notifyEmployee($application, 'Leave application approved',
            "Your application {$application->reference_number} was approved: {$withPay} day(s) with pay".($days > $withPay ? ', '.round($days - $withPay, 3).' without pay' : '').'.'.($note !== '' ? " Note from the Head: {$note}" : ''));
    }

    /** Tells whoever acts next (and, for deadline reminders, HR too). */
    private function notifyStep(LeaveApplication $application, ?string $title = null, ?string $message = null, bool $alsoHr = false): void
    {
        $step = $application->pendingStep();
        if (! $step) {
            return;
        }

        $recipients = match ($step->role) {
            'supervisor' => User::query()->whereKey($step->assigned_to)
                ->orWhereIn('id', ApprovalDelegation::query()->activeOn()->where('delegator_id', $step->assigned_to)->select('delegate_id'))->get(),
            'hr' => $this->withPermission(PermissionName::LeaveCertify),
            'head' => $this->withPermission(PermissionName::LeaveApprove)->merge(
                User::query()->whereIn('id', LeaveDesignation::query()->activeOn()->select('designee_id'))->get()),
            default => collect(),
        };

        if ($alsoHr) {
            $recipients = $recipients->merge($this->withPermission(PermissionName::LeaveCertify));
        }
        $recipients = $recipients->unique('id')->reject(fn (User $user) => $user->id === $application->employee->user_id);
        $name = $application->employee->user->name;

        Notification::send($recipients, new LeaveApplicationUpdate($application, $title ?? 'Leave application for your action',
            $message ?? "{$name}'s {$application->leaveType->name} ({$application->reference_number}) is waiting for you: {$step->roleLabel()}.".$this->signedNote($application, applicant: true), '/leave/approvals'));
    }

    private function notifyEmployee(LeaveApplication $application, string $title, string $message): void
    {
        $application->employee->user->notify(new LeaveApplicationUpdate($application, $title, $message.$this->signedNote($application), "/leave/applications/{$application->id}"));
    }

    /**
     * " Signed electronically by …" for the step just acted on (and, going
     * to an approver, the applicant's own signature), or nothing.
     */
    private function signedNote(LeaveApplication $application, bool $applicant = false): string
    {
        $last = $application->steps()->with('actor:id,name')->where('action', '!=', LeaveStepAction::Pending->value)->whereNotNull('acted_at')
            ->reorder()->orderByDesc('acted_at')->orderByDesc('id')->first();
        $who = collect([
            $applicant && $application->signed_at ? 'the applicant' : null,
            $last?->signed_at ? $last->actor?->name : null,
        ])->filter()->unique()->values();

        return $who->isEmpty() ? '' : ' Signed electronically by '.$who->implode(' and ').'.';
    }

    /**
     * @return Collection<int, User>
     */
    private function withPermission(PermissionName $permission): Collection
    {
        return User::query()->where('status', 'active')
            ->whereHas('activeRoles.permissions', fn ($query) => $query->where('name', $permission->value))
            ->get();
    }

    /**
     * @param  'attachment'|'clearance'  $slot
     */
    private function storeAttachment(LeaveApplication $application, mixed $file, string $slot = 'attachment'): void
    {
        if (! $file instanceof UploadedFile) {
            return;
        }

        $old = $application->{"{$slot}_path"};
        $extension = $file->guessExtension() === 'jpeg' ? 'jpg' : $file->guessExtension();

        $application->forceFill([
            "{$slot}_path" => $file->storeAs("hr/leave/{$application->employee_id}", Str::uuid().'.'.$extension, self::DISK),
            "{$slot}_name" => Str::limit($file->getClientOriginalName(), 250, ''),
            "{$slot}_mime" => $file->getMimeType(),
            "{$slot}_size" => $file->getSize(),
        ])->save();

        if ($old) {
            Storage::disk(self::DISK)->delete($old);
        }
    }

    private function date(mixed $value): ?Carbon
    {
        try {
            return filled($value) ? Carbon::parse($value)->startOfDay() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
