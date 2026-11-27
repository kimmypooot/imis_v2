<?php

namespace App\Support\Activities;

use DomainException;

/**
 * Calendar of Activities: the date or time of an activity that already had
 * a date was changed without saying why. Every move needs a reason.
 */
class MoveReasonRequired extends DomainException
{
    public function __construct(string $message = 'Give the reason for moving this activity.')
    {
        parent::__construct($message);
    }
}
