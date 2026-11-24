<?php

namespace Tests\Feature\HR;

use App\Models\BiometricImport;
use App\Models\BiometricPunch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The DTR punch tables: a punch is stored once per biometric ID and time,
 * and the ID itself lives on the employee's record.
 */
class DtrSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_punch_cannot_be_stored_twice_for_the_same_id_and_time(): void
    {
        $import = BiometricImport::factory()->create();
        $attributes = ['biometric_id' => '906', 'punched_at' => '2026-03-04 08:01:09', 'biometric_import_id' => $import->id];

        BiometricPunch::query()->create($attributes);

        $this->expectException(QueryException::class);
        BiometricPunch::query()->create($attributes);
    }

    public function test_the_same_time_under_two_ids_is_two_punches(): void
    {
        $import = BiometricImport::factory()->create();
        $base = ['punched_at' => '2026-03-04 08:01:09', 'biometric_import_id' => $import->id];

        BiometricPunch::query()->create($base + ['biometric_id' => '906']);
        BiometricPunch::query()->create($base + ['biometric_id' => '959']);

        $this->assertSame(2, BiometricPunch::query()->count());
    }

    public function test_an_employees_biometric_id_is_unique_but_optional(): void
    {
        Employee::factory()->create(['biometric_id' => null]);
        Employee::factory()->create(['biometric_id' => null]);
        Employee::factory()->create(['biometric_id' => '906']);

        $this->expectException(QueryException::class);
        Employee::factory()->create(['biometric_id' => '906']);
    }

    public function test_an_import_belongs_to_its_uploader(): void
    {
        $this->assertInstanceOf(User::class, BiometricImport::factory()->create()->user);
    }
}
