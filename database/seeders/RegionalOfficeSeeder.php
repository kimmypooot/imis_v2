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
     * @var array<int, array{code: string, name: string, type: UnitType, parent?: string, label?: string, plantilla_only?: bool}>
     */
    private const OFFICES = [
        ['code' => 'ORD', 'name' => 'Office of the Regional Director', 'type' => UnitType::RegionalSupportUnit],
        ['code' => 'ESD', 'name' => 'Examination Services Division', 'type' => UnitType::RegionalSupportUnit],
        ['code' => 'MSD', 'name' => 'Management Services Division', 'type' => UnitType::RegionalSupportUnit],
        ['code' => 'HRD', 'name' => 'Human Resource Division', 'type' => UnitType::RegionalSupportUnit],
        ['code' => 'PALD', 'name' => 'Public Assistance and Liaison Division', 'type' => UnitType::RegionalSupportUnit],
        ['code' => 'PSED', 'name' => 'Policies and Systems Evaluation Division', 'type' => UnitType::RegionalSupportUnit],
        ['code' => 'LSD', 'name' => 'Legal Services Division', 'type' => UnitType::RegionalSupportUnit],
        ['code' => 'LFO', 'name' => 'CSC Field Office - Leyte', 'type' => UnitType::FieldOffice, 'label' => 'FO-Leyte', 'plantilla_only' => true],
        ['code' => 'LFO-I', 'name' => 'CSC Field Office - Leyte I', 'type' => UnitType::FieldOffice, 'parent' => 'LFO', 'label' => 'FO-Leyte I'],
        ['code' => 'LFO-II', 'name' => 'CSC Field Office - Leyte II', 'type' => UnitType::FieldOffice, 'parent' => 'LFO', 'label' => 'FO-Leyte II'],
        ['code' => 'ESFO', 'name' => 'CSC Field Office - Eastern Samar', 'type' => UnitType::FieldOffice, 'label' => 'FO-ES'],
        ['code' => 'SFO', 'name' => 'CSC Field Office - Samar', 'type' => UnitType::FieldOffice, 'label' => 'FO-S'],
        ['code' => 'BFO', 'name' => 'CSC Field Office - Biliran', 'type' => UnitType::FieldOffice, 'label' => 'FO-B'],
        ['code' => 'SLFO', 'name' => 'CSC Field Office - Southern Leyte', 'type' => UnitType::FieldOffice, 'label' => 'FO-SL'],
        ['code' => 'NSFO', 'name' => 'CSC Field Office - Northern Samar', 'type' => UnitType::FieldOffice, 'label' => 'FO-NS'],
        ['code' => 'WLSO', 'name' => 'CSC Satellite Office - Western Leyte', 'type' => UnitType::SatelliteOffice, 'parent' => 'BFO', 'label' => 'SO-WL'],
    ];

    public function run(): void
    {
        foreach (self::OFFICES as $order => $office) {
            OrganizationalUnit::query()->firstOrCreate(
                ['code' => $office['code']],
                [
                    'name' => $office['name'],
                    'slug' => OrganizationalUnit::generateUniqueSlug($office['name']),
                    'short_name' => $office['label'] ?? $office['code'],
                    'unit_type' => $office['type'],
                    'status' => Status::Active,
                    'plantilla_only' => $office['plantilla_only'] ?? false,
                    'sort_order' => $order + 1,
                    'parent_id' => isset($office['parent']) ? OrganizationalUnit::query()->where('code', $office['parent'])->value('id') : null,
                ],
            );
        }
    }
}
