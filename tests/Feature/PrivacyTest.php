<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Enums\LdApprovalStatus;
use App\Models\Employee;
use App\Models\Pds\PdsLearningDevelopment;
use App\Models\Setting;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Settings\SettingRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Admin-2: the privacy notice and "Who accessed my record" (RA 10173).
 */
class PrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SettingRegistry::sync();
    }

    public function test_anyone_can_read_the_privacy_notice(): void
    {
        $this->set('privacy_officer_name', 'Juan Dela Cruz');
        $this->set('privacy_officer_email', 'dpo@example.test');
        $this->set('retention_login_history_months', 12);

        $this->get('/privacy')->assertInertia(fn (Assert $page) => $page
            ->component('Privacy')
            ->where('notice.contact.name', 'Juan Dela Cruz')
            ->where('notice.contact.email', 'dpo@example.test')
            ->where('notice.sections', fn ($sections) => collect($sections)->firstWhere('title', 'How long it is kept')['items'][1]
                === 'Sign-in history: 12 months, then deleted automatically.'));

        $this->actingAs(User::factory()->create())->get('/privacy')->assertInertia(fn (Assert $page) => $page->component('Privacy'));
    }

    public function test_employees_see_who_else_opened_their_records(): void
    {
        $employee = Employee::factory()->create();
        $other = Employee::factory()->create();
        $officer = User::factory()->create(['name' => 'Maria HR', 'position' => 'HRMO II']);
        $training = $this->training($employee);
        $audit = app(AuditLogger::class);

        $audit->recordFor($officer, AuditEvent::PdsViewed, 'Viewed the PDS', $employee);
        $audit->recordFor($officer, AuditEvent::LdCertificateViewed, 'Opened a certificate', $training);
        $audit->recordFor($officer, AuditEvent::EmployeeViewed, 'Viewed the pay profile', $employee, ['section' => 'pay_profile']);
        // Not listed: their own visit, someone else's record, a report about everyone, and a change (not an access).
        $audit->recordFor($employee->user, AuditEvent::PdsViewed, 'Own PDS', $employee);
        $audit->recordFor($officer, AuditEvent::PdsViewed, 'Another PDS', $other);
        $audit->recordFor($officer, AuditEvent::LdReportViewed, 'Training report');
        $audit->recordFor($officer, AuditEvent::EmployeeUpdated, 'Updated the record', $employee);

        $this->actingAs($employee->user)->get('/my/record-access')->assertInertia(fn (Assert $page) => $page
            ->component('Portal/MyRecordAccess')
            ->has('entries.data', 3)
            ->where('entries.data', fn ($rows) => collect($rows)->pluck('what')->sort()->values()->all() === ['Pay profile', 'Personal Data Sheet', 'Training certificate']
                && collect($rows)->every(fn ($row) => $row['who'] === 'Maria HR' && $row['position'] === 'HRMO II'))
            ->where('years', [now()->year]));

        $this->actingAs($employee->user)->get('/my/record-access?year=2001')->assertInertia(fn (Assert $page) => $page->has('entries.data', 0));
    }

    public function test_without_an_employee_record_there_is_nothing_to_list(): void
    {
        $this->actingAs(User::factory()->create())->get('/my/record-access')->assertInertia(fn (Assert $page) => $page
            ->component('Portal/MyRecordAccess')
            ->where('entries', null));
    }

    public function test_guests_are_sent_to_sign_in_for_the_access_log(): void
    {
        $this->get('/my/record-access')->assertRedirect('/login');
    }

    private function training(Employee $employee): PdsLearningDevelopment
    {
        $record = new PdsLearningDevelopment;
        $record->forceFill([
            'employee_id' => $employee->id,
            'title' => 'Records Management',
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-01',
            'hours' => 4,
            'ld_type' => 'technical',
            'modality' => 'face_to_face',
            'sponsor' => 'CSC RO VIII',
            'approval_status' => LdApprovalStatus::Approved,
        ])->save();

        return $record;
    }

    private function set(string $key, mixed $value): void
    {
        $setting = Setting::query()->where('key', $key)->firstOrFail();
        $setting->value = $setting->type->serialize($value);
        $setting->save();
    }
}
