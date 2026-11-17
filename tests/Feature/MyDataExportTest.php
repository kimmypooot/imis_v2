<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;
use ZipArchive;

/**
 * RA 10173: a person downloads a copy of their own data.
 */
class MyDataExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_password_must_be_confirmed_before_a_link_is_given(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/my/data-export', ['password' => 'wrong'])
            ->assertSessionHasErrors('password')->assertSessionMissing('data_export_url');

        $this->actingAs($user)->post('/my/data-export', ['password' => 'password'])
            ->assertSessionHasNoErrors()->assertSessionHas('data_export_url');
    }

    public function test_the_download_holds_only_the_signed_in_persons_data_and_is_audited(): void
    {
        $user = User::factory()->create(['name' => 'Ana Reyes']);
        Employee::factory()->for($user)->create(['employee_number' => '2020-0042']);
        $other = User::factory()->create(['name' => 'Someone Else']);
        Employee::factory()->for($other)->create(['employee_number' => '2020-9999']);

        $response = $this->actingAs($user)->get(URL::temporarySignedRoute('my.data-export.download', now()->addMinutes(2), ['user' => $user->id]));

        $response->assertOk()->assertHeader('content-type', 'application/zip');

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()));
        $json = $zip->getFromName('data.json');
        $html = $zip->getFromName('data.html');

        $this->assertStringContainsString('Ana Reyes', $json);
        $this->assertStringContainsString('2020-0042', $json);
        $this->assertStringNotContainsString('Someone Else', $json.$html);
        $this->assertStringNotContainsString('2020-9999', $json.$html);
        $this->assertStringContainsString('My IMIS data', $html);
        $this->assertDatabaseHas('audit_logs', ['event' => 'personal_data.exported', 'actor_id' => $user->id]);
    }

    public function test_an_account_without_an_employee_record_still_gets_its_account_data(): void
    {
        $user = User::factory()->create(['name' => 'Ana Reyes']);

        $this->actingAs($user)->get(URL::temporarySignedRoute('my.data-export.download', now()->addMinutes(2), ['user' => $user->id]))->assertOk();
    }

    public function test_a_link_is_refused_when_unsigned_expired_or_for_someone_else(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)->get("/my/data-export/download?user={$user->id}")->assertForbidden();
        $this->actingAs($user)->get(URL::temporarySignedRoute('my.data-export.download', now()->subMinute(), ['user' => $user->id]))->assertForbidden();
        $this->actingAs($user)->get(URL::temporarySignedRoute('my.data-export.download', now()->addMinutes(2), ['user' => $other->id]))->assertForbidden();
    }

    public function test_the_page_needs_a_signed_in_user(): void
    {
        $this->get('/my/data-export')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/my/data-export')->assertOk();
    }
}
