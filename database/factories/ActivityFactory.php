<?php

namespace Database\Factories;

use App\Enums\ActivityMode;
use App\Enums\ActivityStatus;
use App\Models\Activity;
use App\Models\OrganizationalUnit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Activity>
 */
class ActivityFactory extends Factory
{
    /**
     * A morning activity on 10 March 2027.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organizational_unit_id' => OrganizationalUnit::factory(),
            'title' => fake()->sentence(4),
            'responsible' => null,
            'participants' => 'All staff',
            'venue' => 'Conference Room',
            'mode' => ActivityMode::FaceToFace,
            'remarks' => null,
            'year' => 2027,
            'month' => 3,
            'starts_on' => '2027-03-10',
            'ends_on' => '2027-03-10',
            'starts_at' => '09:00:00',
            'ends_at' => '12:00:00',
            'all_day' => false,
            'status' => ActivityStatus::Scheduled,
            'unplanned' => false,
        ];
    }

    /**
     * Planned for a month, date to follow.
     */
    public function monthOnly(int $year, int $month): static
    {
        return $this->state(fn () => [
            'year' => $year,
            'month' => $month,
            'starts_on' => null,
            'ends_on' => null,
            'starts_at' => null,
            'ends_at' => null,
            'status' => ActivityStatus::Planned,
        ]);
    }

    /**
     * Scheduled on a date, or from one date to another.
     */
    public function dated(string $startsOn, ?string $endsOn = null): static
    {
        $start = Carbon::parse($startsOn);

        return $this->state(fn () => [
            'year' => $start->year,
            'month' => $start->month,
            'starts_on' => $start->toDateString(),
            'ends_on' => $endsOn ?? $start->toDateString(),
            'status' => ActivityStatus::Scheduled,
        ]);
    }
}
