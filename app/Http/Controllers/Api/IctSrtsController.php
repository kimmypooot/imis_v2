<?php

namespace App\Http\Controllers\Api;

use App\Enums\UnitType;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\OrganizationalUnit;
use App\Models\User;
use App\Support\IctSrts\ItgCommittee;
use Illuminate\Http\JsonResponse;

/**
 * The current employees, for ICT SRTS: who can be added to the ICT Group and
 * named as a signatory. Active accounts only, with the office each belongs to
 * (their unit, or the nearest ancestor that is a regional unit, field office or
 * satellite office), as in the SSO payload, and whether they are in the
 * Information Technology Group committee. Called server-to-server
 * (AuthenticateIctSrts).
 */
class IctSrtsController extends Controller
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

        $itg = ItgCommittee::memberIds()->flip();

        $employees = User::query()
            ->where('status', UserStatus::Active)
            ->orderBy('last_name')->orderBy('first_name')
            ->get(['id', 'name', 'username', 'email', 'position', 'organizational_unit_id'])
            ->map(function (User $user) use ($officeOf, $itg) {
                $office = $officeOf($user->organizational_unit_id);

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'email' => $user->email,
                    'position' => $user->position,
                    'office_name' => $office?->name,
                    'office_code' => $office?->code,
                    'in_itg' => $itg->has($user->id),
                ];
            });

        return response()->json(['data' => $employees->values()]);
    }
}
