<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RemovePrismLauncherTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_migration_deletes_the_prism_launcher_and_what_hangs_off_it(): void
    {
        $prism = Application::factory()->create(['short_name' => 'PRISM']);
        $role = $prism->roles()->create(['name' => 'Admin', 'status' => 'active']);
        $prism->accessGrants()->create(['user_id' => User::factory()->create()->id, 'application_role_id' => $role->id]);
        $other = Application::factory()->create(['short_name' => 'OTRS']);

        (require database_path('migrations/2026_10_31_090000_remove_prism_launcher.php'))->up();

        $this->assertModelMissing($prism);
        $this->assertDatabaseMissing('application_roles', ['id' => $role->id]);
        $this->assertDatabaseMissing('application_access', ['application_id' => $prism->id]);
        $this->assertModelExists($other);
    }
}
