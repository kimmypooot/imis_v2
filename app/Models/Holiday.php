<?php

namespace App\Models;

use App\Enums\HolidayType;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * HR-6: an official holiday. Entered by HR from the proclamations for each
 * year; regular and special non-working days are skipped when counting
 * leave days.
 *
 * A repeating holiday falls on the same month and day every year. It is
 * entered once and kept under the placeholder year REPEATING_YEAR (a leap
 * year, so February 29 fits); HolidayCalendar places it in each year.
 */
class Holiday extends Model
{
    public const REPEATING_YEAR = 2000;

    protected $fillable = ['date', 'name', 'type', 'repeats_annually', 'proclamation'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'type' => HolidayType::class, 'repeats_annually' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (Holiday $holiday) {
            if ($holiday->repeats_annually && $holiday->date) {
                $holiday->date = self::repeatingDate($holiday->date->format('m-d'));
            }
        });
    }

    /**
     * The stored date of a repeating holiday on the given month and day.
     */
    public static function repeatingDate(string $monthDay): string
    {
        return self::REPEATING_YEAR.'-'.$monthDay;
    }

    protected function label(): Attribute
    {
        return Attribute::get(fn () => $this->repeats_annually
            ? "holiday {$this->name} (every {$this->date?->format('F j')})"
            : "holiday {$this->name} ({$this->date?->toDateString()})");
    }
}
