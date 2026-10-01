<?php

namespace App\Support\Audit;

use App\Enums\AuditEvent;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;

/**
 * Records Laravel's own authentication events. Failed and blocked
 * (inactive-account) attempts are recorded directly in LoginRequest
 * instead, because it checks credentials itself and never fires
 * `Failed` — and it is the only place that knows *why* a login failed.
 *
 * Registered explicitly in AppServiceProvider and kept out of
 * app/Listeners so event auto-discovery can't register it a second time.
 */
class AuthenticationAuditSubscriber
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handleLogin(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->audit->recordFor(
            $event->user,
            AuditEvent::AuthLogin,
            "{$event->user->name} signed in".($event->remember ? ' (remember me)' : ''),
            $event->user,
            ['guard' => $event->guard, 'remember' => $event->remember],
        );
    }

    public function handleLogout(Logout $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->audit->recordFor(
            $event->user,
            AuditEvent::AuthLogout,
            "{$event->user->name} signed out",
            $event->user,
            ['guard' => $event->guard],
        );
    }

    public function handleLockout(Lockout $event): void
    {
        $username = (string) $event->request->input('username');

        $this->audit->recordFor(
            null,
            AuditEvent::AuthLockout,
            "Sign-in temporarily locked after repeated failures for {$username}",
            null,
            ['username' => $username],
        );
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->audit->recordFor(
            $event->user,
            AuditEvent::AuthPasswordReset,
            "{$event->user->name} reset their password",
            $event->user,
        );
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'handleLogin',
            Logout::class => 'handleLogout',
            Lockout::class => 'handleLockout',
            PasswordReset::class => 'handlePasswordReset',
        ];
    }
}
