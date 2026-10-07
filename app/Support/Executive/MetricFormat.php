<?php

namespace App\Support\Executive;

/**
 * A figure written out for alerts and the weekly summary, the way the
 * dashboard shows it (the dashboard itself formats in the browser).
 */
final class MetricFormat
{
    public static function value(?float $value, string $unit): string
    {
        if ($value === null) {
            return 'no figure';
        }

        $number = number_format($value, floor($value) == $value ? 0 : ($unit === 'peso' ? 2 : 1));

        return match ($unit) {
            'percent' => "{$number}%",
            'peso' => "₱{$number}",
            'days' => "{$number} ".($value == 1 ? 'day' : 'days'),
            'hours' => "{$number} ".($value == 1 ? 'hour' : 'hours'),
            default => $number,
        };
    }
}
