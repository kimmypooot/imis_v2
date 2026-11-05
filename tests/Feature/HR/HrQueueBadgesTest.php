<?php

namespace Tests\Feature\HR;

use App\Enums\LdApprovalStatus;
use App\Models\Employee;
use App\Models\Pds\PdsLearningDevelopment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The counts beside the HR menu entries: shown only to those who may open
 * the queue behind them.
 */
class HrQueueBadgesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_queue_count_is_given_only_to_someone_who_may_open_the_queue(): void
    {
        Cache::flush();
        $this->pendingTraining();
        $this->pendingTraining();

        $reviewer = $this->userWith(['ld.approve']);
        $viewer = $this->userWith(['ld.view']);

        $this->actingAs($reviewer)->get('/admin/hr')
            ->assertInertia(fn (Assert $page) => $page->where('badges.ldReviews', 2)->where('badges.pdsVerifications', 0));

        $this->actingAs($viewer)->get('/admin/hr')
            ->assertInertia(fn (Assert $page) => $page->where('badges.ldReviews', 0));
    }

    private function pendingTraining(): void
    {
        $record = new PdsLearningDevelopment;
        $record->forceFill([
            'employee_id' => Employee::factory()->create()->id,
            'title' => 'Records Management',
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-01',
            'hours' => 4,
            'ld_type' => 'technical',
            'modality' => 'face_to_face',
            'sponsor' => 'CSC RO VIII',
            'approval_status' => LdApprovalStatus::Pending,
        ])->save();
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
