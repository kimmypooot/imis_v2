<?php

namespace App\Enums;

/**
 * A staff member's request to correct their own employee record, decided
 * by HR.
 */
enum DataCorrectionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
