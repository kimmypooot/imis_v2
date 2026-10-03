<?php

namespace App\Http\Requests\Employee;

use App\Enums\EmploymentStatus;
use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('employee'));
    }

    /**
     * Shared by store (which adds `user_id`).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::employmentRules($this->route('employee'));
    }

    /**
     * @return array<string, mixed>
     */
    public static function employmentRules(?Employee $employee = null): array
    {
        return [
            'employee_number' => ['nullable', 'string', 'max:30', Rule::unique('employees', 'employee_number')->ignore($employee)],
            // Optional: a record can exist before HR knows it ("Not set").
            'employment_status' => ['nullable', new Enum(EmploymentStatus::class)],
            'original_appointment_date' => ['nullable', 'date', 'before_or_equal:today'],
            'separation_date' => ['nullable', 'date', 'after_or_equal:original_appointment_date'],
            // Why someone left (retirement, resignation, transfer, …) is
            // required once a separation date is set (and dropped without
            // one — see employmentData()).
            'separation_reason' => ['nullable', 'required_with:separation_date', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * The validated employment fields, with a separation reason only when
     * there is a separation date.
     *
     * @return array<string, mixed>
     */
    public function employmentData(): array
    {
        $data = collect($this->validated())->only(array_keys(self::employmentRules()))->all();

        if (blank($data['separation_date'] ?? null)) {
            $data['separation_reason'] = null;
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return self::fieldNames();
    }

    /**
     * @return array<string, string>
     */
    public static function fieldNames(): array
    {
        return [
            'user_id' => 'employee',
            'employee_number' => 'employee number',
            'employment_status' => 'employment status',
            'original_appointment_date' => 'original appointment date',
        ];
    }
}
