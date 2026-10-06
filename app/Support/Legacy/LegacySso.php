<?php

namespace App\Support\Legacy;

use App\Enums\UnitType;
use App\Models\Application;
use App\Models\OrganizationalUnit;
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
    /** Marks a token as issued for a Laravel system rather than a legacy page. */
    private const HUB_PREFIX = 'host:';

    public function __construct(private readonly LegacyBridge $bridge) {}

    /** Whether launches to this URL get a sign-in token. */
    public function handles(string $url): bool
    {
        $host = Str::lower((string) parse_url($url, PHP_URL_HOST));

        return $host !== '' && in_array($host, array_map(Str::lower(...), config('legacy.sso.hosts')), true);
    }

    /** Whether this URL is a Laravel system under IMIS (config `hub_hosts`). */
    public function handlesHub(string $url): bool
    {
        $authority = $this->authority($url);

        return $authority !== '' && in_array($authority, array_map(Str::lower(...), config('legacy.sso.hub_hosts')), true);
    }

    /**
     * The launch URL with a fresh sign-in token, or unchanged when the URL
     * isn't a legacy subsystem or the account has no legacy identity.
     */
    public function launchUrl(User $user, Application $application, string $url): string
    {
        if ($this->handlesHub($url)) {
            return $this->withQuery($url, 'sso_token='.$this->issue($user, $application, self::HUB_PREFIX.$this->authority($url)));
        }

        if (! $this->handles($url)) {
            return $url;
        }

        $legacyId = $user->legacy_id ?? ($this->bridge->enabled() ? $this->bridge->sync($user) : null);

        if (! $legacyId) {
            return $url;
        }

        return $this->withQuery($url, 'sso_token='.$this->issue($user, $application, $this->audience($url)));
    }

    private function issue(User $user, Application $application, string $audience): string
    {
        $token = Str::random(64);

        DB::table('sso_tokens')->insert([
            'token_hash' => hash('sha256', $token),
            'user_id' => $user->id,
            'application_id' => $application->id,
            'audience' => $audience,
            'expires_at' => now()->addSeconds(config('legacy.sso.ttl')),
            'created_at' => now(),
        ]);

        return $token;
    }

    /**
     * Uses up a token and returns its account, or null when the token is
     * unknown, expired, already used, meant for another system, or its
     * account can no longer sign in.
     */
    public function redeem(string $token, ?string $path): ?User
    {
        $record = $this->claim($token, fn (string $audience) => ! str_starts_with($audience, self::HUB_PREFIX)
            && (($path === null && ! config('legacy.sso.require_path')) || $this->audience((string) $path) === $audience));

        $user = $record ? User::query()->find($record->user_id) : null;

        return $user?->isActive() && $user->legacy_id ? $user : null;
    }

    /**
     * Redeems a token issued for a Laravel system under IMIS. The system
     * must name itself (`audience`, its host[:port]) and it has to be the
     * one the token was issued for.
     *
     * @return array<string, int|string|null>|null
     */
    public function redeemHub(string $token, string $audience): ?array
    {
        $record = $this->claim($token, fn (string $issued) => $issued === self::HUB_PREFIX.Str::lower($audience));

        $user = $record ? User::query()->find($record->user_id) : null;

        if (! $user?->isActive()) {
            return null;
        }

        $role = Application::query()->find($record->application_id)?->accessFor($user)?->role;
        $office = $this->office($user);

        return [
            'id' => $user->id,
            'username' => $user->username,
            'fname' => (string) ($user->first_name ?? $user->name),
            'lname' => (string) $user->last_name,
            'mname' => (string) $user->middle_name,
            'email' => (string) $user->email,
            'position' => (string) $user->position,
            'role' => $role?->name,
            'role_code' => $role?->code,
            // Public URL of the header photo (an unguessable file name), or null.
            'photo_url' => $user->profile_photo_url,
            'office_code' => $office?->code,
            'office_type' => $office?->unit_type->value,
        ];
    }

    /**
     * The person's office for a system that scopes by office: their unit, or
     * the nearest ancestor that is a regional unit, field office or satellite
     * office (so a section under a field office counts as that field office).
     */
    private function office(User $user): ?OrganizationalUnit
    {
        $unit = $user->organizationalUnit;
        $units = $unit ? collect([$unit])->merge($unit->ancestors()) : collect();

        return $units->first(fn (OrganizationalUnit $u) => $u->unit_type !== UnitType::Other);
    }

    /**
     * Uses up a token whose audience passes `$audienceOk`; null when it is
     * unknown, expired, already used or meant for something else.
     *
     * @param  callable(string): bool  $audienceOk
     */
    private function claim(string $token, callable $audienceOk): ?object
    {
        $record = DB::table('sso_tokens')->where('token_hash', hash('sha256', $token))->first();

        if (! $record || $record->used_at !== null || now()->greaterThanOrEqualTo($record->expires_at)) {
            return null;
        }

        if (! $audienceOk((string) $record->audience)) {
            return null;
        }

        // Claimed atomically, so two simultaneous redemptions can't both succeed.
        $claimed = DB::table('sso_tokens')->where('id', $record->id)->whereNull('used_at')->update(['used_at' => now()]);

        return $claimed ? $record : null;
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

    /** "host" or "host:port", lowercased. */
    private function authority(string $url): string
    {
        $host = Str::lower((string) parse_url($url, PHP_URL_HOST));
        $port = parse_url($url, PHP_URL_PORT);

        return $host === '' ? '' : $host.($port ? ':'.$port : '');
    }

    private function withQuery(string $url, string $pair): string
    {
        $fragment = Str::contains($url, '#') ? '#'.Str::after($url, '#') : '';
        $base = Str::before($url, '#');

        return $base.(Str::contains($base, '?') ? '&' : '?').$pair.$fragment;
    }
}
