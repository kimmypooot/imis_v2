<?php

namespace Tests\Feature\Portal;

use App\Models\BiometricImport;
use App\Models\BiometricPunch;
use App\Models\Employee;
use App\Models\EmployeeWorkSchedule;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Support\Portal\AtAGlance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

class AtAGlanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_someone_without_an_employee_record_has_no_tiles_since_the_bell_shows_notifications(): void
    {
        $this->assertSame([], app(AtAGlance::class)->for(User::factory()->create()));
    }

    public function test_an_employee_sees_this_months_dtr_instead_of_a_notifications_tile(): void
    {
        $this->travelTo('2026-11-26 10:00:00');
        $employee = Employee::factory()->create(['biometric_id' => '906']);

        $tiles = collect(app(AtAGlance::class)->for($employee->user))->keyBy('key');

        $this->assertFalse($tiles->has('notifications'));
        $this->assertSame('Late & undertime', $tiles['dtr']['label']);
        $this->assertSame('0:00', $tiles['dtr']['value']);
        $this->assertSame('November', $tiles['dtr']['hint']);
        $this->assertSame('/my/dtr', $tiles['dtr']['href']);
    }

    public function test_last_months_unsigned_sheet_with_hours_asks_to_be_signed(): void
    {
        $this->travelTo('2026-11-26 10:00:00');
        $employee = Employee::factory()->create(['biometric_id' => '906']);
        EmployeeWorkSchedule::factory()->create(['employee_id' => $employee->id, 'work_schedule_id' => WorkSchedule::query()->where('name', 'like', 'Compressed%')->value('id'), 'valid_from' => '2026-01-01']);
        $import = BiometricImport::factory()->create();
        foreach (['2026-10-06 07:51:00', '2026-10-06 19:20:00'] as $time) {
            BiometricPunch::query()->create(['biometric_id' => '906', 'punched_at' => $time, 'biometric_import_id' => $import->id]);
        }

        $tile = collect(app(AtAGlance::class)->for($employee->user))->firstWhere('key', 'dtr');

        $this->assertSame('sign October', $tile['hint']);
        $this->assertSame('/my/dtr?month=2026-10', $tile['href']);
        $this->assertSame('warning', $tile['tone']);
    }

    public function test_the_notifications_page_carries_each_notifications_kind(): void
    {
        $user = User::factory()->create();
        DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'data' => ['title' => 'Hello', 'message' => 'World', 'kind' => 'leave'],
        ]);

        $this->actingAs($user)->get('/notifications')
            ->assertInertia(fn ($page) => $page->where('notifications.0.kind', 'leave'));
    }
}
