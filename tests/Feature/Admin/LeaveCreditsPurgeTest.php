<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleName;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\LeaveType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\HR\Leave\LeaveCredits;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * System Settings danger zone: "Delete all leave credits" truncates the
 * ledger. Super Administrators only, with the password and the typed phrase.
 */
class LeaveCreditsPurgeTest extends TestCase
{
    use RefreshDatabase;

    private const PHRASE = 'DELETE ALL LEAVE CREDITS';

    public function test_a_super_admin_deletes_every_ledger_line_and_it_is_audited(): void
    {
        $admin = $this->superAdmin();
        $this->seedCredits($admin);
        $this->assertGreaterThan(0, DB::table('leave_ledger_entries')->count());

        $this->actingAs($admin)
            ->delete('/admin/settings/leave-credits', ['password' => 'password', 'confirmation' => self::PHRASE])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(0, DB::table('leave_ledger_entries')->count());
        $this->assertSame(0, DB::table('dtr_leave_deductions')->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'system.leave_credits_purged', 'actor_id' => $admin->id]);
    }

    public function test_leave_applications_are_left_alone(): void
    {
        $admin = $this->superAdmin();
        $this->seedCredits($admin);
        $before = LeaveApplication::query()->count();

        $this->actingAs($admin)->delete('/admin/settings/leave-credits', ['password' => 'password', 'confirmation' => self::PHRASE]);

        $this->assertSame($before, LeaveApplication::query()->count());
    }

    public function test_a_wrong_password_deletes_nothing(): void
    {
        $admin = $this->superAdmin();
        $this->seedCredits($admin);

        $this->actingAs($admin)
            ->delete('/admin/settings/leave-credits', ['password' => 'wrong-one', 'confirmation' => self::PHRASE])
            ->assertSessionHasErrors('password');

        $this->assertGreaterThan(0, DB::table('leave_ledger_entries')->count());
        $this->assertDatabaseMissing('audit_logs', ['event' => 'system.leave_credits_purged']);
    }

    public function test_the_phrase_must_be_typed_exactly(): void
    {
        $admin = $this->superAdmin();
        $this->seedCredits($admin);

        foreach ([null, 'delete all leave credits', 'DELETE'] as $phrase) {
            $this->actingAs($admin)
                ->delete('/admin/settings/leave-credits', ['password' => 'password', 'confirmation' => $phrase])
                ->assertSessionHasErrors('confirmation');
        }

        $this->assertGreaterThan(0, DB::table('leave_ledger_entries')->count());
    }

    public function test_holding_the_settings_permission_without_being_a_super_admin_is_not_enough(): void
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'settings.update'], ['display_name' => 'Update settings']));
        $user->roles()->attach($role);
        $this->seedCredits($user);

        $this->actingAs($user)
            ->delete('/admin/settings/leave-credits', ['password' => 'password', 'confirmation' => self::PHRASE])
            ->assertForbidden();

        $this->assertGreaterThan(0, DB::table('leave_ledger_entries')->count());
        $this->actingAs($user)->get('/admin/settings')->assertInertia(fn (Assert $page) => $page->missing('leaveCreditsPurge'));
    }

    public function test_the_settings_page_offers_the_danger_zone_to_a_super_admin(): void
    {
        $admin = $this->superAdmin();
        $this->seedCredits($admin);

        $this->actingAs($admin)->get('/admin/settings')->assertInertia(fn (Assert $page) => $page
            ->where('leaveCreditsPurge.phrase', self::PHRASE)
            ->where('leaveCreditsPurge.entries', 1));
    }

    private function seedCredits(User $actor): void
    {
        $this->seed(LeaveTypeSeeder::class);
        (new LeaveCredits)->postManual(Employee::factory()->create(), [
            'leave_type_id' => LeaveType::query()->where('code', 'VL')->value('id'),
            'kind' => 'opening', 'amount' => 10, 'entry_date' => '2026-01-01', 'particulars' => 'Opening balance',
        ], $actor);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $role = Role::query()->firstOrCreate(['name' => RoleName::SuperAdmin->value], ['display_name' => 'Super Administrator']);
        $user->roles()->attach($role);

        return $user;
    }
}
