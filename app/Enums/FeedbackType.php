<?php

namespace App\Enums;

/**
 * What the sender is offering: how they find IMIS, an idea for something
 * new, or a recommendation to change how something works.
 */
enum FeedbackType: string
{
    case Feedback = 'feedback';
    case Suggestion = 'suggestion';
    case Recommendation = 'recommendation';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function hint(): string
    {
        return match ($this) {
            self::Feedback => 'What works well, or what does not.',
            self::Suggestion => 'An idea for something new or better.',
            self::Recommendation => 'A change to how something should work.',
        };
    }

    /**
     * @return array<int, array{value: string, label: string, hint: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label(), 'hint' => $case->hint()],
            self::cases(),
        );
    }
}
