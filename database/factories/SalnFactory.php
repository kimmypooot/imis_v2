<?php

namespace Database\Factories;

use App\Enums\SalnOathMode;
use App\Enums\SalnStatus;
use App\Enums\SalnType;
use App\Models\Employee;
use App\Models\Saln;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Saln>
 */
class SalnFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'type' => SalnType::Annual,
            'reckoning_date' => '2025-12-31',
            'status' => SalnStatus::Draft,
            'current_version' => 0,
            'oath_mode' => SalnOathMode::RegionalDirector,
            'statement' => [],
        ];
    }
}
