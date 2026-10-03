<?php

namespace App\Enums;

/**
 * HR-6: what a leave-credit ledger entry is. Credits are positive,
 * deductions negative; a balance is the sum of the entries.
 */
enum LedgerEntryKind: string
{
    case Opening = 'opening';
    case Accrual = 'accrual';
    case Grant = 'grant';
    case Deduction = 'deduction';
    case Adjustment = 'adjustment';
    case Lapse = 'lapse';
    case Monetization = 'monetization';

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Opening balance',
            self::Accrual => 'Monthly accrual',
            self::Grant => 'Annual grant',
            self::Deduction => 'Leave taken',
            self::Adjustment => 'Adjustment',
            self::Lapse => 'Lapsed',
            self::Monetization => 'Monetization',
        };
    }

    /**
     * Kinds HR may post by hand (the others come from accrual runs and
     * approved leave).
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function manualOptions(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], [self::Opening, self::Adjustment]);
    }
}
