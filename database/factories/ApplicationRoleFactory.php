<?php

namespace Database\Factories;

use App\Enums\Status;
use App\Models\Application;
use App\Models\ApplicationRole;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApplicationRole>
 */
class ApplicationRoleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'application_id' => Application::factory(),
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'description' => fake()->sentence(),
            'launch_url' => null,
            'status' => Status::Active,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => Status::Inactive]);
    }
}
