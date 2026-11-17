<?php

namespace App\Http\Controllers\Api;

use App\Enums\UnitType;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\OrganizationalUnit;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * The current employees, for BMIS's Employee manager and organization chart:
 * active accounts only, with the office each belongs to (their unit, or the
 * nearest ancestor that is a regional unit, field office or satellite office)
 * and that unit's own name. Called server-to-server (AuthenticateBmis).
 */
class BmisController extends Controller
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
            ->get(['id', 'name', 'username', 'email', 'position', 'organizational_unit_id', 'profile_photo_path'])
            ->map(function (User $user) use ($officeOf, $units) {
                $office = $officeOf($user->organizational_unit_id);

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'email' => $user->email,
                    'position' => $user->position,
                    'unit_name' => $units->get($user->organizational_unit_id)?->name,
                    'office_name' => $office?->name,
                    'office_code' => $office?->code,
                    'photo_url' => $user->profile_photo_url,
                ];
            });

        return response()->json(['data' => $employees->values()]);
    }
}
