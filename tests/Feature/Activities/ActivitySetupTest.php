<?php

namespace Tests\Feature\Activities;

use App\Enums\PermissionName;
use App\Models\Activity;
use App\Models\ActivityPlanCutoff;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ActivityRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Calendar of Activities set-up: the permissions and starter roles, the
 * month query and the yearly plan cut-off.
 */
class ActivitySetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_role_can_view_activities(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::baseline());

        $this->assertTrue($user->hasPermission(PermissionName::ActivitiesView->value));
        $this->assertFalse($user->hasPermission(PermissionName::ActivitiesPost->value));
    }

    public function test_curator_and_poster_roles_exist_with_their_permissions(): void
    {
        $this->seed(ActivityRoleSeeder::class);

        $curator = Role::query()->where('name', 'activities-curator')->firstOrFail();
        $poster = Role::query()->where('name', 'activities-poster')->firstOrFail();

        $this->assertSame('Calendar of Activities — PALD', $curator->display_name);
        $this->assertSame('Calendar of Activities — office poster', $poster->display_name);
        $this->assertEqualsCanonicalizing(
            ['activities.view', 'activities.post', 'activities.manage'],
            $curator->permissions()->pluck('name')->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['activities.view', 'activities.post'],
            $poster->permissions()->pluck('name')->all(),
        );
    }

    public function test_in_month_includes_month_only_and_overlapping_dated_activities(): void
    {
        $monthOnly = Activity::factory()->monthOnly(2025, 12)->create();
        $overlapping = Activity::factory()->dated('2025-11-30', '2025-12-02')->create();
        Activity::factory()->dated('2026-01-05')->create();

        $this->assertEqualsCanonicalizing(
            [$monthOnly->id, $overlapping->id],
            Activity::query()->inMonth(2025, 12)->pluck('id')->all(),
        );
    }

    public function test_plan_cutoff_defaults_to_january_31(): void
    {
        $this->assertSame('2027-01-31', ActivityPlanCutoff::for(2027)->toDateString());

        ActivityPlanCutoff::query()->create(['year' => 2027, 'cuts_off_on' => '2027-02-15']);

        $this->assertSame('2027-02-15', ActivityPlanCutoff::for(2027)->toDateString());
    }
}
