<?php

namespace Database\Factories;

use App\Models\DtrAdjustment;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DtrAdjustment>
 */
class DtrAdjustmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'date' => fake()->date(),
            'kind' => 'credit',
            'time_in' => null,
            'time_out' => null,
            'remark' => 'Official business',
        ];
    }
}
