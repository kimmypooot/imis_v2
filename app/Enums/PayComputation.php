<?php

namespace App\Enums;

/**
 * HR-8: how a pay component's monthly amount is worked out.
 *
 * - fixed: the component's amount (PERA), unless the employee's pay
 *   profile gives another.
 * - percent: a rate of the basic salary, the salary first held between an
 *   optional floor and ceiling (PhilHealth on 10,000–100,000; Pag-IBIG on
 *   at most 10,000), unless the profile gives an amount.
 * - manual: only the amount on the employee's profile (withholding tax,
 *   RATA, cooperative dues); nothing without one.
 */
enum PayComputation: string
{
    case Fixed = 'fixed';
    case Percent = 'percent';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Fixed amount',
            self::Percent => '% of basic salary',
            self::Manual => 'Entered per employee',
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
