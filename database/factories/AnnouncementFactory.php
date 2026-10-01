<?php

namespace Database\Factories;

use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test-only factory. There is deliberately no announcement seeder — the
 * portal must never show invented announcements.
 *
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->sentence(4);

        return [
            'title' => $title,
            'slug' => Announcement::generateUniqueSlug($title),
            'summary' => fake()->sentence(),
            'body' => fake()->paragraphs(2, true),
            'status' => AnnouncementStatus::Draft,
            'publish_at' => null,
            'expires_at' => null,
            'featured' => false,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => AnnouncementStatus::Published,
            'publish_at' => now()->subDay(),
        ]);
    }

    public function scheduled(): static
    {
        return $this->state(fn () => [
            'status' => AnnouncementStatus::Published,
            'publish_at' => now()->addDay(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => AnnouncementStatus::Published,
            'publish_at' => now()->subWeek(),
            'expires_at' => now()->subDay(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn () => [
            'status' => AnnouncementStatus::Archived,
            'publish_at' => now()->subWeek(),
        ]);
    }

    public function featured(): static
    {
        return $this->state(fn () => ['featured' => true]);
    }
}
