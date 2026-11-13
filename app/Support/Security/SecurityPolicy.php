<?php

namespace App\Support\Security;

use App\Models\User;
use App\Support\Settings\Settings;
use Illuminate\Validation\Rules\Password;
use Throwable;

/**
 * The account security rules an administrator can tune on System
 * Settings → Security: session timeouts, sign-in lockout and the
 * password policy. Everything that enforces those rules reads them here,
 * never from the settings directly.
 */
class SecurityPolicy
{
    /**
     * Lets the idle-timeout and session-limit settings override the
     * server defaults in config/session.php. Run at boot, before the
     * session starts; if settings can't be read yet (fresh install, no
     * database), the server defaults stand.
     */
    public static function applySessionConfig(): void
    {
        try {
            $idle = Settings::get('security_idle_timeout_minutes');
            $absolute = Settings::get('security_session_limit_minutes');
        } catch (Throwable) {
            return;
        }

        if ($idle) {
            config(['session.lifetime' => (int) $idle]);
        }
        if ($absolute) {
            config(['session.absolute_lifetime' => (int) $absolute]);
        }
    }

    /** Whether the login page may offer saved-account cards on a device. */
    public static function rememberAccountsEnabled(): bool
    {
        return (bool) Settings::get('security_remember_accounts');
    }

    /** Whether people may sign in with, and add, fingerprint / face (passkey) devices. */
    public static function passkeysEnabled(): bool
    {
        return (bool) Settings::get('security_passkeys');
    }

    public static function maxLoginAttempts(): int
    {
        return (int) Settings::get('security_login_max_attempts');
    }

    public static function lockoutSeconds(): int
    {
        return (int) Settings::get('security_lockout_minutes') * 60;
    }

    /**
     * The rule behind Password::defaults(): letters and numbers always,
     * plus whatever the settings add, and a breached-password check in
     * production.
     */
    public static function passwordRule(): Password
    {
        $rule = Password::min(self::minLength())->letters()->numbers();

        if (Settings::get('password_policy_mixed_case')) {
            $rule->mixedCase();
        }
        if (Settings::get('password_policy_symbols')) {
            $rule->symbols();
        }

        return app()->isProduction() ? $rule->uncompromised() : $rule;
    }

    /**
     * The same rules in words, for the forms that set a password.
     *
     * @return array<int, string>
     */
    public static function passwordRequirements(): array
    {
        return array_values(array_filter([
            'At least '.self::minLength().' characters',
            'Letters and numbers',
            Settings::get('password_policy_mixed_case') ? 'Upper- and lowercase letters' : null,
            Settings::get('password_policy_symbols') ? 'At least one symbol' : null,
            self::historyDepth() > 0
                ? 'Not one of your last '.(self::historyDepth() + 1).' passwords'
                : 'Different from your current password',
            app()->isProduction() ? 'Not found in a known data breach' : null,
        ]));
    }

    public static function minLength(): int
    {
        return (int) Settings::get('password_policy_min_length');
    }

    /** Previous passwords (besides the current one) that can't be reused. */
    public static function historyDepth(): int
    {
        return (int) Settings::get('password_policy_history');
    }

    /** 0 when passwords never expire. */
    public static function expiryDays(): int
    {
        return (int) Settings::get('password_policy_expiry_days');
    }

    /**
     * Expired, or set by a Super Administrator and not yet replaced by
     * its owner: either way, Change Password comes before anything else.
     */
    public static function mustChangePassword(User $user): bool
    {
        return $user->must_change_password || self::passwordExpired($user);
    }

    public static function passwordExpired(User $user): bool
    {
        $days = self::expiryDays();

        return $days > 0
            && $user->password_changed_at !== null
            && $user->password_changed_at->lte(now()->subDays($days));
    }
}
