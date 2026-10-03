<?php

namespace App\Models;

use App\Enums\HolidayType;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * HR-6: an official holiday. Entered by HR from the proclamations for each
 * year; regular and special non-working days are skipped when counting
 * leave days.
 */
class Holiday extends Model
{
    protected $fillable = ['date', 'name', 'type', 'proclamation'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'type' => HolidayType::class];
    }

    protected function label(): Attribute
    {
        return Attribute::get(fn () => "holiday {$this->name} ({$this->date?->toDateString()})");
    }
}
