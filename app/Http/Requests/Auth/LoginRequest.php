<?php

namespace App\Http\Requests\Auth;

use App\Enums\AuditEvent;
use App\Models\User;
use App\Rules\Turnstile;
use App\Support\Audit\AuditLogger;
use App\Support\Usernames;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Username + password sign-in, as in the legacy IMIS. `mode=admin` is the
 * legacy "Superadmin Login" toggle: it only changes where the user lands
 * (see AuthenticatedSessionController) — it never grants anything.
 */
class LoginRequest extends FormRequest
{
    public const MAX_ATTEMPTS = 5;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['username' => Usernames::normalize($this->input('username'))]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:'.Usernames::MAX_LENGTH],
            'password' => ['required', 'string'],
            'mode' => ['nullable', 'in:employee,admin'],
            ...(Turnstile::enabled()
                ? ['cf-turnstile-response' => ['bail', 'required', new Turnstile($this->ip())]]
                : []),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cf-turnstile-response.required' => 'Please complete the security check.',
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $username = $this->string('username')->value();
        $user = User::where('username', $username)->first();

        if (! $user || ! Hash::check($this->string('password'), $user->password)) {
            RateLimiter::hit($this->throttleKey());

            // Only the attempted username is kept — never the password.
            app(AuditLogger::class)->recordFor(
                null,
                AuditEvent::AuthLoginFailed,
                "Failed sign-in attempt for {$username}",
                $user,
                ['username' => $username, 'reason' => $user ? 'wrong_password' : 'unknown_account'],
            );

            throw ValidationException::withMessages([
                'username' => 'Invalid username or password.',
            ]);
        }

        if (! $user->isActive()) {
            RateLimiter::hit($this->throttleKey());

            app(AuditLogger::class)->recordFor(
                null,
                AuditEvent::AuthLoginBlocked,
                "Sign-in blocked for inactive account {$user->username}",
                $user,
                ['username' => $user->username, 'reason' => 'inactive_account'],
            );

            throw ValidationException::withMessages([
                'username' => 'This account is inactive. Please contact the system administrator.',
            ]);
        }

        Auth::login($user, $this->boolean('remember'));
        $user->recordLogin();

        RateLimiter::clear($this->throttleKey());
    }

    public function wantsAdminConsole(): bool
    {
        return $this->input('mode') === 'admin';
    }

    /**
     * Ensure the login request is not rate limited. The remaining lockout
     * time is flashed so the login page can show a live countdown, like
     * the legacy one.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());
        $this->session()->flash('lockout_seconds', $seconds);

        throw ValidationException::withMessages([
            'username' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate($this->string('username')->lower().'|'.$this->ip());
    }
}
