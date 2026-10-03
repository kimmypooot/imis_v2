<?php

namespace App\Http\Requests\Employee;

use App\Models\Employee;
use App\Support\HR\EmployeeRecords;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Setting up employee records for many existing accounts at once, with
 * employment status "Not set" for HR to fill in. Only accounts that are
 * still candidates (active, no record, not yourself) are accepted.
 */
class BulkStoreEmployeesRequest extends FormRequest
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
        $candidates = app(EmployeeRecords::class)->candidates($this->user())->pluck('id')->all();

        return [
            'user_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'user_ids.*' => ['integer', 'distinct', Rule::in($candidates)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_ids.required' => 'Tick at least one person.',
            'user_ids.*.in' => 'Someone in the list can no longer be set up (they may already have a record). Reload and try again.',
        ];
    }
}
