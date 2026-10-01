<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Login usernames, following the legacy IMIS convention: the initials of
 * every given/middle name plus the full surname, lowercased
 * ("Juan Santos Cruz" → "jscruz"), with a numeric suffix when taken
 * ("jscruz2"). Accounts here hold a single `name`, so the last word is
 * taken as the surname; an administrator can always edit the result.
 */
class Usernames
{
    /**
     * Allowed characters, also used by validation.
     */
    public const PATTERN = '/^[a-z0-9][a-z0-9._-]*$/';

    public const MAX_LENGTH = 64;

    public static function generate(string $name, ?int $ignoreUserId = null): string
    {
        $base = self::base($name);
        $candidate = $base;
        $suffix = 2;

        while (self::taken($candidate, $ignoreUserId)) {
            $candidate = Str::limit($base, self::MAX_LENGTH - strlen((string) $suffix), '').$suffix;
            $suffix++;
        }

        return $candidate;
    }

    public static function normalize(?string $username): string
    {
        return Str::lower(trim((string) $username));
    }

    private static function base(string $name): string
    {
        $words = collect(preg_split('/\s+/', Str::ascii(trim($name))))
            ->map(fn (string $word) => preg_replace('/[^a-z0-9]/', '', Str::lower($word)))
            ->filter()
            ->values();

        if ($words->isEmpty()) {
            return 'user';
        }

        $surname = $words->pop();
        $base = $words->map(fn (string $word) => $word[0])->implode('').$surname;

        return Str::limit($base, self::MAX_LENGTH - 4, '');
    }

    private static function taken(string $username, ?int $ignoreUserId): bool
    {
        return User::query()
            ->where('username', $username)
            ->when($ignoreUserId, fn ($query, $id) => $query->whereKeyNot($id))
            ->exists();
    }
}
