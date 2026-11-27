<?php

namespace Database\Factories;

use App\Enums\ActivityMode;
use App\Enums\SeriesFrequency;
use App\Models\ActivitySeries;
use App\Models\OrganizationalUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivitySeries>
 */
class ActivitySeriesFactory extends Factory
{
    /**
     * Every Monday from March to June 2027.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organizational_unit_id' => OrganizationalUnit::factory(),
            'frequency' => SeriesFrequency::Weekly,
            'interval' => 1,
            'weekday' => 1,
            'day_of_month' => null,
            'nth_weekday' => null,
            'starts_on' => '2027-03-01',
            'until' => '2027-06-30',
            'skip_holidays' => true,
            'template' => [
                'title' => 'Weekly staff meeting',
                'responsible' => null,
                'participants' => 'All staff',
                'venue' => 'Conference Room',
                'mode' => ActivityMode::FaceToFace->value,
                'remarks' => null,
                'starts_at' => '08:00:00',
                'ends_at' => '09:00:00',
                'all_day' => false,
            ],
        ];
    }
}
