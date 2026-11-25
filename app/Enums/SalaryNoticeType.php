<?php

namespace App\Enums;

/**
 * The two salary notices HRD issues: a Notice of Salary Adjustment for a
 * legislated salary schedule (NBC Annex B-1), and a Notice of Step
 * Increment due to length of service (CSC-DBM JC No. 1, s. 2012).
 */
enum SalaryNoticeType: string
{
    case Nosa = 'nosa';
    case Nosi = 'nosi';

    public function label(): string
    {
        return match ($this) {
            self::Nosa => 'Notice of Salary Adjustment',
            self::Nosi => 'Notice of Step Increment Due to Length of Service',
        };
    }

    /** "NOSA" / "NOSI": the prefix of HRD's file names. */
    public function code(): string
    {
        return strtoupper($this->value);
    }
}
