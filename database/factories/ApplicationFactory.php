<?php

namespace Database\Factories;

use App\Enums\Status;
use App\Enums\Visibility;
use App\Models\Application;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Application>
 */
class ApplicationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' System',
            'short_name' => fake()->lexify('????'),
            'description' => fake()->sentence(),
            'icon' => 'home',
            'url' => fake()->url(),
            'category' => fake()->randomElement(['records', 'examinations', 'human-resources']),
            'external' => true,
            'featured' => false,
            'status' => Status::Active,
            'visibility' => Visibility::Authenticated,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => Status::Inactive]);
    }

    public function featured(): static
    {
        return $this->state(fn () => ['featured' => true]);
    }

    public function administrative(): static
    {
        return $this->state(fn () => ['visibility' => Visibility::Administrative]);
    }

    public function withoutUrl(): static
    {
        return $this->state(fn () => ['url' => null]);
    }
}
