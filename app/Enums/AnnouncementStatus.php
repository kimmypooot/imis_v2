<?php

namespace App\Enums;

/**
 * Editorial lifecycle of an announcement. `Published` alone does not make
 * an announcement visible — it must also be inside its publish/expiry
 * window (see Announcement::scopePublished()). The allowed transitions are
 * deliberately small: this is a controlled publication switch, not an
 * editorial review workflow.
 */
enum AnnouncementStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Draft => in_array($target, [self::Published, self::Archived], true),
            self::Published => in_array($target, [self::Draft, self::Archived], true),
            // An archived announcement must be restored to a draft (and
            // reviewed) before it can be published again.
            self::Archived => $target === self::Draft,
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => ucfirst($case->value)],
            self::cases(),
        );
    }
}
