<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A flash message carries an id, so the client toasts it once even though
 * partial reloads keep the old flash in the page props.
 */
class FlashMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_flashed_message_has_an_id_and_a_plain_page_has_none(): void
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'settings.view'], ['display_name' => 'settings.view']));
        $user->roles()->attach($role);

        $this->actingAs($user)->withSession(['status' => 'Saved.'])->get('/admin/email-templates')
            ->assertInertia(fn (Assert $page) => $page
                ->where('flash.status', 'Saved.')
                ->has('flash.id'));

        $this->flushSession();
        $this->actingAs($user)->get('/admin/email-templates')
            ->assertInertia(fn (Assert $page) => $page
                ->where('flash.status', null)
                ->where('flash.id', null));
    }

    public function test_each_flashed_message_gets_its_own_id(): void
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => 'settings.view'], ['display_name' => 'settings.view']));
        $user->roles()->attach($role);

        $ids = collect([1, 2])->map(function () use ($user) {
            $id = null;
            $this->actingAs($user)->withSession(['status' => 'Saved.'])->get('/admin/email-templates')
                ->assertInertia(function (Assert $page) use (&$id) {
                    $id = $page->toArray()['props']['flash']['id'];

                    return $page;
                });

            return $id;
        });

        $this->assertNotSame($ids[0], $ids[1]);
    }
}
