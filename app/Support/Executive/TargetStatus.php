<?php

namespace App\Support\Executive;

use App\Models\ExecutiveTarget;

/**
 * Where a figure stands against its target: on track, at risk (short of
 * the target, but within its tolerance) or behind. Null when the figure
 * has no value or no target has been agreed yet.
 */
final class TargetStatus
{
    public const ON_TRACK = 'on_track';

    public const AT_RISK = 'at_risk';

    public const BEHIND = 'behind';

    public const LABELS = [self::ON_TRACK => 'On track', self::AT_RISK => 'At risk', self::BEHIND => 'Behind'];

    public static function of(?float $value, ?ExecutiveTarget $target): ?string
    {
        if ($value === null || $target === null) {
            return null;
        }

        $margin = abs($target->target) * $target->tolerance / 100;

        if ($target->direction === ExecutiveTarget::LOWER) {
            return match (true) {
                $value <= $target->target => self::ON_TRACK,
                $value <= $target->target + $margin => self::AT_RISK,
                default => self::BEHIND,
            };
        }

        return match (true) {
            $value >= $target->target => self::ON_TRACK,
            $value >= $target->target - $margin => self::AT_RISK,
            default => self::BEHIND,
        };
    }

    /** The key a target is stored under: "source/metric". */
    public static function key(string $source, string $metric): string
    {
        return "{$source}/{$metric}";
    }
}
