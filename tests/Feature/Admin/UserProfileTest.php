<?php

namespace Tests\Feature\Admin;

use App\Enums\RoleName;
use App\Models\OrganizationalUnit;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The legacy account profile (name parts, office, position, photo) on the
 * modal-based Manage Users page.
 */
class UserProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_created_user_gets_the_legacy_display_name_username_and_office(): void
    {
        Notification::fake();
        $office = OrganizationalUnit::factory()->create(['name' => 'Examination Services Division']);

        $this->actingAs($this->userWith('users.create'))->post('/users', [
            'first_name' => 'Juan',
            'middle_name' => 'Santos',
            'last_name' => 'Dela Cruz',
            'email' => 'juan@example.test',
            'position' => 'Human Resource Specialist II',
            'sex' => 'male',
            'birthday' => '1990-05-17',
            'organizational_unit_id' => $office->id,
        ])->assertSessionHasNoErrors()->assertSessionHas('status');

        $this->assertDatabaseHas('users', [
            'email' => 'juan@example.test',
            'name' => 'Juan S. Dela Cruz',
            'username' => 'jsdelacruz',
            'position' => 'Human Resource Specialist II',
            'organizational_unit_id' => $office->id,
        ]);
    }

    public function test_editing_name_parts_keeps_the_display_name_in_sync(): void
    {
        $target = User::factory()->create();

        $this->actingAs($this->userWith('users.update'))->put("/users/{$target->id}", [
            'first_name' => 'Maria',
            'middle_name' => 'Lopez',
            'last_name' => 'Reyes',
            'username' => $target->username,
            'email' => $target->email,
        ])->assertSessionHasNoErrors();

        $this->assertSame('Maria L. Reyes', $target->fresh()->name);
    }

    public function test_profile_fields_are_validated(): void
    {
        $target = User::factory()->create();

        $this->actingAs($this->userWith('users.update'))->put("/users/{$target->id}", [
            'first_name' => 'A', 'last_name' => 'B', 'username' => $target->username, 'email' => $target->email,
            'sex' => 'other', 'birthday' => now()->addDay()->toDateString(), 'organizational_unit_id' => 999999,
        ])->assertSessionHasErrors(['sex', 'birthday', 'organizational_unit_id']);
    }

    public function test_photo_is_re_encoded_as_a_square_and_replaces_the_old_one(): void
    {
        Storage::fake('public');
        $target = User::factory()->create();
        $actor = $this->userWith('users.update');

        $this->actingAs($actor)->post("/users/{$target->id}/photo", [
            'profile_photo' => UploadedFile::fake()->image('wide.png', 800, 500),
        ])->assertSessionHasNoErrors();

        $first = $target->fresh()->profile_photo_path;
        $this->assertStringStartsWith('profile-photos/', $first);
        $this->assertStringEndsWith('.jpg', $first);
        [$width, $height] = getimagesizefromstring(Storage::disk('public')->get($first));
        $this->assertSame([400, 400], [$width, $height]);

        $this->actingAs($actor)->post("/users/{$target->id}/photo", [
            'profile_photo' => UploadedFile::fake()->image('new.jpg', 300, 300),
        ]);

        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($target->fresh()->profile_photo_path);
    }

    public function test_photo_can_be_removed(): void
    {
        Storage::fake('public');
        $target = User::factory()->create();
        $actor = $this->userWith('users.update');
        $this->actingAs($actor)->post("/users/{$target->id}/photo", ['profile_photo' => UploadedFile::fake()->image('a.png', 200, 200)]);
        $path = $target->fresh()->profile_photo_path;

        $this->actingAs($actor)->delete("/users/{$target->id}/photo")->assertSessionHasNoErrors();

        Storage::disk('public')->assertMissing($path);
        $this->assertNull($target->fresh()->profile_photo_path);
    }

    public function test_non_images_are_rejected(): void
    {
        Storage::fake('public');
        $target = User::factory()->create();

        $this->actingAs($this->userWith('users.update'))->post("/users/{$target->id}/photo", [
            'profile_photo' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('profile_photo');

        $this->assertNull($target->fresh()->profile_photo_path);
    }

    public function test_a_super_admins_photo_is_protected_from_non_super_admins(): void
    {
        Storage::fake('public');
        $target = User::factory()->create();
        $target->roles()->attach(Role::factory()->create(['name' => RoleName::SuperAdmin->value]));

        $this->actingAs($this->userWith('users.update'))->post("/users/{$target->id}/photo", [
            'profile_photo' => UploadedFile::fake()->image('a.png', 200, 200),
        ])->assertForbidden();
    }

    public function test_listing_carries_profile_and_per_row_abilities(): void
    {
        $protected = User::factory()->create(['name' => 'Protected Admin']);
        $protected->roles()->attach(Role::factory()->create(['name' => RoleName::SuperAdmin->value]));
        $actor = $this->userWith('users.view');

        $this->actingAs($actor)->get('/users')->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Users/Index')
            ->has('officeOptions')
            ->where('users', fn ($users) => collect($users)->firstWhere('name', 'Protected Admin')['can']['update'] === false));
    }

    private function userWith(string $permission): User
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $role->permissions()->attach(
            Permission::query()->firstOrCreate(['name' => $permission], ['display_name' => $permission]),
        );
        $user->roles()->attach($role);

        return $user;
    }
}
