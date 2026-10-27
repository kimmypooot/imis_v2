<?php

namespace App\Enums;

/**
 * Where a submission stands. Administrators move it along by hand; it is
 * never closed on the sender's behalf.
 */
enum FeedbackStatus: string
{
    case New = 'new';
    case InReview = 'in_review';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::InReview => 'Under review',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
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
