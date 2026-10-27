<?php

namespace App\Enums;

/**
 * HR-8: where a pay component is grouped on the employee's Earnings &
 * Deductions page. Only a grouping: nothing is computed from it.
 */
enum PayCategory: string
{
    case Allowance = 'allowance';
    case Mandatory = 'mandatory';
    case GovernmentLoan = 'government_loan';
    case OtherLoan = 'other_loan';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Allowance => 'Allowance / earning',
            self::Mandatory => 'Mandatory government contribution',
            self::GovernmentLoan => 'Government loan',
            self::OtherLoan => 'Other loan',
            self::Other => 'Other',
        };
    }

    /** What an uncategorized component of this kind falls under. */
    public static function defaultFor(PayComponentKind $kind): self
    {
        return match ($kind) {
            PayComponentKind::Earning => self::Allowance,
            PayComponentKind::Loan => self::OtherLoan,
            PayComponentKind::Deduction => self::Other,
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
