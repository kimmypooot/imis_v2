<?php

namespace Tests\Feature\Activities;

use App\Models\OrganizationalUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * PALD is the Calendar of Activities' process owner: everyone whose office
 * is PALD curates the calendar, with no role to assign, and loses it on
 * moving to another office.
 */
class PaldCuratorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_pald_staff_curate_the_calendar_without_a_role(): void
    {
        $pald = OrganizationalUnit::factory()->create(['code' => 'PALD']);
        $user = User::factory()->create(['organizational_unit_id' => $pald->id]);

        $this->assertTrue($user->hasPermission('activities.manage'));
        $this->assertTrue($user->hasPermission('activities.post'));
        $this->assertTrue($user->hasPermission('activities.view'));

        $this->actingAs($user)->get('/activities/manage')->assertOk();
        $this->actingAs($user)->get('/activities')->assertInertia(fn (Assert $page) => $page
            ->where('auth.permissions', fn ($permissions) => collect($permissions)->contains('activities.manage')));
    }

    public function test_staff_of_other_offices_do_not_curate(): void
    {
        $hrd = OrganizationalUnit::factory()->create(['code' => 'HRD']);
        $user = User::factory()->create(['organizational_unit_id' => $hrd->id]);

        $this->assertFalse($user->hasPermission('activities.manage'));
        $this->actingAs($user)->get('/activities/manage')->assertForbidden();
    }

    public function test_moving_out_of_pald_ends_curating(): void
    {
        $pald = OrganizationalUnit::factory()->create(['code' => 'PALD']);
        $hrd = OrganizationalUnit::factory()->create(['code' => 'HRD']);
        $user = User::factory()->create(['organizational_unit_id' => $pald->id]);
        $this->assertTrue($user->hasPermission('activities.manage'));

        $user->update(['organizational_unit_id' => $hrd->id]);

        $this->assertFalse($user->fresh()->hasPermission('activities.manage'));
    }
}
