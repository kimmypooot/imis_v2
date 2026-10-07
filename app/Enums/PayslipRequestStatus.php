<?php

namespace App\Enums;

/**
 * HR-8: an employee's request for a month's payslip, decided by the head
 * of the Human Resource Division (or whoever is designated head).
 */
enum PayslipRequestStatus: string
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
