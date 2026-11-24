<?php

namespace Tests\Feature\HR;

use App\Models\BiometricImport;
use App\Models\BiometricPunch;
use App\Models\Employee;
use App\Models\User;
use App\Support\HR\Dtr\BiometricImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Storing a device log: every punch once, however many times and in
 * however large a file it is uploaded.
 */
class BiometricImporterTest extends TestCase
{
    use RefreshDatabase;

    private const FILE_A = [
        "906\t2026-03-04 08:01:09\t12\t0\t0\t0",
        "906\t2026-03-04 20:41:36\t12\t1\t0\t0",
        "959\t2026-03-02 07:23:11\t12\t0\t0\t0",
    ];

    public function test_new_punches_are_stored_and_counted(): void
    {
        $import = $this->import(self::FILE_A);

        $this->assertSame(3, BiometricPunch::query()->count());
        $this->assertSame([3, 3, 0, 0], [$import->rows_read, $import->rows_new, $import->rows_duplicate, $import->rows_skipped]);
        $this->assertSame('2026-03-02 07:23:11', $import->first_punch_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-04 20:41:36', $import->last_punch_at->format('Y-m-d H:i:s'));
        $this->assertSame(64, strlen($import->file_hash));
        $this->assertSame('attlog.dat', $import->file_name);
    }

    public function test_importing_the_same_file_twice_adds_nothing(): void
    {
        $this->import(self::FILE_A);

        $second = $this->import(self::FILE_A);

        $this->assertSame(3, BiometricPunch::query()->count());
        $this->assertSame([3, 0, 3], [$second->rows_read, $second->rows_new, $second->rows_duplicate]);
    }

    public function test_a_larger_file_only_adds_the_missing_punches(): void
    {
        $this->import(self::FILE_A);

        $second = $this->import([...self::FILE_A, "906\t2026-03-05 06:31:18\t12\t0\t0\t0", "906\t2026-03-05 23:08:37\t12\t1\t0\t0"]);

        $this->assertSame(5, BiometricPunch::query()->count());
        $this->assertSame([5, 2, 3], [$second->rows_read, $second->rows_new, $second->rows_duplicate]);
    }

    public function test_repeated_punches_inside_one_file_are_counted_as_duplicates(): void
    {
        $import = $this->import([self::FILE_A[0], self::FILE_A[0]]);

        $this->assertSame(1, BiometricPunch::query()->count());
        $this->assertSame([2, 1, 1], [$import->rows_read, $import->rows_new, $import->rows_duplicate]);
    }

    public function test_malformed_rows_are_reported_as_skipped(): void
    {
        $import = $this->import([self::FILE_A[0], 'garbage']);

        $this->assertSame([1, 1, 1], [$import->rows_read, $import->rows_new, $import->rows_skipped]);
    }

    public function test_a_large_file_is_imported_without_holding_every_row_in_memory(): void
    {
        $start = Carbon::parse('2026-01-01 06:00:00');
        $lines = [];
        for ($i = 0; $i < 40000; $i++) {
            $lines[] = "906\t".$start->copy()->addMinutes($i)->format('Y-m-d H:i:s')."\t12\t0\t0\t0";
        }

        memory_reset_peak_usage();
        $before = memory_get_peak_usage();
        $import = $this->import($lines);
        $used = memory_get_peak_usage() - $before;

        $this->assertSame(40000, $import->rows_new);
        $this->assertSame(40000, BiometricPunch::query()->count());
        $this->assertLessThan(30 * 1024 * 1024, $used, 'Importing holds too many rows in memory.');
    }

    public function test_unmatched_ids_lists_only_ids_no_employee_has(): void
    {
        $this->import([...self::FILE_A, "1\t2026-03-04 09:00:00\t12\t0\t0\t0"]);
        Employee::factory()->create(['biometric_id' => '959']);

        $unmatched = (new BiometricImporter)->unmatchedIds();

        $this->assertSame(['1', '906'], $unmatched->pluck('biometric_id')->all());
        $this->assertSame(['biometric_id' => '906', 'punches' => 2, 'first_at' => '2026-03-04 08:01:09', 'last_at' => '2026-03-04 20:41:36'], $unmatched->last());
    }

    public function test_setting_an_employees_id_afterwards_claims_the_punches_already_on_file(): void
    {
        $this->import(self::FILE_A);
        $this->assertCount(2, (new BiometricImporter)->unmatchedIds());

        Employee::factory()->create(['biometric_id' => '906']);

        $this->assertSame(['959'], (new BiometricImporter)->unmatchedIds()->pluck('biometric_id')->all());
    }

    /**
     * @param  list<string>  $lines
     */
    public function test_known_only_keeps_just_the_ids_on_a_profile(): void
    {
        Employee::factory()->create(['biometric_id' => '959']);

        $import = $this->import(self::FILE_A, knownOnly: true);

        $this->assertSame(['959'], BiometricPunch::query()->pluck('biometric_id')->map(fn ($id) => (string) $id)->all());
        $this->assertSame([3, 1, 0], [$import->rows_read, $import->rows_new, $import->rows_duplicate]);
        $this->assertSame('2026-03-02 07:23:11', $import->first_punch_at->format('Y-m-d H:i:s'));
    }

    private function import(array $lines, bool $knownOnly = false): BiometricImport
    {
        $path = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($path, implode("\r\n", $lines)."\r\n");

        try {
            return (new BiometricImporter)->import($path, 'attlog.dat', User::factory()->create(), $knownOnly);
        } finally {
            unlink($path);
        }
    }
}
