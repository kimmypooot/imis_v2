<?php

namespace Tests\Feature\Legacy;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Application;
use App\Models\ApplicationRole;
use App\Models\Role;
use App\Models\User;
use App\Support\Legacy\LegacyBridge;
use App\Support\SystemAccess\SystemAccessManager;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Mirroring accounts and system access into a fictional legacy database
 * (a second in-memory connection shaped like the legacy tables).
 */
class LegacyBridgeTest extends TestCase
{
    use RefreshDatabase;

    private ConnectionInterface $legacy;

    private Application $otrs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->withoutDefer();

        config(['database.connections.legacy_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        config(['legacy.bridge' => true, 'legacy.connection' => 'legacy_test']);
        $this->legacy = DB::connection('legacy_test');
        $this->createLegacySchema();

        $this->legacy->table('imis_projects')->insert([['id' => 10, 'code_name' => 'OTRS'], ['id' => 11, 'code_name' => 'LEGACYONLY']]);
        $this->legacy->table('imis_access_roles')->insert(['id' => 100, 'project_id' => 10, 'name' => 'Admin', 'project_url' => '/otrs/admin']);

        $this->otrs = Application::factory()->create(['short_name' => 'OTRS']);
    }

    protected function tearDown(): void
    {
        DB::purge('legacy_test');

        parent::tearDown();
    }

    public function test_a_new_account_gets_a_legacy_account(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Juan', 'middle_name' => 'Dela', 'last_name' => 'Cruz', 'sex' => 'male', 'position' => 'Analyst',
        ]);

        $row = $this->legacy->table('users_cscro8')->sole();
        $this->assertSame((int) $row->id, $user->fresh()->legacy_id);
        $this->assertSame(['Juan', 'Cruz', 'Dela', 'D.', 'Male', $user->username, 'Analyst', 'Active', 'user'],
            [$row->fname, $row->lname, $row->mname, $row->minitial, $row->sex, $row->username, $row->position, $row->status, $row->role]);
        // Signs in to the legacy pages with the same password.
        $this->assertTrue(password_verify('password', $row->password));
    }

    public function test_the_legacy_first_name_carries_the_honorific(): void
    {
        User::factory()->create(['honorific' => 'Atty.', 'first_name' => 'Juan', 'last_name' => 'Cruz']);

        $this->assertSame('Atty. Juan', $this->legacy->table('users_cscro8')->sole()->fname);
    }

    public function test_an_existing_legacy_account_is_matched_and_keeps_what_imis_lacks(): void
    {
        $this->legacyUser(['id' => 7, 'username' => 'jdcruz', 'email' => 'old@example.test', 'type' => 'hrd', 'role' => 'admin']);

        $user = User::factory()->create(['username' => 'jdcruz', 'email' => null, 'status' => UserStatus::Inactive]);

        $row = $this->legacy->table('users_cscro8')->sole();
        $this->assertSame(7, $user->fresh()->legacy_id);
        $this->assertSame(['old@example.test', 'hrd', 'Inactive', 'admin'], [$row->email, $row->type, $row->status, $row->role]);
    }

    public function test_super_administrators_are_legacy_superadmins(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->superAdmin())
            ->put("/users/{$user->id}/roles", ['role_ids' => [Role::where('name', RoleName::SuperAdmin->value)->value('id')]])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('superadmin', $this->legacy->table('users_cscro8')->where('username', $user->username)->value('role'));
    }

    public function test_access_is_mirrored_and_roles_added_here_are_created(): void
    {
        $user = User::factory()->create();
        $legacyId = $user->fresh()->legacy_id;
        $this->legacy->table('imis_system_access')->insert(['user_id' => $legacyId, 'project_id' => 11, 'role_id' => 999]);
        $access = app(SystemAccessManager::class);

        $admin = ApplicationRole::factory()->for($this->otrs)->create(['name' => 'Admin']);
        $access->set($this->otrs, $user, $admin);
        $this->assertSame(100, (int) $this->grant($legacyId, 10)->role_id);

        $viewer = ApplicationRole::factory()->for($this->otrs)->create(['name' => 'Viewer', 'launch_url' => 'https://legacy.example.test/otrs/view']);
        $access->set($this->otrs, $user, $viewer);
        $created = $this->legacy->table('imis_access_roles')->where('name', 'Viewer')->sole();
        $this->assertSame('https://legacy.example.test/otrs/view', $created->project_url);
        $this->assertSame((int) $created->id, (int) $this->grant($legacyId, 10)->role_id);

        $access->set($this->otrs, $user, null);
        $this->assertNull($this->grant($legacyId, 10));
        // A system IMIS doesn't manage is never touched.
        $this->assertNotNull($this->grant($legacyId, 11));
    }

    public function test_nothing_is_written_while_the_bridge_is_off(): void
    {
        config(['legacy.bridge' => false]);

        $user = User::factory()->create();

        $this->assertSame(0, $this->legacy->table('users_cscro8')->count());
        $this->assertNull($user->fresh()->legacy_id);
        $this->artisan('imis:sync-legacy')->expectsOutputToContain('Skipped')->assertSuccessful();
    }

    public function test_the_sync_command_catches_up_every_account(): void
    {
        $users = LegacyBridge::withoutSyncing(fn () => User::factory()->count(3)->create());

        $this->assertSame(0, $this->legacy->table('users_cscro8')->count());

        $this->artisan('imis:sync-legacy')->expectsOutputToContain('Synced 3 account(s).')->assertSuccessful();
        $this->assertSame(3, $this->legacy->table('users_cscro8')->count());
        $this->assertTrue($users->every(fn (User $user) => $user->fresh()->legacy_id !== null));
    }

    private function grant(int $legacyId, int $projectId): ?object
    {
        return $this->legacy->table('imis_system_access')->where('user_id', $legacyId)->where('project_id', $projectId)->first();
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', RoleName::SuperAdmin->value)->value('id'));

        return $user;
    }

    private function legacyUser(array $values): void
    {
        $this->legacy->table('users_cscro8')->insert($values + ['fname' => 'Juan', 'lname' => 'Cruz', 'password' => 'x', 'status' => 'Active']);
    }

    private function createLegacySchema(): void
    {
        $schema = $this->legacy->getSchemaBuilder();

        $schema->create('users_cscro8', function (Blueprint $table) {
            $table->increments('id');
            foreach (['fname', 'lname', 'mname', 'minitial', 'sex', 'username', 'password', 'email', 'position', 'fo_rsu', 'status', 'type', 'role', 'profile'] as $column) {
                $table->string($column)->default('');
            }
            $table->string('first_day')->default('0000-00-00');
            $table->string('birthday')->default('0000-00-00');
            $table->integer('exam_fo_id')->default(0);
            $table->integer('itg_id')->default(0);
        });

        $schema->create('imis_projects', function (Blueprint $table) {
            $table->increments('id');
            $table->string('code_name');
        });

        $schema->create('imis_access_roles', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('project_id');
            $table->string('name');
            $table->string('project_url');
            $table->boolean('is_external')->default(false);
        });

        $schema->create('imis_system_access', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('project_id');
            $table->integer('role_id');
            $table->timestamp('granted_at')->useCurrent();
            $table->integer('granted_by')->nullable();
            $table->unique(['user_id', 'project_id']);
        });
    }
}
