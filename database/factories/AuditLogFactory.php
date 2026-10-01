<?php

namespace Database\Factories;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test-only factory for exercising the viewer's filters. Real entries are
 * only ever written by AuditLogger.
 *
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $event = fake()->randomElement(AuditEvent::cases());

        return [
            'actor_id' => null,
            'actor_label' => null,
            'event' => $event,
            'action' => $event->action(),
            'target_type' => null,
            'target_id' => null,
            'target_label' => null,
            'description' => fake()->sentence(),
            'ip_address' => fake()->ipv4(),
            'user_agent' => 'PHPUnit',
            'metadata' => null,
            'created_at' => now(),
        ];
    }
}
