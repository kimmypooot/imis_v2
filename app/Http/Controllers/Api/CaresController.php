<?php

namespace App\Http\Controllers\Api;

use App\Enums\UnitType;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\OrganizationalUnit;
use App\Models\Position;
use App\Models\User;
use App\Support\HR\OfficeHeads;
use Illuminate\Http\JsonResponse;

/**
 * The people directory, for CARES (Centralized Appointment Attestation and
 * Reporting via Electronic System): the employees who can be named as the
 * action officer of an appointment, the staff of each Field Office who prepare
 * its reports, and who heads each office today, which is who approves them.
 *
 * `data`: active accounts only, with the name in parts (for a signature line),
 * `position`, and the office each belongs to (their unit, or the nearest
 * ancestor that is a regional unit, field office or satellite office), as in the
 * SSO payload. No e-mail, no username.
 *
 * `heads`: one entry per office that has a head, from OfficeHeads (the same
 * answer as the Organizational Units page): the Director II, or while one is
 * designated the Officer-in-Charge or Caretaker. `capacity` is null for the
 * regular head. Called server-to-server (AuthenticateCares).
 */
class CaresController extends Controller
{
    public function employees(OfficeHeads $heads): JsonResponse
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

        // Salary grade by position title, from the positions catalog (HR-4). Null for a title not in it.
        $grades = Position::query()->get(['title', 'salary_grade'])->mapWithKeys(fn (Position $position) => [mb_strtolower(trim($position->title)) => $position->salary_grade]);

        $employees = User::query()
            ->where('status', UserStatus::Active)
            ->orderBy('last_name')->orderBy('first_name')
            ->get(['id', 'name', 'first_name', 'middle_name', 'last_name', 'name_extension', 'position', 'organizational_unit_id'])
            ->map(function (User $user) use ($officeOf, $grades) {
                $office = $officeOf($user->organizational_unit_id);

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'first_name' => $user->first_name,
                    'middle_name' => $user->middle_name,
                    'last_name' => $user->last_name,
                    'name_extension' => $user->name_extension,
                    'position' => $user->position,
                    'salary_grade' => $grades->get(mb_strtolower(trim((string) $user->position))),
                    'office_name' => $office?->name,
                    'office_code' => $office?->code,
                    'office_type' => $office?->unit_type->value,
                ];
            });

        $headRows = collect($heads->all())->map(function (array $head, int $unitId) use ($units) {
            $unit = $units->get($unitId);

            return $unit === null ? null : [
                'office_code' => $unit->code,
                'office_name' => $unit->name,
                'office_type' => $unit->unit_type->value,
                'user_id' => $head['user']->id,
                'capacity' => $head['capacity']?->value,
                'capacity_label' => $head['capacity']?->label(),
            ];
        })->filter()->values();

        return response()->json(['data' => $employees->values(), 'heads' => $headRows]);
    }
}
