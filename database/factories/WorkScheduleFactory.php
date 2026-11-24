<?php

namespace Database\Factories;

use App\Models\WorkSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkSchedule>
 */
class WorkScheduleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true).' schedule',
            'start_time' => '08:00:00',
            'latest_arrival' => '08:00:00',
            'required_minutes' => 480,
            'break_start' => '12:00:00',
            'break_minutes' => 60,
            'rest_weekdays' => [],
        ];
    }
}
