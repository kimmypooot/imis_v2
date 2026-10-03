<?php

namespace App\Support\Legacy;

use App\Models\Application;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Signs people in to the legacy PHP subsystems when they launch one from
 * IMIS, through the hand-off the subsystems already support (legacy
 * includes/session.php): the launch URL carries a one-time
 * `?sso_token=`, and the subsystem redeems it server-to-server at
 * SsoValidateController for the account, identified by its legacy id.
 *
 * Tokens are random, stored only as a SHA-256 hash, valid for
 * `legacy.sso.ttl` seconds, redeemable once, and only ever appended to
 * URLs on the configured legacy hosts.
 */
class LegacySso
{
    public function __construct(private readonly LegacyBridge $bridge) {}

    /** Whether launches to this URL get a sign-in token. */
    public function handles(string $url): bool
    {
        $host = Str::lower((string) parse_url($url, PHP_URL_HOST));

        return $host !== '' && in_array($host, array_map(Str::lower(...), config('legacy.sso.hosts')), true);
    }

    /**
     * The launch URL with a fresh sign-in token, or unchanged when the URL
     * isn't a legacy subsystem or the account has no legacy identity.
     */
    public function launchUrl(User $user, Application $application, string $url): string
    {
        if (! $this->handles($url)) {
            return $url;
        }

        $legacyId = $user->legacy_id ?? ($this->bridge->enabled() ? $this->bridge->sync($user) : null);

        if (! $legacyId) {
            return $url;
        }

        $token = Str::random(64);

        DB::table('sso_tokens')->insert([
            'token_hash' => hash('sha256', $token),
            'user_id' => $user->id,
            'application_id' => $application->id,
            'audience' => $this->audience($url),
            'expires_at' => now()->addSeconds(config('legacy.sso.ttl')),
            'created_at' => now(),
        ]);

        return $this->withQuery($url, 'sso_token='.$token);
    }

    /**
     * Uses up a token and returns its account, or null when the token is
     * unknown, expired, already used, meant for another system, or its
     * account can no longer sign in.
     */
    public function redeem(string $token, ?string $path): ?User
    {
        $record = DB::table('sso_tokens')->where('token_hash', hash('sha256', $token))->first();

        if (! $record || $record->used_at !== null || now()->greaterThanOrEqualTo($record->expires_at)) {
            return null;
        }

        if (($path !== null || config('legacy.sso.require_path')) && $this->audience((string) $path) !== $record->audience) {
            return null;
        }

        // Claimed atomically, so two simultaneous redemptions can't both succeed.
        $claimed = DB::table('sso_tokens')->where('id', $record->id)->whereNull('used_at')->update(['used_at' => now()]);

        $user = $claimed ? User::query()->find($record->user_id) : null;

        return $user?->isActive() && $user->legacy_id ? $user : null;
    }

    /**
     * The account in the shape legacy session.php expects (the same fields
     * its own login put into $_SESSION).
     *
     * @return array<string, int|string>
     */
    public function payload(User $user): array
    {
        return [
            'id' => (int) $user->legacy_id,
            'username' => $user->username,
            'fname' => (string) ($user->first_name ?? $user->name),
            'lname' => (string) $user->last_name,
            'mname' => (string) $user->middle_name,
            'minitial' => LegacyBridge::middleInitial($user->middle_name),
            'global_role' => $this->bridge->legacyRole($user, $this->bridge->currentLegacyRole($user)),
            'email' => (string) $user->email,
            'position' => (string) $user->position,
        ];
    }

    /** Deletes tokens a day past expiry; scheduled daily. */
    public function prune(): int
    {
        return DB::table('sso_tokens')->where('expires_at', '<', now()->subDay())->delete();
    }

    /** First path segment, lowercased: "/otrs/hrd/index" → "otrs". */
    private function audience(string $urlOrPath): string
    {
        $path = (string) (parse_url($urlOrPath, PHP_URL_PATH) ?? '');

        return Str::lower(Str::before(ltrim($path, '/'), '/'));
    }

    private function withQuery(string $url, string $pair): string
    {
        $fragment = Str::contains($url, '#') ? '#'.Str::after($url, '#') : '';
        $base = Str::before($url, '#');

        return $base.(Str::contains($base, '?') ? '&' : '?').$pair.$fragment;
    }
}
