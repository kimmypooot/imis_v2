<?php

namespace Database\Factories;

use App\Enums\Status;
use App\Enums\UnitType;
use App\Models\OrganizationalUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationalUnit>
 */
class OrganizationalUnitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'code' => strtoupper(fake()->unique()->lexify('UNIT-????')),
            'name' => $name,
            'slug' => OrganizationalUnit::generateUniqueSlug($name),
            'short_name' => fake()->lexify('???'),
            'unit_type' => fake()->randomElement(UnitType::cases()),
            'description' => fake()->sentence(),
            'status' => Status::Active,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => Status::Inactive]);
    }

    public function childOf(OrganizationalUnit $parent): static
    {
        return $this->state(fn () => ['parent_id' => $parent->id]);
    }
}
