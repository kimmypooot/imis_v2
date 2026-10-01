<?php

namespace Database\Factories;

use App\Models\ApplicationAccess;
use App\Models\ApplicationRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApplicationAccess>
 */
class ApplicationAccessFactory extends Factory
{
    /**
     * The application is always derived from the role, so a grant can't be
     * built pointing at another system's role.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'application_role_id' => ApplicationRole::factory(),
            'application_id' => fn (array $attributes) => ApplicationRole::query()
                ->findOrFail($attributes['application_role_id'])
                ->application_id,
            'user_id' => User::factory(),
        ];
    }

    public function forRole(ApplicationRole $role): static
    {
        return $this->state(fn () => [
            'application_role_id' => $role->id,
            'application_id' => $role->application_id,
        ]);
    }
}
