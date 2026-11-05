<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\LeaveCardMonth;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveCardMonth>
 */
class LeaveCardMonthFactory extends Factory
{
    protected $model = LeaveCardMonth::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'year' => 2024,
            'month' => 1,
            'vl_earned' => 1.25,
            'vl_used' => 0,
            'vl_balance' => 10,
            'sl_earned' => 1.25,
            'sl_used' => 0,
            'sl_balance' => 20,
            'particulars' => '(00-00-00) ok',
            'applications' => null,
        ];
    }
}
