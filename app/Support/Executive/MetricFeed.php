<?php

namespace App\Support\Executive;

use InvalidArgumentException;

/**
 * The one shape every figure on the Executive Dashboard takes, whether an
 * IMIS module computed it or a connected system sent it (its summary
 * feed, version 1 — see docs/executive-feed.md):
 *
 *     {
 *       "version": 1,
 *       "generated_at": "2027-01-10T08:00:00+08:00",
 *       "metrics": [
 *         { "key": "exams.scheduled", "label": "Exams scheduled this month",
 *           "value": 12, "unit": "count", "period": "January 2027",
 *           "breakdown": [ { "label": "Leyte FO", "value": 3 } ] }
 *       ]
 *     }
 *
 * Totals only: a feed must never carry a person's name or record. Links
 * (`href`) are kept only for IMIS's own figures, which also name the
 * permission the link needs; a system's feed can't add links.
 */
final class MetricFeed
{
    public const VERSION = 1;

    public const UNITS = ['count', 'percent', 'days', 'hours', 'peso'];

    public const MAX_METRICS = 50;

    public const MAX_BREAKDOWN = 50;

    /**
     * Checks a connected system's feed and returns its figures, cleaned.
     *
     * @param  mixed  $payload  the decoded JSON body
     * @return array<int, array<string, mixed>>
     *
     * @throws InvalidArgumentException when the feed doesn't follow the format
     */
    public static function fromFeed(mixed $payload): array
    {
        if (! is_array($payload) || ($payload['version'] ?? null) !== self::VERSION) {
            throw new InvalidArgumentException('Not a version '.self::VERSION.' summary feed.');
        }

        if (! is_array($payload['metrics'] ?? null) || ! array_is_list($payload['metrics'])) {
            throw new InvalidArgumentException('The feed has no "metrics" list.');
        }

        return self::clean($payload['metrics'], allowLinks: false);
    }

    /**
     * @param  array<int, mixed>  $metrics
     * @return array<int, array<string, mixed>>
     *
     * @throws InvalidArgumentException
     */
    public static function clean(array $metrics, bool $allowLinks = true): array
    {
        if (count($metrics) > self::MAX_METRICS) {
            throw new InvalidArgumentException('More than '.self::MAX_METRICS.' figures.');
        }

        $seen = [];

        return array_map(function (mixed $metric) use (&$seen, $allowLinks) {
            $metric = self::metric($metric, $allowLinks);

            if (isset($seen[$metric['key']])) {
                throw new InvalidArgumentException("Figure \"{$metric['key']}\" appears twice.");
            }
            $seen[$metric['key']] = true;

            return $metric;
        }, array_values($metrics));
    }

    /**
     * @return array<string, mixed>
     */
    private static function metric(mixed $metric, bool $allowLinks): array
    {
        if (! is_array($metric)) {
            throw new InvalidArgumentException('A figure is not an object.');
        }

        $key = $metric['key'] ?? null;
        if (! is_string($key) || ! preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)*$/', $key) || strlen($key) > 100) {
            throw new InvalidArgumentException('A figure has no valid "key" (lowercase letters, digits, _ and dots).');
        }

        $value = $metric['value'] ?? null;
        if ($value !== null && ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value))) {
            throw new InvalidArgumentException("Figure \"{$key}\" has a value that isn't a number.");
        }

        $unit = $metric['unit'] ?? 'count';
        if (! in_array($unit, self::UNITS, true)) {
            throw new InvalidArgumentException("Figure \"{$key}\" has an unknown unit.");
        }

        $clean = [
            'key' => $key,
            'label' => self::text($metric['label'] ?? null, 120) ?? throw new InvalidArgumentException("Figure \"{$key}\" has no label."),
            'value' => $value === null ? null : round((float) $value, 2),
            'unit' => $unit,
            'period' => self::text($metric['period'] ?? null, 60),
            'note' => self::text($metric['note'] ?? null, 200),
            'breakdown' => self::breakdown($metric['breakdown'] ?? [], $key),
        ];

        if ($allowLinks && isset($metric['href']) && is_string($metric['href']) && str_starts_with($metric['href'], '/') && ! str_starts_with($metric['href'], '//')) {
            $clean['href'] = $metric['href'];
            $clean['permission'] = is_string($metric['permission'] ?? null) ? $metric['permission'] : null;
        }

        return $clean;
    }

    /**
     * @return array<int, array{label: string, value: float|null}>
     */
    private static function breakdown(mixed $rows, string $key): array
    {
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > self::MAX_BREAKDOWN) {
            throw new InvalidArgumentException("Figure \"{$key}\" has an invalid breakdown.");
        }

        return array_map(function (mixed $row) use ($key) {
            $label = is_array($row) ? self::text($row['label'] ?? null, 120) : null;
            $value = is_array($row) ? ($row['value'] ?? null) : null;

            if ($label === null || ($value !== null && ! is_int($value) && ! is_float($value))) {
                throw new InvalidArgumentException("Figure \"{$key}\" has an invalid breakdown row.");
            }

            return ['label' => $label, 'value' => $value === null ? null : round((float) $value, 2)];
        }, $rows);
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim(strip_tags($value)), 0, $max);
    }
}
