<?php

namespace App\Enums;

/**
 * HR-8: a monthly payroll is a draft (HR reviews and adjusts it) until it
 * is finalized. Finalized lines are what loans count as paid and what
 * payslips show; HR can reopen a run to correct it.
 */
enum PayrollRunStatus: string
{
    case Draft = 'draft';
    case Finalized = 'finalized';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
