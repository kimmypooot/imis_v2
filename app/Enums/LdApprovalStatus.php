<?php

namespace App\Enums;

/**
 * HRD's review of an L&D record. Employee entries start pending; HRD's
 * own entries are approved at once (as in ldms). Reports count approved
 * only; the PDS shows approved and pending.
 */
enum LdApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending Review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }
}
