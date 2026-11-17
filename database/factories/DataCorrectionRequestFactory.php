<?php

namespace Database\Factories;

use App\Enums\DataCorrectionStatus;
use App\Models\DataCorrectionRequest;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataCorrectionRequest>
 */
class DataCorrectionRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'field' => 'last_name',
            'current_value' => 'Cruz',
            'proposed_value' => 'Dela Cruz',
            'reason' => 'My surname was misspelled when the record was set up.',
            'status' => DataCorrectionStatus::Pending,
        ];
    }

    public function decided(DataCorrectionStatus $status = DataCorrectionStatus::Approved): static
    {
        return $this->state(['status' => $status, 'decided_at' => now()]);
    }
}
