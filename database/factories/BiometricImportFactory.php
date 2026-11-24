<?php

namespace Database\Factories;

use App\Models\BiometricImport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BiometricImport>
 */
class BiometricImportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'file_name' => 'attlog.dat',
            'file_hash' => hash('sha256', fake()->uuid()),
        ];
    }
}
