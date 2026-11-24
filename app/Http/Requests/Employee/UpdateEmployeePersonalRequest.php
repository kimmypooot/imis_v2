<?php

namespace App\Http\Requests\Employee;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * HR editing the person's own details from the employee record: name, sex
 * and birthday. Username, email, office and position stay with the
 * account (Manage Users) and plantilla.
 */
class UpdateEmployeePersonalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('updatePersonal', $this->route('employee'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'honorific' => ['nullable', Rule::in(User::HONORIFICS)],
            'first_name' => ['required', 'string', 'max:64'],
            'middle_name' => ['nullable', 'string', 'max:64'],
            'last_name' => ['required', 'string', 'max:64'],
            'name_extension' => ['nullable', 'string', 'max:10'],
            'sex' => ['nullable', 'in:male,female'],
            'birthday' => ['nullable', 'date', 'after:1900-01-01', 'before:today'],
        ];
    }
}
