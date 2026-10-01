<?php

namespace Database\Factories;

use App\Enums\RoleStatus;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word().'-'.fake()->randomNumber(4);

        return [
            'name' => Str::slug($name),
            'display_name' => Str::headline($name),
            'description' => fake()->sentence(),
            'status' => RoleStatus::Active,
        ];
    }
}
