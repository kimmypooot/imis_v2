<?php

namespace App\Support\Security;

use Illuminate\Contracts\Session\Session;

/**
 * The legacy IMIS session limits (includes/session.php): a 2-hour idle
 * timeout (Laravel's own `session.lifetime`) and an 8-hour absolute cap
 * per sign-in (`session.absolute_lifetime`), enforced by
 * EnforceSessionLimits and counted down in the browser by
 * useSessionTimeout.
 *
 * "Remember me" sign-ins are exempt: the remember cookie re-authenticates
 * them anyway, which is the point of ticking it.
 */
class SessionLimits
{
    private const STARTED = 'auth.started_at';

    private const REMEMBERED = 'auth.remembered';

    public function start(Session $session, bool $remembered): void
    {
        $session->put(self::STARTED, now()->getTimestamp());
        $session->put(self::REMEMBERED, $remembered);
    }

    public function started(Session $session): bool
    {
        return $session->has(self::STARTED);
    }

    public function applies(Session $session): bool
    {
        return ! $session->get(self::REMEMBERED, false);
    }

    public function idleSeconds(): int
    {
        return (int) config('session.lifetime') * 60;
    }

    /** Seconds left before the absolute cap ends this sign-in. */
    public function absoluteRemaining(Session $session): int
    {
        $started = (int) $session->get(self::STARTED, now()->getTimestamp());

        return max(0, $started + (int) config('session.absolute_lifetime') * 60 - now()->getTimestamp());
    }

    public function expired(Session $session): bool
    {
        return $this->applies($session) && $this->started($session) && $this->absoluteRemaining($session) === 0;
    }

    /**
     * What the browser needs to warn before a timeout, or null when the
     * session is exempt. Durations, not timestamps, so the client's clock
     * never has to agree with the server's.
     *
     * @return array{idle_seconds: int, absolute_remaining: int, warning_seconds: int}|null
     */
    public function forClient(Session $session): ?array
    {
        if (! $this->applies($session)) {
            return null;
        }

        return [
            'idle_seconds' => $this->idleSeconds(),
            'absolute_remaining' => $this->absoluteRemaining($session),
            'warning_seconds' => (int) config('session.idle_warning') * 60,
        ];
    }
}
