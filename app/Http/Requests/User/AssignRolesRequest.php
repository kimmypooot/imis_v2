<?php

namespace App\Http\Requests\User;

use App\Enums\RoleName;
use App\Http\Requests\Concerns\GuardsLastSuperAdmin;
use App\Http\Requests\Concerns\GuardsSuperAdminRoleGrant;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AssignRolesRequest extends FormRequest
{
    use GuardsLastSuperAdmin;
    use GuardsSuperAdminRoleGrant;

    public function authorize(): bool
    {
        return $this->user()->can('assignRoles', $this->route('user'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role_ids' => ['present', 'array'],
            'role_ids.*' => ['integer', 'distinct', 'exists:roles,id'],
        ];
    }

    /**
     * Two privilege-escalation guards, both enforced here independently of
     * the frontend so they cannot be bypassed by hand-crafting the PUT
     * request:
     * - only an existing Super Administrator may *grant* the super-admin
     *   role to anyone, including themselves;
     * - the super-admin role may never be *removed* from the last
     *   remaining active Super Administrator, or the system would have no
     *   one left who can manage access.
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

                return;
            }

            $target = $this->route('user');
            $superAdminId = Role::query()->where('name', RoleName::SuperAdmin->value)->value('id');
            $keepsSuperAdmin = $superAdminId !== null && in_array($superAdminId, $roleIds, true);

            if (! $keepsSuperAdmin && $this->isLastActiveSuperAdmin($target)) {
                $validator->errors()->add(
                    'role_ids',
                    'The Super Administrator role cannot be removed from the last active Super Administrator.',
                );
            }
        });
    }
}
