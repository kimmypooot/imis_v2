<?php

namespace Database\Factories;

use App\Enums\SalaryNoticeType;
use App\Models\Employee;
use App\Models\SalaryNotice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalaryNotice>
 */
class SalaryNoticeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => SalaryNoticeType::Nosa,
            'employee_id' => Employee::factory(),
            'notice_date' => '2027-01-20',
            'effective_date' => '2027-01-01',
            'basis' => 'National Budget Circular No. 999 s. 2027 dated 15 January 2027, implementing Executive Order No. 64 s. 2024 dated 02 August 2024',
            'addressee' => fake()->name(),
            'position_title' => 'Administrative Officer IV',
            'item_number' => 'ADOF4-1-2005',
            'salary_grade' => 15,
            'from_step' => 1,
            'from_salary' => 30000,
            'to_step' => 1,
            'to_salary' => 31000,
            'signatory_name' => 'ATTY. JUANA DELA CRUZ',
            'signatory_position' => 'Director IV',
        ];
    }

    public function nosi(): static
    {
        return $this->state(fn () => [
            'type' => SalaryNoticeType::Nosi,
            'salary_schedule_id' => null,
            'salutation' => 'Dear Ms. Dela Cruz:',
            'to_step' => 2,
        ]);
    }
}
