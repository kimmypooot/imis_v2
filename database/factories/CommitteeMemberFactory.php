<?php

namespace Database\Factories;

use App\Enums\Status;
use App\Models\Committee;
use App\Models\CommitteeMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommitteeMember>
 */
class CommitteeMemberFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'committee_id' => Committee::factory(),
            'user_id' => User::factory(),
            'position' => fake()->randomElement(['Chairperson', 'Vice Chairperson', 'Member', 'Secretary']),
            'effective_from' => fake()->date(),
            'effective_until' => null,
            'status' => Status::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => Status::Inactive]);
    }

    public function ended(): static
    {
        return $this->state(fn () => [
            'status' => Status::Inactive,
            'effective_until' => fake()->date(),
        ]);
    }
}
