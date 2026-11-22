<?php

namespace Tests\Feature;

use App\Enums\UnitType;
use App\Models\Application;
use App\Models\ApplicationAccess;
use App\Models\ApplicationRole;
use App\Models\OrganizationalUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TIMS's Collecting Officer role is removed, and whoever held it is moved by
 * the office they work in rather than left with a grant TIMS refuses.
 */
class TimsCollectingOfficerRemovalTest extends TestCase
{
    use RefreshDatabase;

    private function migrate(): void
    {
        (require database_path('migrations/2027_03_11_090000_remove_collecting_officer_role_from_tims.php'))->up();
    }

    public function test_holders_move_by_office_and_the_role_is_removed(): void
    {
        $tims = Application::factory()->create(['short_name' => 'TIMS']);
        $roles = collect(['field-office', 'admin', 'management', 'collecting-officer'])
            ->mapWithKeys(fn (string $code) => [$code => ApplicationRole::factory()->for($tims)->create(['code' => $code])]);

        $fieldOffice = OrganizationalUnit::factory()->create(['code' => 'LFO-I', 'unit_type' => UnitType::FieldOffice]);
        $cashier = OrganizationalUnit::factory()->create(['parent_id' => $fieldOffice->id, 'unit_type' => UnitType::Other]);
        $hrd = OrganizationalUnit::factory()->create(['code' => 'HRD', 'unit_type' => UnitType::RegionalSupportUnit]);
        $ord = OrganizationalUnit::factory()->create(['code' => 'ORD', 'unit_type' => UnitType::RegionalSupportUnit]);
        $msd = OrganizationalUnit::factory()->create(['code' => 'MSD', 'unit_type' => UnitType::RegionalSupportUnit]);

        $holder = function (OrganizationalUnit $unit) use ($tims, $roles): ApplicationAccess {
            return ApplicationAccess::factory()->create([
                'application_id' => $tims->id,
                'application_role_id' => $roles['collecting-officer']->id,
                'user_id' => User::factory()->create(['organizational_unit_id' => $unit->id])->id,
            ]);
        };

        $inField = $holder($cashier);
        $inHrd = $holder($hrd);
        $inOrd = $holder($ord);
        $inMsd = $holder($msd);

        // A designation grant that would fall back to the retired role.
        $designated = ApplicationAccess::factory()->create([
            'application_id' => $tims->id,
            'application_role_id' => $roles['admin']->id,
            'via_designation' => true,
            'designation_previous_role_id' => $roles['collecting-officer']->id,
            'user_id' => User::factory()->create(['organizational_unit_id' => $fieldOffice->id])->id,
        ]);

        $this->migrate();
        $this->migrate(); // again: nothing left to do

        $this->assertSame($roles['field-office']->id, $inField->fresh()->application_role_id);
        $this->assertSame($roles['admin']->id, $inHrd->fresh()->application_role_id);
        $this->assertSame($roles['management']->id, $inOrd->fresh()->application_role_id);
        $this->assertNull($inMsd->fresh(), 'A holder outside the TIMS offices keeps no grant.');
        $this->assertSame($roles['field-office']->id, $designated->fresh()->designation_previous_role_id);
        $this->assertNull(ApplicationRole::find($roles['collecting-officer']->id));
    }

    public function test_it_does_nothing_without_tims(): void
    {
        $other = Application::factory()->create(['short_name' => 'OTRS']);
        $role = ApplicationRole::factory()->for($other)->create(['code' => 'collecting-officer']);

        $this->migrate();

        $this->assertNotNull($role->fresh());
    }
}
