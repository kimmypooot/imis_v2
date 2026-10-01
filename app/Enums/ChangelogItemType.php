<?php

namespace App\Enums;

/**
 * Kinds of entries in a release note (legacy imis_changelog_items.item_type).
 */
enum ChangelogItemType: string
{
    case Feature = 'feature';
    case Improvement = 'improvement';
    case Fix = 'fix';

    public function label(): string
    {
        return match ($this) {
            self::Feature => 'New',
            self::Improvement => 'Improved',
            self::Fix => 'Fixed',
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
