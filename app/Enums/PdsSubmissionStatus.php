<?php

namespace App\Enums;

/**
 * A signed PDS submission's state. Only `submitted` awaits HR; a newer
 * submission supersedes an unreviewed one.
 */
enum PdsSubmissionStatus: string
{
    case Submitted = 'submitted';
    case Verified = 'verified';
    case Returned = 'returned';
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Awaiting verification',
            self::Verified => 'Verified',
            self::Returned => 'Returned',
            self::Superseded => 'Superseded',
        };
    }
}
