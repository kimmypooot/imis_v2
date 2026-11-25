<?php

namespace App\Enums;

/**
 * HR-8: a monthly payroll's path through HRD and Accounting.
 *
 * - Draft: HRD prepares it, with every deduction but withholding tax.
 * - WithAccounting: submitted to MSD, where the Accountant III reviews it
 *   and enters each person's withholding tax; HRD can't change it.
 * - Returned: back with HRD, who finalizes it (or sends it to Accounting
 *   again).
 * - Finalized: locked; loans count its lines as paid and payslips can be
 *   requested. Reopening sends it back to Returned.
 */
enum PayrollRunStatus: string
{
    case Draft = 'draft';
    case WithAccounting = 'with_accounting';
    case Returned = 'returned';
    case Finalized = 'finalized';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::WithAccounting => 'With Accounting',
            self::Returned => 'Returned to HRD',
            self::Finalized => 'Finalized',
        };
    }

    /** HRD can change lines while the payroll is with them. */
    public function isWithHrd(): bool
    {
        return $this === self::Draft || $this === self::Returned;
    }
}
