<?php

namespace Database\Factories;

use App\Enums\FeedbackStatus;
use App\Enums\FeedbackType;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Feedback>
 */
class FeedbackFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => fake()->randomElement(FeedbackType::cases()),
            'subject' => fake()->sentence(5),
            'message' => fake()->paragraph(),
            'page_path' => '/dashboard',
            'status' => FeedbackStatus::New,
        ];
    }
}
