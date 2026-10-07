<?php

namespace App\Enums;

/**
 * In what capacity someone heads an office by designation (HR-1b): as
 * Officer-in-Charge, or as Caretaker of an office with no head (e.g. a
 * field office without a Director II). Both head the office, so both open
 * its staff records, may take its staff over as their supervisor, and show
 * on the Staffing page; the capacity is what the order says they are.
 */
enum HeadCapacity: string
{
    case OfficerInCharge = 'oic';
    case Caretaker = 'caretaker';

    public function label(): string
    {
        return match ($this) {
            self::OfficerInCharge => 'Officer-in-Charge',
            self::Caretaker => 'Caretaker',
        };
    }

    /** As shown beside a name or an office ("OIC of ESFO"). */
    public function shortLabel(): string
    {
        return match ($this) {
            self::OfficerInCharge => 'OIC',
            self::Caretaker => 'Caretaker',
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $capacity) => ['value' => $capacity->value, 'label' => $capacity->label()], self::cases());
    }
}
