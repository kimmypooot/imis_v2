<?php

namespace Tests\Feature;

use App\Enums\AnnouncementStatus;
use App\Enums\RoleName;
use App\Enums\Status;
use App\Enums\UserStatus;
use App\Models\Announcement;
use App\Models\Application;
use App\Models\ApplicationAccess;
use App\Models\ApplicationRole;
use App\Models\Changelog;
use App\Models\Committee;
use App\Models\LoginHistory;
use App\Models\OrganizationalUnit;
use App\Models\SystemAccessLog;
use App\Models\User;
use App\Support\Legacy\LegacyImporter;
use Database\Seeders\RegionalOfficeSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Runs the importer against a small, entirely fictional legacy database
 * built on a second in-memory connection.
 */
class LegacyImportTest extends TestCase
{
    use RefreshDatabase;

    private ConnectionInterface $legacy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(RegionalOfficeSeeder::class);

        config(['database.connections.legacy_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $this->legacy = DB::connection('legacy_test');
        $this->createLegacySchema();
        $this->seedLegacyData();
    }

    protected function tearDown(): void
    {
        DB::purge('legacy_test');

        parent::tearDown();
    }

    public function test_imports_accounts_with_their_legacy_passwords_and_profile(): void
    {
        $this->import();

        $user = User::where('username', 'jdcruz')->firstOrFail();
        $this->assertSame('Juan D. Cruz', $user->name);
        $this->assertSame(1, $user->legacy_id);
        $this->assertSame('male', $user->sex);
        $this->assertSame('Human Resource Division', $user->organizationalUnit->name);
        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertTrue(Hash::check('legacy-secret', $user->password));
        $this->assertTrue($user->hasRole(RoleName::SuperAdmin));
        // 2026-09-01 08:00 Manila is midnight UTC.
        $this->assertSame('2026-09-01 00:00:00', $user->last_login_at->toDateTimeString());

        $inactive = User::where('username', 'mreyes')->firstOrFail();
        $this->assertSame(UserStatus::Inactive, $inactive->status);
        $this->assertFalse($inactive->hasRole(RoleName::SuperAdmin));
    }

    public function test_a_shared_email_stays_with_the_most_recent_sign_in(): void
    {
        $this->import();

        $this->assertSame('shared@example.test', User::where('username', 'asantos')->value('email'));
        $this->assertNull(User::where('username', 'bsantos')->value('email'));
    }

    public function test_an_existing_account_keeps_its_password_and_only_gains_missing_details(): void
    {
        $existing = User::factory()->create(['username' => 'jdcruz', 'password' => 'new-password', 'position' => null]);

        $this->import();

        $existing->refresh();
        $this->assertTrue(Hash::check('new-password', $existing->password));
        $this->assertSame('Analyst', $existing->position);
        $this->assertSame(1, User::where('username', 'jdcruz')->count());
    }

    public function test_imports_systems_roles_placement_and_access(): void
    {
        $this->import();

        $otrs = Application::where('short_name', 'OTRS')->firstOrFail();
        $this->assertTrue($otrs->requires_access);
        $this->assertNull($otrs->url);
        $this->assertSame('user', $otrs->icon);
        $this->assertSame('HRD', $otrs->organizationalUnit->code);
        $this->assertSame(['Information Technology Group'], $otrs->listedCommittees->pluck('name')->all());
        $this->assertSame('https://legacy.example.test/otrs/hrd/index', $otrs->roles()->where('name', 'Admin')->value('launch_url'));

        $eris = Application::where('short_name', 'ERIS')->firstOrFail();
        $this->assertFalse($eris->requires_access);
        $this->assertSame('https://legacy.example.test/eris/guest', $eris->url);
        $this->assertTrue($eris->under_maintenance);

        $this->assertSame('ITG', Committee::where('name', 'Information Technology Group')->value('short_name'));

        $grant = ApplicationAccess::where('application_id', $otrs->id)->where('user_id', User::where('username', 'asantos')->value('id'))->firstOrFail();
        $this->assertSame('Admin', $grant->role->name);
        $this->assertSame(User::where('username', 'jdcruz')->value('id'), $grant->granted_by);
    }

    public function test_never_brings_back_a_system_built_into_imis(): void
    {
        $this->legacy->table('imis_projects')->insert([
            'id' => 3, 'code_name' => 'PRISM', 'project_name' => 'Personnel Records', 'description' => 'Personnel', 'icon' => 'bi bi-person', 'guest_url' => null, 'is_active' => 1, 'is_public' => 0,
        ]);
        $this->legacy->table('imis_access_roles')->insert(['id' => 4, 'project_id' => 3, 'name' => 'Admin', 'project_url' => '/prism']);
        $this->legacy->table('imis_system_access')->insert([
            'id' => 3, 'user_id' => 2, 'project_id' => 3, 'role_id' => 4, 'granted_at' => '2026-05-01 00:00:00', 'granted_by' => null,
        ]);

        $this->import();

        $this->assertFalse(Application::where('short_name', 'PRISM')->exists());
        $this->assertSame(1, ApplicationAccess::where('user_id', User::where('username', 'asantos')->value('id'))->count());
    }

    public function test_retires_unheld_roles_the_legacy_system_does_not_define(): void
    {
        $otrs = Application::factory()->create(['short_name' => 'OTRS']);
        $stale = $otrs->roles()->create(['name' => 'Viewer', 'status' => Status::Active]);

        $this->import();

        $this->assertSame(Status::Inactive, $stale->fresh()->status);
        $this->assertSame(Status::Active, ApplicationRole::where('application_id', $otrs->id)->where('name', 'Admin')->value('status'));
    }

    public function test_imports_announcements_releases_and_history_in_utc(): void
    {
        $this->import();

        $announcement = Announcement::firstOrFail();
        $this->assertSame(AnnouncementStatus::Published, $announcement->status);
        $this->assertSame("Line one\nLine two", $announcement->body);
        $this->assertSame('2026-06-04 04:00:00', $announcement->expires_at->toDateTimeString());

        $release = Changelog::where('version', '2.7.0')->firstOrFail();
        $this->assertTrue($release->isPublished());
        $this->assertSame(['feature', 'fix'], $release->items->pluck('type')->map->value->all());

        $login = LoginHistory::where('username', 'jdcruz')->firstOrFail();
        $this->assertSame('2026-09-01 00:00:00', $login->logged_in_at->toDateTimeString());
        $this->assertNull($login->session_id);
        $this->assertSame('user', $login->ended_by);

        $launch = SystemAccessLog::firstOrFail();
        $this->assertSame('Online Training Registration System', $launch->application_name);
        $this->assertSame('203.0.113.5', $launch->ip_address);
    }

    public function test_running_twice_creates_no_duplicates(): void
    {
        $this->import();
        $counts = [User::count(), Application::count(), ApplicationAccess::count(), Announcement::count(), LoginHistory::count(), SystemAccessLog::count(), Committee::count(), OrganizationalUnit::count()];

        $this->import();

        $this->assertSame($counts, [User::count(), Application::count(), ApplicationAccess::count(), Announcement::count(), LoginHistory::count(), SystemAccessLog::count(), Committee::count(), OrganizationalUnit::count()]);
    }

    private function import(): array
    {
        return (new LegacyImporter($this->legacy, 'https://legacy.example.test'))->run();
    }

    private function createLegacySchema(): void
    {
        $schema = $this->legacy->getSchemaBuilder();

        $schema->create('users_cscro8', function (Blueprint $table) {
            $table->integer('id')->primary();
            foreach (['fname', 'lname', 'mname', 'sex', 'username', 'password', 'email', 'position', 'fo_rsu', 'status', 'role', 'profile'] as $column) {
                $table->string($column)->default('');
            }
            $table->date('birthday')->nullable();
            $table->dateTime('last_login')->nullable();
        });
        $schema->create('imis_projects', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('code_name');
            $table->string('project_name');
            $table->text('description');
            $table->string('icon');
            $table->string('guest_url')->nullable();
            $table->boolean('is_active');
            $table->boolean('is_public');
        });
        $schema->create('imis_access_roles', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->integer('project_id');
            $table->string('name');
            $table->string('project_url');
        });
        $schema->create('imis_system_access', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->integer('user_id');
            $table->integer('project_id');
            $table->integer('role_id');
            $table->timestamp('granted_at');
            $table->integer('granted_by')->nullable();
        });
        $schema->create('imis_dash_categories', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('type');
        });
        $schema->create('imis_dash_divisions', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->integer('category_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active');
        });
        $schema->create('imis_dash_division_systems', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->integer('division_id');
            $table->integer('project_id');
            $table->integer('display_order');
            $table->boolean('is_active');
            $table->boolean('is_maintenance');
        });
        $schema->create('imis_announcements', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('title');
            $table->text('message');
            $table->string('type');
            $table->integer('created_by');
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('scheduled_at')->nullable();
            $table->boolean('is_active');
            $table->timestamps();
        });
        $schema->create('imis_changelog', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('version');
            $table->string('title');
            $table->date('release_date')->nullable();
            $table->boolean('is_published');
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        $schema->create('imis_changelog_items', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->integer('changelog_id');
            $table->string('item_type');
            $table->string('description');
            $table->integer('sort_order');
        });
        $schema->create('imis_history_login_logs', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->integer('user_id');
            $table->string('username');
            $table->string('ip_address');
            $table->dateTime('login_time');
            $table->dateTime('logout_time')->nullable();
            $table->text('user_agent');
            $table->string('status');
            $table->string('session_id')->nullable();
        });
        $schema->create('imis_history_access_logs', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->integer('login_log_id')->nullable();
            $table->integer('user_id');
            $table->integer('project_id');
            $table->string('project_code');
            $table->string('role_name');
            $table->dateTime('accessed_at');
        });
    }

    private function seedLegacyData(): void
    {
        $hash = Hash::make('legacy-secret');
        $person = fn (array $values) => array_merge([
            'mname' => 'Dizon', 'sex' => 'Male', 'password' => $hash, 'position' => 'Analyst',
            'fo_rsu' => 'Human Resource Division', 'status' => 'Active', 'role' => 'user', 'profile' => '',
            'birthday' => '1990-01-01', 'last_login' => null,
        ], $values);

        $this->legacy->table('users_cscro8')->insert([
            $person(['id' => 1, 'fname' => 'Juan', 'lname' => 'Cruz', 'username' => 'jdcruz', 'email' => 'juan@example.test', 'role' => 'superadmin', 'last_login' => '2026-09-01 08:00:00']),
            $person(['id' => 2, 'fname' => 'Ana', 'lname' => 'Santos', 'username' => 'asantos', 'email' => 'shared@example.test', 'sex' => 'Female']),
            $person(['id' => 3, 'fname' => 'Ben', 'lname' => 'Santos', 'username' => 'bsantos', 'email' => 'Shared@example.test']),
            $person(['id' => 4, 'fname' => 'Mara', 'lname' => 'Reyes', 'username' => 'mreyes', 'email' => 'mara@example.test', 'status' => 'Inactive', 'role' => '']),
        ]);

        $this->legacy->table('imis_projects')->insert([
            ['id' => 1, 'code_name' => 'OTRS', 'project_name' => 'Online Training Registration System', 'description' => 'Training', 'icon' => 'bi bi-person-vcard', 'guest_url' => null, 'is_active' => 1, 'is_public' => 0],
            ['id' => 2, 'code_name' => 'ERIS', 'project_name' => 'Examination-Related Information System', 'description' => 'Exams', 'icon' => 'bi bi-journal-text', 'guest_url' => '/eris/guest', 'is_active' => 1, 'is_public' => 1],
        ]);
        $this->legacy->table('imis_access_roles')->insert([
            ['id' => 1, 'project_id' => 1, 'name' => 'Admin', 'project_url' => '/otrs/hrd/index'],
            ['id' => 2, 'project_id' => 1, 'name' => 'User', 'project_url' => '/otrs/index'],
            ['id' => 3, 'project_id' => 2, 'name' => 'User', 'project_url' => 'https://eris.example.test'],
        ]);
        $this->legacy->table('imis_system_access')->insert([
            ['id' => 1, 'user_id' => 2, 'project_id' => 1, 'role_id' => 1, 'granted_at' => '2026-05-01 00:00:00', 'granted_by' => 1],
            ['id' => 2, 'user_id' => 3, 'project_id' => 1, 'role_id' => 2, 'granted_at' => '2026-05-01 00:00:00', 'granted_by' => null],
        ]);
        $this->legacy->table('imis_dash_categories')->insert([['id' => 1, 'type' => 'rsu'], ['id' => 2, 'type' => 'committee']]);
        $this->legacy->table('imis_dash_divisions')->insert([
            ['id' => 1, 'category_id' => 1, 'name' => 'Human Resource Division (HRD)', 'description' => 'HR systems', 'is_active' => 1],
            ['id' => 2, 'category_id' => 2, 'name' => 'Information Technology Group (ITG)', 'description' => null, 'is_active' => 1],
            ['id' => 3, 'category_id' => 1, 'name' => 'Examination Services Division (ESD)', 'description' => null, 'is_active' => 1],
        ]);
        $this->legacy->table('imis_dash_division_systems')->insert([
            ['id' => 1, 'division_id' => 1, 'project_id' => 1, 'display_order' => 1, 'is_active' => 1, 'is_maintenance' => 0],
            ['id' => 2, 'division_id' => 2, 'project_id' => 1, 'display_order' => 2, 'is_active' => 1, 'is_maintenance' => 0],
            ['id' => 3, 'division_id' => 3, 'project_id' => 2, 'display_order' => 1, 'is_active' => 1, 'is_maintenance' => 1],
        ]);
        $this->legacy->table('imis_announcements')->insert([
            'id' => 1, 'title' => 'OTRS back online', 'message' => "Line one\r\nLine two", 'type' => 'info', 'created_by' => 1,
            'expires_at' => '2026-06-04 12:00:00', 'scheduled_at' => null, 'is_active' => 1,
            'created_at' => '2026-05-29 07:40:42', 'updated_at' => '2026-05-29 07:40:42',
        ]);
        $this->legacy->table('imis_changelog')->insert([
            'id' => 1, 'version' => '2.7.0', 'title' => 'Refresh', 'release_date' => '2026-09-19', 'is_published' => 1,
            'created_by' => null, 'created_at' => '2026-09-19 08:48:42', 'updated_at' => '2026-09-19 09:14:46',
        ]);
        $this->legacy->table('imis_changelog_items')->insert([
            ['id' => 1, 'changelog_id' => 1, 'item_type' => 'feature', 'description' => 'New login', 'sort_order' => 0],
            ['id' => 2, 'changelog_id' => 1, 'item_type' => 'fix', 'description' => 'Fixed a thing', 'sort_order' => 1],
        ]);
        $this->legacy->table('imis_history_login_logs')->insert([
            ['id' => 1, 'user_id' => 1, 'username' => 'jdcruz', 'ip_address' => '203.0.113.5', 'login_time' => '2026-09-01 08:00:00', 'logout_time' => '2026-09-01 09:00:00', 'user_agent' => 'Mozilla/5.0', 'status' => 'logged_out', 'session_id' => 'legacy-session'],
            ['id' => 2, 'user_id' => 2, 'username' => 'asantos', 'ip_address' => '203.0.113.6', 'login_time' => '2026-09-02 08:00:00', 'logout_time' => null, 'user_agent' => 'Mozilla/5.0', 'status' => 'timeout', 'session_id' => null],
            ['id' => 3, 'user_id' => 3, 'username' => 'bsantos', 'ip_address' => '203.0.113.7', 'login_time' => '2026-08-01 08:00:00', 'logout_time' => null, 'user_agent' => 'Mozilla/5.0', 'status' => 'timeout', 'session_id' => null],
        ]);
        $this->legacy->table('imis_history_access_logs')->insert([
            'id' => 1, 'login_log_id' => 1, 'user_id' => 1, 'project_id' => 1, 'project_code' => 'OTRS', 'role_name' => 'Admin', 'accessed_at' => '2026-09-01 08:05:00',
        ]);
    }
}
