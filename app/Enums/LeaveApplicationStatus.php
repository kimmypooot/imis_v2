<?php

namespace App\Enums;

/**
 * HR-6b: where a leave application stands.
 */
enum LeaveApplicationStatus: string
{
    case ForReview = 'for_review';
    case Returned = 'returned';
    case Approved = 'approved';
    case Disapproved = 'disapproved';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::ForReview => 'For Review',
            self::Returned => 'Returned for Correction',
            self::Approved => 'Approved',
            self::Disapproved => 'Disapproved',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Still holds its days (counts against balances and overlaps). */
    public function isOpen(): bool
    {
        return in_array($this, [self::ForReview, self::Returned], true);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
