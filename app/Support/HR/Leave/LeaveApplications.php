<?php

namespace App\Support\HR\Leave;

use App\Enums\LeaveApplicationStatus;
use App\Enums\LeaveDetail;
use App\Enums\LeaveStepAction;
use App\Enums\PermissionName;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveApplicationStep;
use App\Models\LeaveDesignation;
use App\Models\LeaveType;
use App\Models\User;
use App\Notifications\LeaveApplicationUpdate;
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

    public function __construct(
        private readonly LeaveCredits $credits,
        private readonly WorkingDays $workingDays,
    ) {}

    // ── Filing ───────────────────────────────────────────────────────

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'leave_type_id' => ['required', 'integer', Rule::exists('leave_types', 'id')->where('is_active', true)],
            'date_from' => ['required', 'date', 'after:1990-01-01'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'is_half_day' => ['boolean'],
            'half_day_period' => ['nullable', 'required_if:is_half_day,true', 'in:AM,PM'],
            'detail' => ['nullable', Rule::enum(LeaveDetail::class)],
            'detail_text' => ['nullable', 'string', 'max:500'],
            'commutation_requested' => ['boolean'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.self::ATTACHMENT_KILOBYTES],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return ['leave_type_id' => 'type of leave', 'date_from' => 'start date', 'date_to' => 'end date', 'half_day_period' => 'AM or PM', 'detail_text' => 'details'];
    }

    /**
     * What the form shows as the dates change: the days counted, the credits
     * left after what's pending, and every rule the filing breaks (none:
     * ready to submit).
     *
     * @param  array<string, mixed>  $input
     * @return array{days: float, credit: ?array{code: string, balance: float, pending: float, available: float}, issues: array<string, string>}
     */
    public function preview(Employee $employee, array $input, ?LeaveApplication $ignore = null): array
    {
        $type = LeaveType::query()->with('chargedTo')->find($input['leave_type_id'] ?? null);
        $from = $this->date($input['date_from'] ?? null);
        $to = $this->date($input['date_to'] ?? null) ?? $from;

        if (! $type || ! $from || ! $to || $to->lt($from)) {
            return ['days' => 0.0, 'credit' => null, 'issues' => []];
        }

        $halfDay = (bool) ($input['is_half_day'] ?? false);
        $days = $this->workingDays->count($from, $to, $halfDay, $type->counts_calendar_days, $employee);
        $credit = $type->requires_balance ? $this->available($employee, $type, $ignore) : null;

        return [
            'days' => $days,
            'credit' => $credit,
            'issues' => $this->issues($employee, $type, $from, $to, $halfDay, $days, $input, $credit, $ignore),
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
        $supervisor = $type->workflow === 'standard' ? $employee->immediate_supervisor_id : null;

        return DB::transaction(function () use ($employee, $data, $actor, $resubmitting, $type, $preview, $supervisor) {
            $application = $resubmitting ?? new LeaveApplication;
            $detail = isset($data['detail']) ? LeaveDetail::from($data['detail']) : null;

            $application->forceFill([
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'date_from' => $data['date_from'],
                'date_to' => ($data['is_half_day'] ?? false) ? $data['date_from'] : $data['date_to'],
                'is_half_day' => (bool) ($data['is_half_day'] ?? false),
                'half_day_period' => ($data['is_half_day'] ?? false) ? $data['half_day_period'] : null,
                'days' => $preview['days'],
                'days_with_pay' => null,
                'days_without_pay' => null,
                'detail' => $detail,
                'detail_text' => $detail?->needsText() || $detail === null ? ($data['detail_text'] ?? null) : null,
                'commutation_requested' => (bool) ($data['commutation_requested'] ?? false),
                'remarks' => $data['remarks'] ?? null,
                'status' => LeaveApplicationStatus::ForReview,
                'filed_by' => $actor->id,
                'submitted_at' => now(),
                'decided_at' => null,
            ])->save();

            if (! $application->reference_number) {
                $application->forceFill(['reference_number' => sprintf('LV-%d-%05d', now()->year, $application->id)])->save();
            }

            $this->storeAttachment($application, $data['attachment'] ?? null);

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
            'supervisor' => ['can' => $step->assigned_to === $user->id, 'acting_for' => null],
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
            || LeaveDesignation::query()->activeOn()->where('designee_id', $user->id)->exists();
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
            'office' => $application->employee->user->organizationalUnit?->short_name ?: $application->employee->user->organizationalUnit?->name,
            'leave_type_id' => $application->leave_type_id,
            'type_code' => $application->leaveType->code,
            'type_name' => $application->leaveType->name,
            'date_from' => $application->date_from->toDateString(),
            'date_to' => $application->date_to->toDateString(),
            'is_half_day' => $application->is_half_day,
            'half_day_period' => $application->half_day_period,
            'days' => (float) $application->days,
            'days_with_pay' => $application->days_with_pay !== null ? (float) $application->days_with_pay : null,
            'days_without_pay' => $application->days_without_pay !== null ? (float) $application->days_without_pay : null,
            'detail' => $application->detail?->value,
            'detail_label' => $application->detail?->label(),
            'detail_text' => $application->detail_text,
            'commutation_requested' => $application->commutation_requested,
            'remarks' => $application->remarks,
            'status' => $application->status->value,
            'status_label' => $application->status->label(),
            'submitted_at' => $application->submitted_at?->toIso8601String(),
            'decided_at' => $application->decided_at?->toIso8601String(),
            'has_attachment' => $application->attachment_path !== null,
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
                'certified_credits' => $step->certified_credits,
            ]);
    }

    // ── Internals ────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $input
     * @param  ?array{code: string, balance: float, pending: float, available: float}  $credit
     * @return array<string, string> field => message
     */
    private function issues(Employee $employee, LeaveType $type, Carbon $from, Carbon $to, bool $halfDay, float $days, array $input, ?array $credit, ?LeaveApplication $ignore): array
    {
        $issues = [];

        if ($reasons = $this->credits->ineligibilityReasons($employee, $type, $from)) {
            $issues['leave_type_id'] = implode(' ', $reasons);
        }
        if ($type->workflow === 'standard' && ! $employee->immediate_supervisor_id) {
            $issues['leave_type_id'] = 'Your immediate supervisor isn\'t set yet. Ask HR to set it before filing.';
        }
        if ($halfDay && ! $type->allows_half_day) {
            $issues['is_half_day'] = "{$type->name} can't be taken by the half day.";
        } elseif ($halfDay && ! $from->equalTo($to)) {
            $issues['is_half_day'] = 'A half day is for a single date.';
        }
        if ($days <= 0) {
            $issues['date_to'] = 'Those dates have no working days.';
        }
        if ($type->advance_filing_days > 0 && today()->gt($from->copy()->subDays($type->advance_filing_days))) {
            $issues['date_from'] = "{$type->name} must be filed at least {$type->advance_filing_days} days ahead.";
        }
        if ($type->max_days_per_filing !== null && $days > (float) $type->max_days_per_filing) {
            $issues['date_to'] = "{$type->name} is at most ".(float) $type->max_days_per_filing.' days per filing.';
        }
        if ($type->max_days_per_year !== null) {
            $taken = (float) LeaveApplication::query()->holdingDays()
                ->where('employee_id', $employee->id)->where('leave_type_id', $type->id)
                ->whereYear('date_from', $from->year)
                ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))
                ->sum('days');
            if ($taken + $days > (float) $type->max_days_per_year) {
                $issues['date_to'] = "{$type->name} is at most ".(float) $type->max_days_per_year." days a year; {$taken} already filed or taken.";
            }
        }
        if ($credit !== null && $days > $credit['available']) {
            $issues['date_to'] = "Not enough {$credit['code']} credits: {$credit['available']} available (balance {$credit['balance']} less {$credit['pending']} pending), {$days} asked.";
        }
        $overlap = LeaveApplication::query()->holdingDays()->where('employee_id', $employee->id)
            ->whereDate('date_from', '<=', $to)->whereDate('date_to', '>=', $from)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))->value('reference_number');
        if ($overlap) {
            $issues['date_from'] = "Those dates overlap application {$overlap}.";
        }
        if ($type->medical_certificate_after_days !== null && $days > $type->medical_certificate_after_days
            && ! ($input['attachment'] ?? null) instanceof UploadedFile && ! $ignore?->attachment_path) {
            $issues['attachment'] = "Attach a medical certificate: {$type->name} over {$type->medical_certificate_after_days} days needs one.";
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
        $type = $application->leaveType;
        $days = (float) $application->days;

        $withPay = match (true) {
            $type->code === 'LWOP' => 0.0,
            $type->requires_balance => min($days, max(0, $this->credits->balance($application->employee, $type))),
            default => $days,
        };

        if (isset($data['days_with_pay']) && $data['days_with_pay'] !== '') {
            $asked = (float) $data['days_with_pay'];
            if ($asked > $withPay) {
                throw ValidationException::withMessages(['days_with_pay' => "At most {$withPay} days can be with pay."]);
            }
            $withPay = $asked;
        }

        $application->forceFill([
            'status' => LeaveApplicationStatus::Approved,
            'current_step' => null,
            'decided_at' => now(),
            'days_with_pay' => $withPay,
            'days_without_pay' => round($days - $withPay, 3),
        ])->save();

        // Dated the day it's approved, as the legacy LMS did: a balance "as
        // of today" must already exclude approved leave still to come.
        $this->credits->deductLeave($application->employee, $type, $withPay, today(),
            "{$type->code} taken, {$application->reference_number} (".$application->date_from->format('M j').($application->date_to->equalTo($application->date_from) ? '' : '–'.$application->date_to->format('M j')).')', $by);

        $this->notifyEmployee($application, 'Leave application approved',
            "Your application {$application->reference_number} was approved: {$withPay} day(s) with pay".($days > $withPay ? ', '.round($days - $withPay, 3).' without pay' : '').'.');
    }

    /** Tells whoever acts next. */
    private function notifyStep(LeaveApplication $application): void
    {
        $step = $application->pendingStep();
        if (! $step) {
            return;
        }

        $recipients = match ($step->role) {
            'supervisor' => User::query()->whereKey($step->assigned_to)->get(),
            'hr' => $this->withPermission(PermissionName::LeaveCertify),
            'head' => $this->withPermission(PermissionName::LeaveApprove)->merge(
                User::query()->whereIn('id', LeaveDesignation::query()->activeOn()->select('designee_id'))->get()),
            default => collect(),
        };

        $recipients = $recipients->unique('id')->reject(fn (User $user) => $user->id === $application->employee->user_id);
        $name = $application->employee->user->name;

        Notification::send($recipients, new LeaveApplicationUpdate($application, 'Leave application for your action',
            "{$name}'s {$application->leaveType->name} ({$application->reference_number}) is waiting for you: {$step->roleLabel()}.", '/leave/approvals'));
    }

    private function notifyEmployee(LeaveApplication $application, string $title, string $message): void
    {
        $application->employee->user->notify(new LeaveApplicationUpdate($application, $title, $message, "/leave/applications/{$application->id}"));
    }

    /**
     * @return Collection<int, User>
     */
    private function withPermission(PermissionName $permission): Collection
    {
        return User::query()->where('status', 'active')
            ->whereHas('roles.permissions', fn ($query) => $query->where('name', $permission->value))
            ->get();
    }

    private function storeAttachment(LeaveApplication $application, mixed $file): void
    {
        if (! $file instanceof UploadedFile) {
            return;
        }

        $old = $application->attachment_path;
        $extension = $file->guessExtension() === 'jpeg' ? 'jpg' : $file->guessExtension();

        $application->forceFill([
            'attachment_path' => $file->storeAs("hr/leave/{$application->employee_id}", Str::uuid().'.'.$extension, self::DISK),
            'attachment_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'attachment_mime' => $file->getMimeType(),
            'attachment_size' => $file->getSize(),
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
