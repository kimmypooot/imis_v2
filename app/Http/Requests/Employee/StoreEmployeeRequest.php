<?php

namespace App\Http\Requests\Employee;

use App\Enums\RoleName;
use App\Models\Employee;
use Closure;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends UpdateEmployeeRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Employee::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id'),
                // One record per person.
                Rule::unique('employees', 'user_id'),
                // Separation of duties: HR doesn't set up their own record
                // (a Super Administrator may).
                function (string $attribute, mixed $value, Closure $fail) {
                    if ((int) $value === $this->user()->id && ! $this->user()->hasRole(RoleName::SuperAdmin)) {
                        $fail('Another HR officer has to set up your own employee record.');
                    }
                },
            ],
            ...self::employmentRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['user_id.unique' => 'This person already has an employee record.'];
    }
}
