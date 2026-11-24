<?php

namespace Database\Factories;

use App\Models\DtrSignatory;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DtrSignatory>
 */
class DtrSignatoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'period_from' => '2026-10-01',
            'period_to' => '2026-10-31',
            'signatory_user_id' => User::factory(),
        ];
    }
}
