<?php

namespace Tests\Feature\Portal;

use App\Enums\SalnStatus;
use App\Models\Employee;
use App\Models\Saln;
use App\Models\SalnEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NeedsAttentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_nothing_waiting_gives_an_empty_list(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Portal/Dashboard')
                ->missing('attention')
                ->loadDeferredProps(fn (Assert $reload) => $reload->where('attention', [])));
    }

    public function test_a_returned_saln_is_listed_with_the_committees_remarks(): void
    {
        $employee = Employee::factory()->create();
        $saln = Saln::factory()->create(['employee_id' => $employee->id, 'status' => SalnStatus::Returned]);
        SalnEvent::forceCreate(['saln_id' => $saln->id, 'action' => 'returned', 'remarks' => 'Update the acquisition cost of item 2', 'created_at' => now()]);

        $this->actingAs($employee->user)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('attention.0.kind', 'returned')
                    ->where('attention.0.title', 'SALN 2025 returned for correction')
                    ->where('attention.0.meta', 'Update the acquisition cost of item 2')
                    ->where('attention.0.href', "/my/saln/{$saln->id}")));
    }

    public function test_a_cleared_saln_is_not_listed(): void
    {
        $employee = Employee::factory()->create();
        Saln::factory()->create(['employee_id' => $employee->id, 'status' => SalnStatus::Cleared]);

        $this->actingAs($employee->user)->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->loadDeferredProps(fn (Assert $reload) => $reload->where('attention', [])));
    }
}
