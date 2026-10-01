<?php

namespace App\Http\Requests\Role;

use App\Enums\RoleName;
use App\Models\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AssignPermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('managePermissions', $this->route('role'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'permission_ids' => ['present', 'array'],
            'permission_ids.*' => ['integer', 'distinct', 'exists:permissions,id'],
        ];
    }

    /**
     * Two privilege-escalation guards, both skipped for an actual Super
     * Administrator actor (Gate::before already lets them do anything, and
     * neither rule protects their own access — only a non-super-admin
     * actor's reach needs limiting):
     * - a role's permission set can only be *expanded* with permissions
     *   the actor already holds themselves — otherwise a `permissions.
     *   manage` holder could grant their own role (or any role) a
     *   permission they don't have, escalating their own access;
     * - the `super-admin` role's permission set cannot be touched at all
     *   by a non-super-admin, since this is a system-critical role.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $actor = $this->user();

            if ($actor->hasRole(RoleName::SuperAdmin)) {
                return;
            }

            $role = $this->route('role');

            if ($role->name === RoleName::SuperAdmin->value) {
                $validator->errors()->add(
                    'permission_ids',
                    'The Super Administrator role\'s permissions cannot be changed.',
                );

                return;
            }

            $requestedIds = array_map('intval', (array) $this->input('permission_ids', []));
            $currentIds = $role->permissions()->pluck('permissions.id')->all();
            $newIds = array_diff($requestedIds, $currentIds);

            if ($newIds === []) {
                return;
            }

            $grantablePermissionNames = $actor->permissions()->pluck('name');
            $unheldNewPermissionNames = Permission::query()
                ->whereKey($newIds)
                ->pluck('name')
                ->reject(fn (string $name) => $grantablePermissionNames->contains($name));

            if ($unheldNewPermissionNames->isNotEmpty()) {
                $validator->errors()->add(
                    'permission_ids',
                    'You cannot grant a permission you do not hold yourself: '.$unheldNewPermissionNames->implode(', ').'.',
                );
            }
        });
    }
}
