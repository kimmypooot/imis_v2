<?php

namespace Database\Seeders;

use App\Enums\Status;
use App\Enums\UnitType;
use App\Models\OrganizationalUnit;
use Illuminate\Database\Seeder;

/**
 * The real CSC Regional Office VIII offices, taken verbatim from the
 * legacy IMIS user-account division map (admin/backend/insert/insert_user.php):
 * 7 Regional Support Units, 7 field offices, and 1 satellite office.
 *
 * Idempotent (matched by code) and non-destructive: an office an admin has
 * since edited keeps those edits. Descriptions, contact details, and any
 * parent/child structure are left for administrators to fill in — none of
 * that is established by the legacy data, so none is invented here.
 */
class RegionalOfficeSeeder extends Seeder
{
    /**
     * @var array<int, array{code: string, name: string, type: UnitType}>
     */
    private const OFFICES = [
        ['code' => 'ORD', 'name' => 'Office of the Regional Director', 'type' => UnitType::RegionalSupportUnit],
        ['code' => 'ESD', 'name' => 'Examination Services Division', 'type' => UnitType::RegionalSupportUnit],
        ['code' => 'MSD', 'name' => 'Management Services Division', 'type' => UnitType::RegionalSupportUnit],
        ['code' => 'HRD', 'name' => 'Human Resource Division', 'type' => UnitType::RegionalSupportUnit],
        ['code' => 'PALD', 'name' => 'Public Assistance and Liaison Division', 'type' => UnitType::RegionalSupportUnit],
        ['code' => 'PSED', 'name' => 'Policies and Systems Evaluation Division', 'type' => UnitType::RegionalSupportUnit],
        ['code' => 'LSD', 'name' => 'Legal Services Division', 'type' => UnitType::RegionalSupportUnit],
        ['code' => 'LFO-I', 'name' => 'CSC Field Office - Leyte I', 'type' => UnitType::FieldOffice],
        ['code' => 'LFO-II', 'name' => 'CSC Field Office - Leyte II', 'type' => UnitType::FieldOffice],
        ['code' => 'ESFO', 'name' => 'CSC Field Office - Eastern Samar', 'type' => UnitType::FieldOffice],
        ['code' => 'SFO', 'name' => 'CSC Field Office - Samar', 'type' => UnitType::FieldOffice],
        ['code' => 'BFO', 'name' => 'CSC Field Office - Biliran', 'type' => UnitType::FieldOffice],
        ['code' => 'SLFO', 'name' => 'CSC Field Office - Southern Leyte', 'type' => UnitType::FieldOffice],
        ['code' => 'NSFO', 'name' => 'CSC Field Office - Northern Samar', 'type' => UnitType::FieldOffice],
        ['code' => 'WLSO', 'name' => 'CSC Satellite Office - Western Leyte', 'type' => UnitType::SatelliteOffice],
    ];

    public function run(): void
    {
        foreach (self::OFFICES as $order => $office) {
            OrganizationalUnit::query()->firstOrCreate(
                ['code' => $office['code']],
                [
                    'name' => $office['name'],
                    'slug' => OrganizationalUnit::generateUniqueSlug($office['name']),
                    'short_name' => $office['code'],
                    'unit_type' => $office['type'],
                    'status' => Status::Active,
                    'sort_order' => $order + 1,
                ],
            );
        }
    }
}
