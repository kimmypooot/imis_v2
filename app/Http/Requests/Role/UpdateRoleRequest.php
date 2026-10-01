<?php

namespace App\Http\Requests\Role;

use App\Enums\RoleName;
use App\Enums\RoleStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('role'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('roles', 'name')->ignore($this->route('role'))],
            'display_name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', new Enum(RoleStatus::class)],
        ];
    }

    /**
     * The `super-admin` role's `name` is the only thing `Gate::before`
     * checks to grant the system-wide authorization bypass — renaming or
     * deactivating it would permanently lock everyone out, including an
     * actual Super Administrator making the change. This is therefore
     * unconditional: it is never bypassed, not even for a Super
     * Administrator actor.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $role = $this->route('role');

            if ($role->name !== RoleName::SuperAdmin->value) {
                return;
            }

            if ($this->input('name') !== RoleName::SuperAdmin->value) {
                $validator->errors()->add('name', 'The Super Administrator role cannot be renamed.');
            }

            if ($this->input('status') !== RoleStatus::Active->value) {
                $validator->errors()->add('status', 'The Super Administrator role cannot be deactivated.');
            }
        });
    }
}
