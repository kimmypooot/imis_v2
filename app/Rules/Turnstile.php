<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Verifies a Cloudflare Turnstile token server-side (the legacy login's
 * "Security Check"). The browser widget alone proves nothing — only this
 * siteverify call does. Fails closed: if Cloudflare can't be reached, the
 * sign-in is refused rather than waved through.
 */
class Turnstile implements ValidationRule
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(private readonly ?string $ip = null) {}

    public static function enabled(): bool
    {
        return filled(config('services.turnstile.site_key')) && filled(config('services.turnstile.secret_key'));
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('Please complete the security check.');

            return;
        }

        try {
            $response = Http::asForm()->timeout(5)->post(self::VERIFY_URL, array_filter([
                'secret' => config('services.turnstile.secret_key'),
                'response' => $value,
                'remoteip' => $this->ip,
            ]));

            if ($response->successful() && $response->json('success') === true) {
                return;
            }
        } catch (Throwable $e) {
            Log::warning('Turnstile verification unavailable', ['error' => $e->getMessage()]);
        }

        $fail('The security check failed. Please try again.');
    }
}
