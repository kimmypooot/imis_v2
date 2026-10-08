<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Admin-3, bulk user import: preview first, then the valid rows become
 * accounts exactly as Add User makes them.
 */
class UserImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_needs_the_create_permission(): void
    {
        $this->actingAs(User::factory()->create())->get('/users/import')->assertForbidden();
        $this->actingAs($this->admin())->get('/users/import')->assertOk();
        $this->actingAs($this->admin())->get('/users/import/template')->assertOk()->assertDownload('imis-user-import-template.csv');
    }

    public function test_the_preview_checks_every_row(): void
    {
        OrganizationalUnit::factory()->create(['code' => 'HRD', 'short_name' => 'HRD', 'name' => 'Human Resource Division']);
        Role::factory()->create(['name' => 'supervisor', 'display_name' => 'Supervisor']);
        Role::query()->create(['name' => RoleName::SuperAdmin->value, 'display_name' => 'Super Administrator', 'status' => 'active']);
        User::factory()->create(['email' => 'taken@example.test']);

        $response = $this->actingAs($this->admin())->post('/users/import/preview', ['file' => $this->csv([
            'Juan,Santos,Dela Cruz,,male,1990-05-14,AO II,hrd,juan@example.test,,Supervisor',
            'Ana,,Reyes,,,,,Nowhere,ana@example.test,,',
            'Pedro,,Cruz,,,,,,taken@example.test,,super-admin',
            ',,,,,,,,not-an-email,,',
            'Rosa,,Lim,,,,,,dup@example.test,,',
            'Rosa,,Lim,,,,,,dup@example.test,,',
        ])]);

        $preview = $response->assertSessionHasNoErrors()->baseResponse->getSession()->get('import_preview');
        $rows = collect($preview['rows'])->keyBy('line');

        $this->assertSame(1, $preview['valid']);
        $this->assertSame([], $rows[2]['errors']);
        $this->assertSame('jsdelacruz', $rows[2]['username']);
        $this->assertSame('HRD', $rows[2]['office']);
        $this->assertSame(['Supervisor'], $rows[2]['roles']);
        $this->assertStringContainsString('Office "Nowhere" not found', $rows[3]['errors'][0]);
        $this->assertTrue(collect($rows[4]['errors'])->contains(fn ($error) => str_contains($error, 'super-admin')));
        $this->assertTrue(collect($rows[4]['errors'])->contains(fn ($error) => str_contains($error, 'email has already been taken')));
        $this->assertNotEmpty($rows[5]['errors']);
        $this->assertContains('This email appears more than once in the file.', $rows[6]['errors']);
        $this->assertSame(0, User::query()->where('email', 'juan@example.test')->count());
    }

    public function test_importing_creates_the_valid_rows_like_add_user(): void
    {
        Notification::fake();
        $admin = $this->admin();

        $this->actingAs($admin)->post('/users/import/preview', ['file' => $this->csv([
            'Juan,Santos,Dela Cruz,,male,1990-05-14,AO II,,juan@example.test,,',
            'Bad,,Row,,,,,,nope,,',
        ])]);
        $token = session('import_preview')['token'];

        $this->actingAs($admin)->post('/users/import', ['token' => $token, 'send_links' => true])
            ->assertSessionHas('import_result', fn ($result) => count($result['created']) === 1 && $result['created'][0]['username'] === 'jsdelacruz');

        $user = User::query()->where('email', 'juan@example.test')->sole();
        $this->assertSame('Juan S. Dela Cruz', $user->name);
        $this->assertNotNull($user->employee);
        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertSame('bulk import', AuditLog::query()->where('event', 'user.created')->sole()->metadata['source']);
        $this->assertTrue(AuditLog::query()->where('event', 'user.imported')->exists());

        // A preview is used once.
        $this->actingAs($admin)->post('/users/import', ['token' => $token])->assertSessionHas('error');
    }

    public function test_a_title_in_the_first_name_or_honorific_column_becomes_the_honorific(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $header = "First Name,Last Name,Email,Honorific\n";

        $this->actingAs($admin)->post('/users/import/preview', ['file' => UploadedFile::fake()->createWithContent('people.csv',
            $header."Atty. Ana,Reyes,ana@example.test,\nJuan,Cruz,juan@example.test,engr\nRosa,Lim,rosa@example.test,Sir\n")]);
        $preview = session('import_preview');
        $rows = collect($preview['rows'])->keyBy('line');

        $this->assertSame(['Atty. Ana Reyes', 'areyes'], [$rows[2]['name'], $rows[2]['username']]);
        $this->assertNotEmpty($rows[4]['errors']);

        $this->actingAs($admin)->post('/users/import', ['token' => $preview['token']]);

        $ana = User::query()->where('email', 'ana@example.test')->sole();
        $this->assertSame(['Atty.', 'Ana'], [$ana->honorific, $ana->first_name]);
        $this->assertSame('Engr. Juan Cruz', User::query()->where('email', 'juan@example.test')->value('name'));
    }

    public function test_files_without_the_required_columns_are_refused(): void
    {
        $file = UploadedFile::fake()->createWithContent('people.csv', "name,email\nJuan,juan@example.test\n");

        $this->actingAs($this->admin())->post('/users/import/preview', ['file' => $file])->assertSessionHasErrors('file');
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function csv(array $lines): UploadedFile
    {
        $header = "\xEF\xBB\xBFFirst Name,Middle Name,Last Name,Name Extension,Sex,Birthday,Position,Office,Email,Username,Roles";

        return UploadedFile::fake()->createWithContent('people.csv', $header."\n".implode("\n", $lines)."\n");
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'users.create'], ['display_name' => 'Users Create']));
        $user->roles()->attach($role);

        return $user;
    }
}
