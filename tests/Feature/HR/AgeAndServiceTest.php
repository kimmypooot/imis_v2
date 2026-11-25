<?php

namespace Tests\Feature\HR;

use App\Enums\UserStatus;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Human Resources → Age & Service: the whole roster behind the HR
 * dashboard's birthday, retirement and loyalty-award lists.
 */
class AgeAndServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-03 09:00:00');
    }

    public function test_only_viewers_of_all_employee_records_may_open_it(): void
    {
        $this->get('/admin/hr/age-and-service')->assertRedirect('/login');

        $this->actingAs($this->userWith(['employees.view_unit']))->get('/admin/hr/age-and-service')->assertForbidden();

        $this->actingAs($this->userWith(['employees.view']))->get('/admin/hr/age-and-service')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/HR/AgeAndService/Index'));
    }

    public function test_viewing_is_audited_once_a_day_per_viewer(): void
    {
        $hr = $this->userWith(['employees.view']);

        $this->actingAs($hr)->get('/admin/hr/age-and-service');
        $this->actingAs($hr)->get('/admin/hr/age-and-service?view=birthdays_30');
        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseHas('audit_logs', ['event' => 'employee.age_and_service_viewed', 'actor_id' => $hr->id]);

        $this->travelTo('2026-10-04 09:00:00');
        $this->actingAs($hr)->get('/admin/hr/age-and-service');
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_everyone_in_service_with_age_service_retirement_and_next_loyalty_award(): void
    {
        $this->seedStaff();

        $this->actingAs($this->userWith(['employees.view']))->get('/admin/hr/age-and-service')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.view', 'all')
                ->has('people', 3)
                ->where('people.0.name', 'Ana')
                ->where('people.0.photo_url', null)
                ->where('people.0.age', 59)
                ->where('people.0.birthday_in_days', 17)
                ->where('people.0.government_label', '29 yrs 11 mos')
                ->where('people.0.csc_label', '29 yrs 11 mos')
                ->where('people.0.next_award', 30)
                ->where('people.0.next_award_date', '2026-10-20')
                ->where('people.0.award_this_year', 30)
                ->where('people.0.optional_retirement', '2026-10-20')
                ->where('people.0.compulsory_retirement', '2031-10-20')
                ->where('people.0.retirement_status', null)
                // Came from another agency: CSC service counts from joining the CSC.
                ->where('people.1.name', 'Ben')
                ->where('people.1.age', 66)
                ->where('people.1.government_label', '26 yrs 8 mos')
                ->where('people.1.csc_label', '16 yrs 7 mos')
                ->where('people.1.next_award', 20)
                ->where('people.1.next_award_date', '2030-03-01')
                ->where('people.1.retirement_status', 'past_compulsory')
                // No appointment date: no length of service.
                ->where('people.2.name', 'Cora')
                ->where('people.2.government_label', null)
                ->where('people.2.next_award', null));
    }

    public function test_views_narrow_the_roster_as_the_dashboard_links_ask(): void
    {
        $this->seedStaff();
        $hr = $this->userWith(['employees.view']);
        $names = fn (string $view) => fn (Assert $page) => $page->where('filters.view', $view);

        $this->actingAs($hr)->get('/admin/hr/age-and-service?view=birthdays_30')
            ->assertInertia(fn (Assert $page) => $names('birthdays_30')($page)->has('people', 1)->where('people.0.name', 'Ana'));
        $this->actingAs($hr)->get('/admin/hr/age-and-service?view=birthdays_month')
            ->assertInertia(fn (Assert $page) => $page->has('people', 1)->where('people.0.name', 'Ana'));
        // Reaching 60 or 65 within 12 months, or already past 65; by compulsory date.
        $this->actingAs($hr)->get('/admin/hr/age-and-service?view=retiring_12')
            ->assertInertia(fn (Assert $page) => $page->has('people', 2)->where('people.0.name', 'Ben')->where('people.1.name', 'Ana'));
        $this->actingAs($hr)->get('/admin/hr/age-and-service?view=retirement_age')
            ->assertInertia(fn (Assert $page) => $page->has('people', 1)->where('people.0.name', 'Ben'));
        $this->actingAs($hr)->get('/admin/hr/age-and-service?view=loyalty_year')
            ->assertInertia(fn (Assert $page) => $page->has('people', 1)->where('people.0.name', 'Ana'));

        $this->actingAs($hr)->get('/admin/hr/age-and-service?view=everything')->assertSessionHasErrors('view');
    }

    private function seedStaff(): void
    {
        $this->person('Ana', '1966-10-20', ['original_appointment_date' => '1996-10-20']);
        $this->person('Ben', '1960-05-01', ['original_appointment_date' => '2000-01-10', 'csc_entry_date' => '2010-03-01']);
        $this->person('Cora', '1995-03-15');
        $this->person('Gone', '1966-10-10', ['separation_date' => '2025-01-01']);
        $this->person('Inactive', '1966-10-10')->user->forceFill(['status' => UserStatus::Inactive])->save();
    }

    /**
     * @param  array<string, mixed>  $employment
     */
    private function person(string $name, string $birthday, array $employment = []): Employee
    {
        $user = User::factory()->create(['name' => $name, 'birthday' => $birthday]);

        return Employee::factory()->for($user)->create(['original_appointment_date' => null, ...$employment]);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['birthday' => null]);
        $role = Role::factory()->create();

        foreach ($permissions as $permission) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $permission], ['display_name' => $permission]));
        }

        $user->roles()->attach($role);

        return $user;
    }
}
