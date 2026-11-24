<?php

namespace Database\Factories;

use App\Models\BiometricImport;
use App\Models\BiometricPunch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BiometricPunch>
 */
class BiometricPunchFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'biometric_import_id' => BiometricImport::factory(),
            'biometric_id' => (string) fake()->numberBetween(1, 999),
            'punched_at' => fake()->dateTimeBetween('-1 month')->format('Y-m-d H:i:s'),
        ];
    }
}
