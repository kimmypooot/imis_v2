<?php

namespace Tests\Feature\HR\Concerns;

use App\Enums\UnitType;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

/**
 * Shared set-up for SALN tests: a complete statement, the Office of the
 * Regional Director with its Director IV, and users with permissions.
 */
trait SalnFixtures
{
    /**
     * @return array<string, mixed>
     */
    protected function validStatement(array $overrides = []): array
    {
        return array_replace([
            'declarant' => [
                'family_name' => 'DELA CRUZ', 'first_name' => 'JUAN', 'middle_initial' => 'P.',
                'position' => 'Human Resource Specialist II', 'agency' => 'Civil Service Commission Regional Office VIII',
                'office_address' => 'Palo, Leyte',
            ],
            'spouse' => null,
            'other_spouses' => [],
            'children' => [],
            'real_properties' => [],
            'personal_properties' => [['owner' => 'declarant', 'description' => 'Laptop', 'acquisition_year' => '2024', 'acquisition_cost' => '45000.00']],
            'liabilities' => [],
            'no_business_interests' => true,
            'business_interests' => [],
            'no_relatives' => true,
            'relatives' => [],
            'government_id' => ['type' => 'UMID', 'number' => '0111-2222333-4', 'issued_on' => '2020-01-15'],
        ], $overrides);
    }

    protected function regionalDirector(string $name = 'Atty. Regional Director'): User
    {
        $ord = OrganizationalUnit::query()->firstWhere('code', 'ORD')
            ?? OrganizationalUnit::factory()->create(['code' => 'ORD', 'name' => 'Office of the Regional Director', 'unit_type' => UnitType::RegionalSupportUnit]);

        return User::factory()->create(['name' => $name, 'position' => 'Director IV', 'organizational_unit_id' => $ord->id]);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    protected function userWith(array $permissions, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $role = Role::factory()->create();

        foreach ($permissions as $permission) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $permission], ['display_name' => $permission]));
        }

        $user->roles()->attach($role);

        return $user;
    }
}
