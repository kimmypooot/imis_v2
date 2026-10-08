<?php

namespace Tests\Feature\HR;

use App\Enums\LdApprovalStatus;
use App\Enums\LdType;
use App\Models\Employee;
use App\Models\Pds\PdsLearningDevelopment;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * HR-3c: `imis:import-legacy-ld` against a small, entirely fictional ldms
 * table on a second in-memory connection.
 */
class LegacyLdImportTest extends TestCase
{
    use RefreshDatabase;

    private ConnectionInterface $legacy;

    private Employee $ana;

    private Employee $ben;

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.legacy_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        config(['legacy.connection' => 'legacy_test']);
        $this->legacy = DB::connection('legacy_test');

        $this->legacy->getSchemaBuilder()->create('ld_cscro8', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->integer('user_id');
            foreach (['training_name', 'training_provider', 'training_type', 'training_venue', 'resource_speaker', 'modality', 'approval_status'] as $column) {
                $table->string($column)->nullable();
            }
            $table->decimal('training_hrs', 10, 2)->nullable();
            $table->string('start_date')->nullable();
            $table->string('end_date')->nullable();
            $table->integer('approved_by')->default(0);
            $table->integer('created_by')->default(0);
            $table->integer('otrs_training_id')->default(0);
            $table->string('created_at')->nullable();
            $table->string('updated_at')->nullable();
            $table->string('deleted_at')->nullable();
        });

        $this->ana = Employee::factory()->for(User::factory()->state(['legacy_id' => 1, 'username' => 'asantos']))->create();
        $this->ben = Employee::factory()->for(User::factory()->state(['legacy_id' => 2, 'username' => 'breyes']))->create();
        User::factory()->create(['legacy_id' => 3, 'username' => 'nofile']);
        $reviewer = User::factory()->create(['legacy_id' => 9]);

        $this->legacy->table('ld_cscro8')->insert([
            $this->row(['id' => 101, 'user_id' => 1, 'training_type' => 'Leadership and Management', 'approved_by' => 9, 'created_at' => '2026-03-01 08:00:00']),
            $this->row(['id' => 102, 'user_id' => 1, 'training_type' => 'Coaching Clinic', 'training_name' => 'Coaching', 'end_date' => '2026-02-01']),
            $this->row(['id' => 103, 'user_id' => 2, 'training_type' => 'technical', 'approval_status' => 'pending', 'deleted_at' => '2026-04-01 09:00:00']),
            $this->row(['id' => 104, 'user_id' => 3]),
            $this->row(['id' => 105, 'user_id' => 77]),
            $this->row(['id' => 106, 'user_id' => 2, 'start_date' => null]),
        ]);

        $this->reviewerId = $reviewer->id;
    }

    private int $reviewerId;

    protected function tearDown(): void
    {
        DB::purge('legacy_test');

        parent::tearDown();
    }

    public function test_it_imports_mapped_trainings_and_reports_the_rest(): void
    {
        $this->artisan('imis:import-legacy-ld')
            ->expectsOutputToContain('Import finished.')
            ->expectsOutputToContain('nofile (1)')
            ->expectsOutputToContain('#77 (1)')
            ->assertSuccessful();

        $first = PdsLearningDevelopment::query()->where('legacy_id', 101)->sole();
        $this->assertSame($this->ana->id, $first->employee_id);
        $this->assertSame(LdType::Managerial, $first->ld_type);
        $this->assertNull($first->modality);
        $this->assertSame(LdApprovalStatus::Approved, $first->approval_status);
        $this->assertSame($this->reviewerId, $first->reviewed_by);
        $this->assertSame('National Privacy Commission', $first->sponsor);
        $this->assertSame('Tacloban City', $first->venue);
        $this->assertSame('12.50', $first->hours);
        // Legacy DATETIMEs are Manila time, as the app is.
        $this->assertSame('2026-03-01 08:00:00', $first->created_at->toDateTimeString());

        // An unknown type becomes "Other" with its text; an end before the start becomes the start.
        $second = PdsLearningDevelopment::query()->where('legacy_id', 102)->sole();
        $this->assertSame(LdType::Other, $second->ld_type);
        $this->assertSame('Coaching Clinic', $second->ld_type_other);
        $this->assertSame('2026-03-10', $second->date_to->toDateString());

        // Removed in ldms stays removed.
        $removed = PdsLearningDevelopment::withTrashed()->where('legacy_id', 103)->sole();
        $this->assertTrue($removed->trashed());
        $this->assertSame(LdApprovalStatus::Pending, $removed->approval_status);
        $this->assertNull($removed->reviewed_by);

        $this->assertSame(3, PdsLearningDevelopment::withTrashed()->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'ld_record.imported']);
    }

    public function test_re_running_skips_what_was_imported_and_keeps_hr_corrections(): void
    {
        $this->artisan('imis:import-legacy-ld')->assertSuccessful();
        PdsLearningDevelopment::query()->where('legacy_id', 101)->update(['title' => 'Corrected by HR']);

        $this->artisan('imis:import-legacy-ld')->assertSuccessful();

        $this->assertSame(3, PdsLearningDevelopment::withTrashed()->count());
        $this->assertSame('Corrected by HR', PdsLearningDevelopment::query()->where('legacy_id', 101)->value('title'));
    }

    public function test_a_training_already_logged_here_is_not_duplicated(): void
    {
        $existing = new PdsLearningDevelopment;
        $existing->forceFill([
            'employee_id' => $this->ana->id, 'title' => 'DATA PRIVACY ACT ORIENTATION', 'date_from' => '2026-03-10', 'date_to' => '2026-03-10',
            'hours' => 8, 'ld_type' => 'technical', 'approval_status' => 'approved',
        ])->save();

        $this->artisan('imis:import-legacy-ld')->assertSuccessful();

        $this->assertFalse(PdsLearningDevelopment::query()->where('legacy_id', 101)->exists());
        $this->assertTrue(PdsLearningDevelopment::query()->where('legacy_id', 102)->exists());
    }

    public function test_a_dry_run_saves_nothing(): void
    {
        $this->artisan('imis:import-legacy-ld --dry-run')
            ->expectsOutputToContain('Dry run: nothing was saved.')
            ->assertSuccessful();

        $this->assertSame(0, PdsLearningDevelopment::withTrashed()->count());
        $this->assertDatabaseMissing('audit_logs', ['event' => 'ld_record.imported']);
    }

    public function test_it_refuses_before_accounts_have_legacy_ids(): void
    {
        User::query()->update(['legacy_id' => null]);

        $this->artisan('imis:import-legacy-ld')->expectsOutputToContain('imis:import-legacy')->assertFailed();
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function row(array $values): array
    {
        return $values + [
            'training_name' => 'Data Privacy Act Orientation',
            'training_provider' => 'National Privacy Commission',
            'training_type' => 'Technical',
            'training_venue' => 'Tacloban City',
            'resource_speaker' => 'Atty. Reyes',
            'modality' => 'face-to-face',
            'approval_status' => 'approved',
            'training_hrs' => 12.5,
            'start_date' => '2026-03-10',
            'end_date' => '2026-03-11',
            'approved_by' => 0,
            'created_at' => null,
            'deleted_at' => null,
        ];
    }
}
