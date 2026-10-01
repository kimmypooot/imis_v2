<?php

namespace Tests\Unit\Models;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MassAssignmentProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_silently_ignores_unlisted_attributes_on_mass_assignment(): void
    {
        $role = Role::create([
            'name' => 'test-role',
            'display_name' => 'Test Role',
            'status' => 'active',
            'id' => 999,
        ]);

        $this->assertNotSame(999, $role->id);
    }

    public function test_permission_silently_ignores_unlisted_attributes_on_mass_assignment(): void
    {
        $permission = Permission::create([
            'name' => 'test.permission',
            'display_name' => 'Test Permission',
            'id' => 999,
        ]);

        $this->assertNotSame(999, $permission->id);
    }
}
