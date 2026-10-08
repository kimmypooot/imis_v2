<?php

namespace App\Support\Security;

use App\Enums\AuditEvent;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Employee;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Settings\Settings;
use App\Support\SystemAccess\OfficeAdminAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Admin-3, account lifecycle. `imis:account-lifecycle` (nightly) deactivates:
 * - the accounts of employees whose separation date has passed;
 * - with System Settings → Security → "Deactivate after … days without
 *   signing in" set, accounts nobody has signed in to for that long
 *   (counting from the last sign-in, the account's creation, or its last
 *   reactivation, whichever is latest).
 * Super Administrators are never deactivated automatically, so the office
 * can't be locked out; they're reported instead.
 *
 * Deactivating — automatically or by an administrator — also ends the
 * person's open sessions at once, and drops the system access their
 * office designation gave them (OfficeAdminAccess).
 */
class AccountLifecycle
{
    public function __construct(
        private readonly LoginHistoryRecorder $sessions,
        private readonly OfficeAdminAccess $officeAdmins,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * What the nightly run would do (`--dry-run`) or did.
     *
     * @return array{separated: array<int, User>, dormant: array<int, User>, skipped: array<int, User>}
     */
    public function due(): array
    {
        $separated = Employee::query()->with('user')
            ->whereNotNull('separation_date')->whereDate('separation_date', '<=', today())
            ->whereHas('user', fn ($query) => $query->where('status', UserStatus::Active))
            ->get()->pluck('user');

        $days = (int) Settings::get('account_inactive_days');
        $dormant = $days > 0
            ? User::query()->where('status', UserStatus::Active)->get()
                ->reject(fn (User $user) => $separated->contains('id', $user->id))
                ->filter(fn (User $user) => $this->lastActive($user)->lt(now()->subDays($days)))
            : collect();

        $protected = fn (User $user) => $user->hasRole(RoleName::SuperAdmin);

        return [
            'separated' => $separated->reject($protected)->values()->all(),
            'dormant' => $dormant->reject($protected)->values()->all(),
            'skipped' => $separated->merge($dormant)->filter($protected)->values()->all(),
        ];
    }

    /**
     * @return array{separated: int, dormant: int, skipped: int}
     */
    public function sweep(): array
    {
        $due = $this->due();
        $days = (int) Settings::get('account_inactive_days');

        foreach ($due['separated'] as $user) {
            $date = $user->employee->separation_date->format('M j, Y');
            $this->deactivate($user, "Separated from service on {$date}");
        }

        foreach ($due['dormant'] as $user) {
            $this->deactivate($user, "No sign-in for {$days} days");
        }

        return array_map('count', $due);
    }

    /**
     * Deactivates on its own (nightly run): audited with no actor and the
     * reason. An administrator's deactivation goes through UserController,
     * which calls ended() after saving.
     */
    public function deactivate(User $user, string $reason): void
    {
        $user->update(['status' => UserStatus::Inactive]);

        $this->audit->recordFor(null, AuditEvent::UserStatusChanged, "Deactivated user {$user->name} automatically: {$reason}", $user, [
            'status' => ['old' => UserStatus::Active->value, 'new' => UserStatus::Inactive->value],
            'reason' => $reason,
            'automatic' => true,
        ]);

        $this->ended($user);
    }

    /**
     * After an account is deactivated: sign it out everywhere and drop
     * the access its office designation gave it. Returns how many
     * sessions were ended.
     */
    public function ended(User $user): int
    {
        $this->officeAdmins->sync($user);

        $sessions = DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->pluck('id');
        $sessions->each(fn (string $id) => $this->sessions->forceEnd($id));

        return $sessions->count();
    }

    /** The latest of: last sign-in, account creation, last (re)activation. */
    public function lastActive(User $user): Carbon
    {
        return collect([$user->last_login_at, $user->created_at, $user->status_changed_at])->filter()->max() ?? now();
    }
}
