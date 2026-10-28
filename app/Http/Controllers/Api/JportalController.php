<?php

namespace App\Http\Controllers\Api;

use App\Enums\UnitType;
use App\Http\Controllers\Controller;
use App\Models\OrganizationalUnit;
use App\Support\HR\UnitLeadership;
use Illuminate\Http\JsonResponse;

/**
 * JPortal's public office directory (portal.cscro8.com/directory): the
 * regional support units and field/satellite offices IMIS already tracks,
 * read live instead of hand-copied onto the public site. Called
 * server-to-server (AuthenticateJportal).
 */
class JportalController extends Controller
{
    public function directory(UnitLeadership $leadership): JsonResponse
    {
        $units = OrganizationalUnit::query()
            ->active()
            ->whereIn('unit_type', [
                UnitType::RegionalSupportUnit->value,
                UnitType::FieldOffice->value,
                UnitType::SatelliteOffice->value,
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $units->map(fn (OrganizationalUnit $unit) => [
            'id' => $unit->id,
            'code' => $unit->code,
            'name' => $unit->name,
            'short_name' => $unit->short_name,
            'unit_type' => $unit->unit_type?->value,
            'unit_type_label' => $unit->unit_type?->label(),
            'email' => $unit->email,
            'telephone' => $unit->telephone,
            'address' => $unit->address,
            'heads' => array_map(fn (array $entry) => [
                'name' => $entry['user']->name,
                'position' => $entry['user']->position,
                'capacity_label' => $entry['capacity']?->label(),
            ], $leadership->at($unit->id)),
        ])->values()]);
    }
}
