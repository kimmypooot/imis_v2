<?php

namespace Database\Factories;

use App\Models\DtrSignature;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DtrSignature>
 */
class DtrSignatureFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'period_from' => '2026-10-01',
            'period_to' => '2026-10-31',
            'code' => strtoupper(fake()->unique()->bothify('????########')),
            'content_hash' => hash('sha256', fake()->uuid()),
            'signature_path' => null,
            'signed_at' => now(),
            'superseded_at' => null,
        ];
    }
}
