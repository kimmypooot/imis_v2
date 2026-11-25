<?php

namespace Tests\Feature\HR;

use App\Models\Role;
use App\Models\Saln;
use App\Models\SalnVersion;
use Database\Seeders\HrRoleSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * SALN foundation: the statement is encrypted at rest, versions never
 * change, references are stable, and only HRD gets `saln.manage`.
 */
class SalnSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_statement_is_encrypted_at_rest(): void
    {
        $saln = Saln::factory()->create(['statement' => ['declarant' => ['family_name' => 'DELA CRUZ']]]);

        $raw = DB::table('salns')->where('id', $saln->id)->value('statement');

        $this->assertStringNotContainsString('DELA CRUZ', $raw);
        $this->assertSame('DELA CRUZ', $saln->fresh()->statement['declarant']['family_name']);
    }

    public function test_audit_targets_use_a_stable_morph_name(): void
    {
        $this->assertSame('saln', Saln::factory()->create()->getMorphClass());
    }

    public function test_versions_cannot_be_updated(): void
    {
        $version = SalnVersion::factory()->create();

        $this->expectException(LogicException::class);

        $version->forceFill(['officer_name' => 'Someone Else'])->save();
    }

    public function test_version_statement_is_encrypted_at_rest(): void
    {
        $version = SalnVersion::factory()->create(['statement' => ['declarant' => ['family_name' => 'DELA CRUZ']]]);

        $this->assertStringNotContainsString('DELA CRUZ', DB::table('saln_versions')->where('id', $version->id)->value('statement'));
    }

    public function test_reference_format(): void
    {
        $saln = Saln::factory()->create(['reckoning_date' => '2025-12-31']);
        DB::table('salns')->where('id', $saln->id)->update(['id' => 42]);

        $this->assertSame('SALN-2025-00042', Saln::query()->findOrFail(42)->reference());
    }

    public function test_hr_roles_get_saln_manage_only(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(HrRoleSeeder::class);

        $hr = Role::query()->where('name', 'hr-officer')->firstOrFail();

        $this->assertTrue($hr->permissions()->where('name', 'saln.manage')->exists());
        $this->assertFalse(Role::query()->whereHas('permissions', fn ($q) => $q->where('name', 'saln.review'))
            ->where('name', '!=', 'super-admin')->exists());
    }
}
