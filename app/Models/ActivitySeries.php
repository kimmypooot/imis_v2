<?php

namespace App\Models;

use App\Enums\SeriesFrequency;
use Database\Factories\ActivitySeriesFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Calendar of Activities: the rule of a repeating activity. Saving it
 * creates each occurrence as a real activity; `template` holds what every
 * occurrence starts with (title, responsible, participants, venue, mode,
 * remarks, starts_at, ends_at, all_day). `weekday` is ISO (1 = Monday);
 * `nth_weekday` (1–5) with `weekday` means e.g. "every 1st Monday".
 */
class ActivitySeries extends Model
{
    /** @use HasFactory<ActivitySeriesFactory> */
    use HasFactory;

    protected $table = 'activity_series';

    protected $fillable = [
        'organizational_unit_id', 'frequency', 'interval', 'weekday', 'day_of_month', 'nth_weekday',
        'starts_on', 'until', 'skip_holidays', 'template', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'frequency' => SeriesFrequency::class,
            'interval' => 'integer',
            'weekday' => 'integer',
            'day_of_month' => 'integer',
            'nth_weekday' => 'integer',
            'starts_on' => 'date:Y-m-d',
            'until' => 'date:Y-m-d',
            'skip_holidays' => 'boolean',
            'template' => 'array',
        ];
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class, 'organizational_unit_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class, 'series_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
