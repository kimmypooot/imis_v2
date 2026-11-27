<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Support\Privacy\PersonalDataExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
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

        $this->actingAs($user)->post('/my/data-export', ['sections' => ['account'], 'password' => 'wrong'])
            ->assertSessionHasErrors('password')->assertSessionMissing('data_export_url');

        $this->actingAs($user)->post('/my/data-export', ['sections' => ['account'], 'password' => 'password'])
            ->assertSessionHasNoErrors()->assertSessionHas('data_export_url');
    }

    public function test_at_least_one_known_section_must_be_chosen(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/my/data-export', ['sections' => [], 'password' => 'password'])
            ->assertSessionHasErrors('sections')->assertSessionMissing('data_export_url');

        $this->actingAs($user)->post('/my/data-export', ['sections' => ['account', 'someone_elses_data'], 'password' => 'password'])
            ->assertSessionHasErrors('sections.1')->assertSessionMissing('data_export_url');
    }

    public function test_the_download_holds_only_the_chosen_sections(): void
    {
        $user = User::factory()->create(['name' => 'Ana Reyes']);
        Employee::factory()->for($user)->create(['employee_number' => '2020-0042']);

        $this->actingAs($user)->post('/my/data-export', ['sections' => ['account', 'attendance'], 'password' => 'password'])->assertSessionHasNoErrors();
        $url = session('data_export_url');

        $data = $this->downloadedJson($this->actingAs($user)->get($url)->assertOk());

        $this->assertSame(['Account and access', 'Attendance (DTR)'], $data['sections_included']);
        $this->assertSame('Ana Reyes', $data['account']['name']);
        $this->assertArrayHasKey('biometric_punches', $data['attendance']);
        $this->assertArrayNotHasKey('employment', $data);
        $this->assertArrayNotHasKey('pds', $data);
        $this->assertDatabaseHas('audit_logs', ['event' => 'personal_data.exported', 'actor_id' => $user->id]);
    }

    public function test_every_section_builds_for_an_employee(): void
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->create(['biometric_id' => '1042']);

        $data = app(PersonalDataExport::class)->build($user);

        $this->assertSame(PersonalDataExport::sectionKeys(), array_values(array_diff(array_keys($data), ['generated_at', 'sections_included'])));
    }

    public function test_the_checklist_offers_employee_sections_only_to_employees(): void
    {
        $employee = User::factory()->create();
        Employee::factory()->for($employee)->create();

        $this->actingAs(User::factory()->create())->get('/my/data-export')->assertInertia(fn ($page) => $page
            ->where('sections', fn ($sections) => collect($sections)->pluck('key')->all() === ['account', 'committees', 'sign_in', 'feedback']));

        $this->actingAs($employee)->get('/my/data-export')->assertInertia(fn ($page) => $page
            ->where('sections', fn ($sections) => collect($sections)->pluck('key')->all() === PersonalDataExport::sectionKeys()));
    }

    /**
     * @return array<string, mixed>
     */
    private function downloadedJson(TestResponse $response): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()));

        return json_decode($zip->getFromName('data.json'), true);
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
