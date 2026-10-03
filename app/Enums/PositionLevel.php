<?php

namespace App\Enums;

/**
 * HR-4: CSC position levels (first, second, third).
 */
enum PositionLevel: string
{
    case First = 'first';
    case Second = 'second';
    case Third = 'third';

    public function label(): string
    {
        return match ($this) {
            self::First => 'First level',
            self::Second => 'Second level',
            self::Third => 'Third level',
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
