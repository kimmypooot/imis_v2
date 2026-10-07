<?php

namespace App\Enums;

/**
 * Sex as recorded on the account (and the PDS's sex at birth). Stored as
 * the lowercase value; shown with its label ("Male", "Female"), never raw.
 */
enum Sex: string
{
    case Male = 'male';
    case Female = 'female';

    public function label(): string
    {
        return match ($this) {
            self::Male => 'Male',
            self::Female => 'Female',
        };
    }

    /** The label for a stored value, or null when none is recorded. */
    public static function labelFor(?string $value): ?string
    {
        return self::tryFrom((string) $value)?->label();
    }
}
