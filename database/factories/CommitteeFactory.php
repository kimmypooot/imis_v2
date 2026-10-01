<?php

namespace Database\Factories;

use App\Enums\Status;
use App\Models\Committee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Committee>
 */
class CommitteeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' Committee',
            'short_name' => fake()->lexify('????'),
            'description' => fake()->sentence(),
            'status' => Status::Active,
            'effective_from' => null,
            'effective_until' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => Status::Inactive]);
    }
}
