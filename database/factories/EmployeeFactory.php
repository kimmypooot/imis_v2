<?php

namespace Database\Factories;

use App\Enums\EmploymentStatus;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'employee_number' => fake()->unique()->numerify('CSC-#####'),
            'employment_status' => EmploymentStatus::Permanent,
            'original_appointment_date' => fake()->dateTimeBetween('-20 years', '-1 year')->format('Y-m-d'),
            'separation_date' => null,
            'separation_reason' => null,
            'remarks' => null,
        ];
    }

    public function separated(): static
    {
        return $this->state(fn () => [
            'separation_date' => now()->subMonth()->toDateString(),
            'separation_reason' => 'Retirement',
        ]);
    }
}
