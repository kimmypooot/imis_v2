<?php

namespace App\Http\Controllers\Api;

use App\Enums\UnitType;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\OrganizationalUnit;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * The current employees, for ETTS: who can be suggested as the addressee or
 * signatory of a routing slip or an authenticated copy. Active accounts only,
 * with the office each belongs to (their unit, or the nearest ancestor that is
 * a regional unit, field office or satellite office), as in the SSO payload.
 * Name, position and office only: no e-mail, no username. Called
 * server-to-server (AuthenticateEtts).
 */
class EttsController extends Controller
{
    public function employees(): JsonResponse
    {
        $units = OrganizationalUnit::query()->get(['id', 'parent_id', 'code', 'name', 'unit_type'])->keyBy('id');

        $officeOf = function (?int $unitId) use ($units): ?OrganizationalUnit {
            for ($unit = $units->get($unitId); $unit !== null; $unit = $units->get($unit->parent_id)) {
                if ($unit->unit_type !== UnitType::Other) {
                    return $unit;
                }
            }

            return null;
        };

        $employees = User::query()
            ->where('status', UserStatus::Active)
            ->orderBy('last_name')->orderBy('first_name')
            ->get(['id', 'name', 'position', 'organizational_unit_id'])
            ->map(function (User $user) use ($officeOf) {
                $office = $officeOf($user->organizational_unit_id);

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'position' => $user->position,
                    'office_name' => $office?->name,
                    'office_code' => $office?->code,
                ];
            });

        return response()->json(['data' => $employees->values()]);
    }
}
