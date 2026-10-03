<?php

namespace App\Enums;

/**
 * HR-6: holiday types. Regular and special non-working holidays are not
 * working days (they're skipped when counting leave days); a special
 * working holiday is.
 */
enum HolidayType: string
{
    case Regular = 'regular';
    case SpecialNonWorking = 'special_non_working';
    case SpecialWorking = 'special_working';

    public function label(): string
    {
        return match ($this) {
            self::Regular => 'Regular holiday',
            self::SpecialNonWorking => 'Special non-working day',
            self::SpecialWorking => 'Special working day',
        };
    }

    public function isDayOff(): bool
    {
        return $this !== self::SpecialWorking;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
