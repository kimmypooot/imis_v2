<?php

namespace App\Enums;

/**
 * Calendar of Activities: where an activity stands. `planned` has only a
 * month; giving it a date makes it `scheduled`. "Moved" is not a status:
 * an activity has moved when it has move records, so a moved activity
 * that is later held is both moved and done.
 */
enum ActivityStatus: string
{
    case Planned = 'planned';
    case Scheduled = 'scheduled';
    case Cancelled = 'cancelled';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planned',
            self::Scheduled => 'Scheduled',
            self::Cancelled => 'Cancelled',
            self::Done => 'Done',
        };
    }
}
