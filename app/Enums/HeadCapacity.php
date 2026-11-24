<?php

namespace App\Enums;

/**
 * In what capacity someone heads an office by designation (HR-1b): as
 * Officer-in-Charge, as Caretaker of an office with no head (e.g. a field
 * office without a Director II), or as the Head of an office that shares
 * their place of assignment (the Leyte field office's Director II over
 * Leyte I and II, which top management treats as one place, so not a
 * concurrent capacity). All three head the office, so all open its staff
 * records, may take its staff over as their supervisor, and show on the
 * Staffing page; the capacity is what the order says they are.
 */
enum HeadCapacity: string
{
    case OfficerInCharge = 'oic';
    case Caretaker = 'caretaker';
    case SamePlace = 'head';

    public function label(): string
    {
        return match ($this) {
            self::OfficerInCharge => 'Officer-in-Charge',
            self::Caretaker => 'Caretaker',
            self::SamePlace => 'Head (same place of assignment)',
        };
    }

    /** As shown beside a name or an office ("OIC of ESFO"). */
    public function shortLabel(): string
    {
        return match ($this) {
            self::OfficerInCharge => 'OIC',
            self::Caretaker => 'Caretaker',
            self::SamePlace => 'Head',
        };
    }

    /** Whether the office is a second one beside their own, as opposed to part of their one place of assignment. */
    public function isConcurrent(): bool
    {
        return $this !== self::SamePlace;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $capacity) => [
            'value' => $capacity->value,
            'label' => $capacity->isConcurrent() ? "{$capacity->label()} (heads the office)" : $capacity->label(),
        ], self::cases());
    }
}
