<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * The shape the kit's ApprovalTrail draws: where a request is on its way
 * (leave, PDS, SALN), worked out by each module from its own data.
 *
 * States: done · current (where it is now) · returned (sent back to the
 * employee) · declined (stopped there) · upcoming.
 */
final class ApprovalTrail
{
    public const Done = 'done';

    public const Current = 'current';

    public const Returned = 'returned';

    public const Declined = 'declined';

    public const Upcoming = 'upcoming';

    /**
     * @param  array<int, array{label: string, state: string, detail: ?string}>  $steps
     * @return array{summary: ?string, steps: array<int, array{label: string, state: string, detail: ?string}>}
     */
    public static function make(array $steps, ?string $summary = null): array
    {
        return ['summary' => $summary, 'steps' => array_values($steps)];
    }

    /**
     * @return array{label: string, state: string, detail: ?string}
     */
    public static function step(string $label, string $state, ?string $detail = null): array
    {
        return ['label' => $label, 'state' => $state, 'detail' => $detail !== '' ? $detail : null];
    }

    /** "J. Cruz · Nov 18, 2026", leaving out whichever part is missing. */
    public static function byOn(?string $name, ?CarbonInterface $at): ?string
    {
        return implode(' · ', array_filter([$name, self::date($at)])) ?: null;
    }

    public static function date(?CarbonInterface $at): ?string
    {
        return $at?->format('M j, Y');
    }
}
