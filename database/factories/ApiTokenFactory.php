<?php

namespace Database\Factories;

use App\Enums\ApiIntegration;
use App\Models\ApiToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ApiToken>
 */
class ApiTokenFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $token = Str::random(48);

        return [
            'integration' => ApiIntegration::Cares,
            'name' => 'Production',
            'token_hash' => hash('sha256', $token),
            'token_hint' => substr($token, 0, 9),
        ];
    }
}
