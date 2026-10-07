<?php

namespace App\Enums;

/**
 * HR-8: what a pay component is. Earnings add to gross pay; deductions
 * and loan amortizations come off it. A loan component is a loan type
 * (GSIS Consolidated Loan, Pag-IBIG MPL…): each employee's loans of that
 * type carry their own amounts (EmployeeLoan).
 */
enum PayComponentKind: string
{
    case Earning = 'earning';
    case Deduction = 'deduction';
    case Loan = 'loan';

    public function label(): string
    {
        return match ($this) {
            self::Earning => 'Allowance / earning',
            self::Deduction => 'Deduction',
            self::Loan => 'Loan',
        };
    }

    /** On a payslip, loans are deductions. */
    public function isDeduction(): bool
    {
        return $this !== self::Earning;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
