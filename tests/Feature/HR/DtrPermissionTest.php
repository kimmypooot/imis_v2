<?php

namespace Tests\Feature\HR;

use App\Models\BiometricPunch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * DTR permissions: `dtr.view` reads punches and mappings, `dtr.manage`
 * uploads logs and maps IDs (and also reads).
 */
class DtrPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_dtr_manage_may_view_and_manage(): void
    {
        $user = $this->userWith(['dtr.manage']);

        $this->assertTrue(Gate::forUser($user)->allows('viewAny', BiometricPunch::class));
        $this->assertTrue(Gate::forUser($user)->allows('manage', BiometricPunch::class));
    }

    public function test_dtr_view_may_only_view(): void
    {
        $user = $this->userWith(['dtr.view']);

        $this->assertTrue(Gate::forUser($user)->allows('viewAny', BiometricPunch::class));
        $this->assertFalse(Gate::forUser($user)->allows('manage', BiometricPunch::class));
    }

    public function test_a_user_with_neither_permission_is_denied(): void
    {
        $user = $this->userWith([]);

        $this->assertFalse(Gate::forUser($user)->allows('viewAny', BiometricPunch::class));
        $this->assertFalse(Gate::forUser($user)->allows('manage', BiometricPunch::class));
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();

        foreach ($permissions as $permission) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $permission], ['display_name' => $permission]));
        }

        $user->roles()->attach($role);

        return $user;
    }
}
