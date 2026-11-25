<?php

namespace App\Enums;

/**
 * Who administers the oath: the Regional Director by default, a notary
 * outside the office as the exception, or another named officer (for the
 * Regional Director's own SALN).
 */
enum SalnOathMode: string
{
    case RegionalDirector = 'regional_director';
    case Notary = 'notary';
    case OtherOfficer = 'other_officer';

    public function label(): string
    {
        return match ($this) {
            self::RegionalDirector => 'Before the Regional Director',
            self::Notary => 'Before a notary public',
            self::OtherOfficer => 'Before another administering officer',
        };
    }
}
