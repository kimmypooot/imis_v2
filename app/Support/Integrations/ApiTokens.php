<?php

namespace App\Support\Integrations;

use App\Enums\ApiIntegration;
use App\Models\ApiToken;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Str;

/**
 * The one place a connected system's bearer token is checked, issued and
 * revoked. A token is accepted when it matches an unrevoked, unexpired
 * token issued on the Integration Tokens page, or the system's legacy
 * `.env` secret (so existing connections keep working until rotated).
 */
class ApiTokens
{
    public function authorize(ApiIntegration $integration, ?string $bearer, ?string $ip = null): bool
    {
        $bearer = (string) $bearer;

        if ($bearer === '') {
            return false;
        }

        $legacy = (string) config($integration->configKey());

        if ($legacy !== '' && hash_equals($legacy, $bearer)) {
            return true;
        }

        $token = ApiToken::query()
            ->where('integration', $integration->value)
            ->where('token_hash', hash('sha256', $bearer))
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();

        if ($token === null) {
            return false;
        }

        // Once a minute is enough to answer "is this token still in use?"
        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now(), 'last_used_ip' => $ip])->saveQuietly();
        }

        return true;
    }

    /**
     * @return array{0: ApiToken, 1: string} the stored token and its plain text (shown once)
     */
    public function issue(ApiIntegration $integration, string $name, ?User $by, ?DateTimeInterface $expiresAt): array
    {
        $plain = 'imis_'.Str::random(48);

        $token = ApiToken::query()->create([
            'integration' => $integration,
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'token_hint' => substr($plain, 0, 9),
            'expires_at' => $expiresAt,
            'created_by' => $by?->id,
        ]);

        return [$token, $plain];
    }

    public function revoke(ApiToken $token, ?User $by): void
    {
        if ($token->isRevoked()) {
            return;
        }

        $token->forceFill(['revoked_at' => now(), 'revoked_by' => $by?->id])->save();
    }

    /**
     * Whether the integration's `.env` secret is set (so it is still accepted).
     */
    public function hasLegacySecret(ApiIntegration $integration): bool
    {
        return (string) config($integration->configKey()) !== '';
    }
}
