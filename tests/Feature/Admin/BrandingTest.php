<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\BrandAsset;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Branding\Branding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Admin-3, branding: replacing and resetting the logos and sign-in photo.
 */
class BrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_gets_the_built_in_branding_by_default(): void
    {
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('branding.logo', '/images/brand/csc-logo.png')
            ->where('branding.header_name', 'CSC RO VIII'));
    }

    public function test_an_uploaded_logo_replaces_the_built_in_one_until_reset(): void
    {
        Storage::fake('public');
        $admin = $this->userWith(['settings.view', 'settings.update']);

        $this->actingAs($admin)->post('/admin/branding/logo', ['image' => UploadedFile::fake()->image('logo.png', 200, 200)])->assertSessionHasNoErrors();

        $path = BrandAsset::query()->sole()->path;
        Storage::disk('public')->assertExists($path);
        $this->assertStringEndsWith($path, Branding::url('logo'));
        $this->assertTrue(AuditLog::query()->where('event', 'setting.branding_updated')->exists());

        $this->actingAs($admin)->delete('/admin/branding/logo');

        Storage::disk('public')->assertMissing($path);
        $this->assertSame('/images/brand/csc-logo.png', Branding::url('logo'));
    }

    public function test_only_raster_images_are_accepted(): void
    {
        Storage::fake('public');
        $admin = $this->userWith(['settings.view', 'settings.update']);

        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->actingAs($admin)->post('/admin/branding/logo', ['image' => $svg])->assertSessionHasErrors('image');
        $this->actingAs($admin)->post('/admin/branding/favicon', ['image' => UploadedFile::fake()->image('x.png')])->assertNotFound();
    }

    public function test_viewers_cannot_change_branding(): void
    {
        $viewer = $this->userWith(['settings.view']);

        $this->actingAs($viewer)->get('/admin/branding')->assertInertia(fn (Assert $page) => $page->component('Admin/Branding')->where('canUpdate', false)->has('assets', 3));
        $this->actingAs($viewer)->post('/admin/branding/logo', ['image' => UploadedFile::fake()->image('logo.png')])->assertForbidden();
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
