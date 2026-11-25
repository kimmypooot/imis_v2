<?php

namespace Database\Factories;

use App\Enums\SalnOathMode;
use App\Models\Saln;
use App\Models\SalnVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalnVersion>
 */
class SalnVersionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'saln_id' => Saln::factory(),
            'version' => 1,
            'reference' => 'SALN-2025-'.fake()->unique()->numerify('#####'),
            'statement' => [],
            'oath_mode' => SalnOathMode::RegionalDirector,
            'officer_name' => 'Regional Director',
            'officer_title' => 'Director IV',
        ];
    }
}
