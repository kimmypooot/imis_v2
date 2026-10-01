<?php

namespace Database\Factories;

use App\Enums\Status;
use App\Enums\Visibility;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->catchPhrase();

        return [
            'name' => $name,
            'slug' => Service::generateUniqueSlug($name),
            'description' => fake()->sentence(),
            'icon' => 'clipboard',
            'external_url' => null,
            'status' => Status::Active,
            'visibility' => Visibility::Authenticated,
            'featured' => false,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => Status::Inactive]);
    }

    public function administrative(): static
    {
        return $this->state(fn () => ['visibility' => Visibility::Administrative]);
    }

    public function featured(): static
    {
        return $this->state(fn () => ['featured' => true]);
    }
}
