<?php

namespace Tests\Feature;

use App\Enums\UnitType;
use App\Models\OrganizationalUnit;
use Database\Seeders\RegionalOfficeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegionalOfficeSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_the_fifteen_regional_offices_by_type(): void
    {
        $this->seed(RegionalOfficeSeeder::class);

        $this->assertSame(7, OrganizationalUnit::where('unit_type', UnitType::RegionalSupportUnit)->count());
        $this->assertSame(7, OrganizationalUnit::where('unit_type', UnitType::FieldOffice)->count());
        $this->assertSame(1, OrganizationalUnit::where('unit_type', UnitType::SatelliteOffice)->count());
        $this->assertDatabaseHas('organizational_units', ['code' => 'ESD', 'name' => 'Examination Services Division']);
    }

    public function test_is_idempotent_and_keeps_admin_edits(): void
    {
        $this->seed(RegionalOfficeSeeder::class);
        OrganizationalUnit::where('code', 'ESD')->update(['description' => 'Edited by an admin']);

        $this->seed(RegionalOfficeSeeder::class);

        $this->assertSame(15, OrganizationalUnit::count());
        $this->assertSame('Edited by an admin', OrganizationalUnit::where('code', 'ESD')->value('description'));
    }
}
