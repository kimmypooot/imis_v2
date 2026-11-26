<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleName;
use App\Models\Employee;
use App\Models\Feedback;
use App\Models\PlantillaAppointment;
use App\Models\PlantillaItem;
use App\Models\Role;
use App\Models\SalaryNotice;
use App\Models\User;
use App\Support\Admin\TestDataReset;
use App\Support\Backup\BackupStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * System Settings danger zone, development only: a Super Administrator
 * ticks the test data to delete. People, their records and setup stay; a
 * backup is taken first.
 */
class TestDataResetTest extends TestCase
{
    use RefreshDatabase;

    private const PHRASE = 'RESET TEST DATA';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['test_data.reset_allowed' => true]);
    }

    public function test_the_settings_page_lists_each_category_with_its_count(): void
    {
        SalaryNotice::factory()->count(2)->create();

        $this->actingAs($this->superAdmin())->get('/admin/settings')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('testDataReset.phrase', self::PHRASE)
                ->where('testDataReset.categories', fn ($categories) => collect($categories)->firstWhere('key', 'salary_notices')['count'] === 2));
    }

    public function test_only_the_ticked_categories_are_deleted_and_the_people_stay(): void
    {
        $this->backupSucceeds();
        $admin = $this->superAdmin();
        $notice = SalaryNotice::factory()->create();
        $document = $this->document($notice->employee, 'hr/201/1/signed.pdf');
        $feedback = Feedback::factory()->create();

        $this->actingAs($admin)->delete('/admin/settings/test-data', [
            'categories' => ['salary_notices', 'documents'],
            'password' => 'password',
            'confirmation' => self::PHRASE,
        ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('status');

        $this->assertSame(0, SalaryNotice::query()->count());
        $this->assertSame(0, DB::table('employee_documents')->count());
        Storage::disk('local')->assertMissing($document);
        // Not ticked, so kept; the people are never on the list.
        $this->assertModelExists($feedback);
        $this->assertModelExists($notice->employee);
        $this->assertModelExists($admin);
        $this->assertDatabaseHas('audit_logs', ['event' => 'system.test_data_reset', 'actor_id' => $admin->id]);
    }

    public function test_a_file_still_used_by_a_kept_record_is_not_deleted(): void
    {
        $this->backupSucceeds();
        $employee = Employee::factory()->create();
        $shared = $this->document($employee, 'photos/shared.jpg');
        $employee->user->forceFill(['profile_photo_path' => $shared])->save();

        $this->actingAs($this->superAdmin())->delete('/admin/settings/test-data', ['categories' => ['documents'], 'password' => 'password', 'confirmation' => self::PHRASE]);

        $this->assertSame(0, DB::table('employee_documents')->count());
        Storage::disk('local')->assertExists($shared);
    }

    public function test_step_increments_are_never_deleted_even_when_everything_is_ticked(): void
    {
        $this->backupSucceeds();
        $employee = Employee::factory()->create();
        $item = PlantillaItem::query()->create(['item_number' => 'ADOF4-1', 'position_title' => 'Administrative Officer IV', 'salary_grade' => 15, 'level' => 'second']);
        $before = $this->period($employee, $item, ['step' => 1, 'nature' => 'original', 'date_from' => '2023-01-01', 'date_to' => '2026-10-31', 'end_reason' => 'Step increment']);
        $increment = $this->period($employee, $item, ['step' => 2, 'nature' => 'step_increment', 'date_from' => '2026-11-01']);
        $admin = $this->superAdmin();

        // Not a category any more: asking for it is refused.
        $this->actingAs($admin)->delete('/admin/settings/test-data', ['categories' => ['step_increments'], 'password' => 'password', 'confirmation' => self::PHRASE])
            ->assertSessionHasErrors('categories.0');

        $this->actingAs($admin)->delete('/admin/settings/test-data', ['categories' => array_keys(TestDataReset::CATEGORIES), 'password' => 'password', 'confirmation' => self::PHRASE])
            ->assertSessionHasNoErrors();

        $this->assertModelExists($increment);
        $this->assertSame('2026-10-31', $before->fresh()->date_to->toDateString());
    }

    public function test_nothing_is_deleted_when_the_backup_fails(): void
    {
        $this->mock(BackupStore::class, fn (MockInterface $mock) => $mock->shouldReceive('create')->andThrow(new RuntimeException('mysqldump missing')));
        SalaryNotice::factory()->create();

        $this->actingAs($this->superAdmin())->delete('/admin/settings/test-data', ['categories' => ['salary_notices'], 'password' => 'password', 'confirmation' => self::PHRASE])
            ->assertSessionHas('error');

        $this->assertSame(1, SalaryNotice::query()->count());
    }

    public function test_it_needs_a_super_admin_the_password_the_phrase_and_a_development_server(): void
    {
        $this->backupSucceeds();
        SalaryNotice::factory()->create();
        $request = ['categories' => ['salary_notices'], 'password' => 'password', 'confirmation' => self::PHRASE];

        $this->actingAs(User::factory()->create())->delete('/admin/settings/test-data', $request)->assertForbidden();
        $admin = $this->superAdmin();
        $this->actingAs($admin)->delete('/admin/settings/test-data', [...$request, 'password' => 'wrong'])->assertSessionHasErrors('password');
        $this->actingAs($admin)->delete('/admin/settings/test-data', [...$request, 'confirmation' => 'reset'])->assertSessionHasErrors('confirmation');

        config(['test_data.reset_allowed' => false]);
        $this->actingAs($admin)->delete('/admin/settings/test-data', $request)->assertForbidden();
        $this->actingAs($admin)->get('/admin/settings')->assertInertia(fn (Assert $page) => $page->where('testDataReset', null));

        $this->assertSame(1, SalaryNotice::query()->count());
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function backupSucceeds(): void
    {
        $this->mock(BackupStore::class, fn (MockInterface $mock) => $mock->shouldReceive('create')->andReturn(['name' => 'imis-test.sql.gz', 'size' => 1, 'created_at' => Carbon::now(), 'automatic' => false]));
    }

    private function document(Employee $employee, string $path): string
    {
        Storage::disk('local')->put($path, 'file');
        DB::table('employee_documents')->insert([
            'employee_id' => $employee->id, 'document_type' => 'other', 'title' => 'Test', 'file_path' => $path, 'original_name' => basename($path),
            'mime_type' => 'application/pdf', 'size' => 4, 'sha256' => str_repeat('a', 64), 'uploaded_by' => $employee->user_id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $path;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function period(Employee $employee, PlantillaItem $item, array $attributes): PlantillaAppointment
    {
        $period = new PlantillaAppointment(['status' => 'permanent', ...$attributes]);
        $period->forceFill(['plantilla_item_id' => $item->id, 'employee_id' => $employee->id, 'end_reason' => $attributes['end_reason'] ?? null])->save();

        return $period;
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $role = Role::query()->firstOrCreate(['name' => RoleName::SuperAdmin->value], ['display_name' => 'Super Administrator']);
        $user->roles()->attach($role);

        return $user;
    }
}
