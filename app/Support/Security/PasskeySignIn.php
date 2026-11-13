<?php

namespace App\Support\Security;

use App\Enums\AuditEvent;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Events\PasskeyVerified;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;

/**
 * Fingerprint / face / PIN sign-in (passkeys, WebAuthn), wired into the same
 * sign-in as the username and password: an inactive account is refused,
 * session limits and login history start the same way, and every
 * registration, removal and passkey sign-in is audited. The package
 * (laravel/passkeys) owns the cryptography and the routes; nothing here
 * ever sees a fingerprint, only a signature from the device.
 */
class PasskeySignIn
{
    public static function register(): void
    {
        Passkeys::authorizeLoginUsing(self::authorizeLogin(...));

        app()->singleton(PasskeyLoginResponse::class, fn () => new class implements PasskeyLoginResponse
        {
            public function toResponse($request)
            {
                return PasskeySignIn::finishSignIn($request);
            }
        });

        Event::listen(PasskeyVerified::class, function (PasskeyVerified $event) {
            self::audit($event->user, AuditEvent::AuthPasskeyLogin, "{$event->user->name} signed in with a passkey ({$event->passkey->name})", $event->passkey);
        });
        Event::listen(PasskeyRegistered::class, function (PasskeyRegistered $event) {
            self::audit($event->user, AuditEvent::AuthPasskeyRegistered, "{$event->user->name} added a passkey ({$event->passkey->name})", $event->passkey);
        });
        Event::listen(PasskeyDeleted::class, function (PasskeyDeleted $event) {
            self::audit($event->user, AuditEvent::AuthPasskeyRemoved, "{$event->user->name} removed a passkey ({$event->passkey->name})", $event->passkey);
        });
    }

    /**
     * Only an active account may sign in; the same rule and wording as the
     * password sign-in.
     *
     * @throws ValidationException
     */
    public static function authorizeLogin(Request $request, PasskeyUser $user, Passkey $passkey): bool
    {
        if ($user instanceof User && ! $user->isActive()) {
            app(AuditLogger::class)->recordFor(
                null,
                AuditEvent::AuthLoginBlocked,
                "Sign-in blocked for inactive account {$user->username}",
                $user,
                ['username' => $user->username, 'reason' => 'inactive_account', 'method' => 'passkey'],
            );

            throw ValidationException::withMessages([
                'credential' => 'This account is inactive. Please contact the system administrator.',
            ]);
        }

        return true;
    }

    /**
     * What the password sign-in does after the guard logs the person in:
     * the idle and absolute limits, the login history row and the splash.
     * The package has already logged in and regenerated the session.
     */
    public static function finishSignIn(Request $request): mixed
    {
        $user = $request->user();

        $user->recordLogin();
        app(SessionLimits::class)->start($request->session(), $request->boolean('remember'));
        app(LoginHistoryRecorder::class)->started($request, $user);
        $request->session()->flash('signed_in', 'portal');

        $target = redirect()->intended(route('dashboard', absolute: false))->getTargetUrl();

        return $request->wantsJson() ? response()->json(['redirect' => $target]) : redirect($target);
    }

    private static function audit(mixed $user, AuditEvent $event, string $description, Passkey $passkey): void
    {
        if ($user instanceof User) {
            app(AuditLogger::class)->recordFor($user, $event, $description, $user, ['passkey' => $passkey->name]);
        }
    }
}
