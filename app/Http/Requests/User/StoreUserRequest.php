<?php

namespace App\Http\Requests\User;

use App\Http\Requests\Concerns\GuardsSuperAdminRoleGrant;
use App\Http\Requests\User\Concerns\ProfileRules;
use App\Http\Requests\User\Concerns\UsernameRules;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreUserRequest extends FormRequest
{
    use GuardsSuperAdminRoleGrant;
    use ProfileRules;
    use UsernameRules;

    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->profileRules(),
            'profile_photo' => ['nullable', ...$this->photoRules()],
            // Optional: generated from the name parts (legacy convention) when left blank.
            'username' => ['nullable', ...$this->usernameRules()],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'role_ids' => ['sometimes', 'array'],
            'role_ids.*' => ['integer', 'distinct', 'exists:roles,id'],
        ];
    }

    /**
     * Same privilege-escalation guard as assigning roles to an existing
     * user: only a Super Administrator may create an account that starts
     * out holding the super-admin role.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $roleIds = array_map('intval', (array) $this->input('role_ids', []));

            if ($this->isGrantingSuperAdminWithoutAuthority($this->user(), $roleIds)) {
                $validator->errors()->add(
                    'role_ids',
                    'Only a Super Administrator may assign the Super Administrator role.',
                );
            }
        });
    }
}
