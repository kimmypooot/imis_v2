<?php

namespace App\Support\HR\Leave;

use App\Models\Employee;
use App\Models\User;
use App\Support\HR\OfficeHeads;
use App\Support\Settings\Settings;

/**
 * HR-6: who recommends someone's leave (CS Form 6, Part B). The supervisor
 * HR sets on their leave page wins; otherwise it follows their position
 * and office:
 *
 * - staff of an office: its head (OfficeHeads) — the Chief Human Resource
 *   Specialist of a Regional Support Unit, the Director II of a Field or
 *   Satellite Office, or whoever heads it by designation today (OIC,
 *   Caretaker);
 * - staff of the Office of the Regional Director (ORD), the Director III,
 *   the Chief Human Resource Specialists and the Attorney VI: the
 *   Director IV;
 * - Director IV: the Chairperson of the Civil Service Commission, who is
 *   outside IMIS (named in System Settings → Human Resources).
 *
 * Anyone else the rule can't place (e.g. a Director II, or an office with
 * no head) needs HR to set theirs.
 */
class Supervisors
{
    public const CHAIRPERSON_SETTING = 'hr_csc_chairperson';

    public const CHAIRPERSON_TITLE = 'Chairperson, Civil Service Commission';

    /** Positions whose supervisor is the Director IV, wherever they serve. */
    private const REPORT_TO_DIRECTOR_IV = ['director iii', 'chief human resource specialist', 'attorney vi'];

    /** @var ?array{offices: array<int, User>, director_iv: ?User} */
    private ?array $heads = null;

    public function __construct(private readonly OfficeHeads $officeHeads) {}

    /**
     * The supervisor in effect: `id` is null when they're outside IMIS
     * (the Chairperson), `source` says where it came from (null: none).
     *
     * @return array{id: ?int, name: ?string, position: ?string, source: ?string}
     */
    public function for(Employee $employee): array
    {
        $employee->loadMissing(['user.organizationalUnit', 'immediateSupervisor']);

        if ($set = $employee->immediateSupervisor) {
            return ['id' => $set->id, 'name' => $set->name, 'position' => $set->position, 'source' => 'set'];
        }

        return $this->byRule($employee) ?? ['id' => null, 'name' => null, 'position' => null, 'source' => null];
    }

    /**
     * What the position rule alone gives (ignoring what HR set).
     *
     * @return ?array{id: ?int, name: ?string, position: ?string, source: string}
     */
    public function byRule(Employee $employee): ?array
    {
        $user = $employee->loadMissing('user.organizationalUnit')->user;
        $position = mb_strtolower(trim((string) $user?->position));

        if ($position === 'director iv') {
            return ['id' => null, 'name' => Settings::get(self::CHAIRPERSON_SETTING), 'position' => self::CHAIRPERSON_TITLE, 'source' => 'external'];
        }

        $head = in_array($position, self::REPORT_TO_DIRECTOR_IV, true) || $user?->organizationalUnit?->code === 'ORD'
            ? $this->heads()['director_iv']
            : $this->heads()['offices'][$user?->organizational_unit_id] ?? null;

        return $head && $head->id !== $user?->id
            ? ['id' => $head->id, 'name' => $head->name, 'position' => $head->position, 'source' => 'rule']
            : null;
    }

    /** The user ID of the supervisor in effect, for routing a filing. */
    public function idFor(Employee $employee): ?int
    {
        return $this->for($employee)['id'];
    }

    /**
     * Whether the rule makes this person anyone's supervisor (heads an
     * office, or is the Director IV).
     */
    public function supervisesByRule(User $user): bool
    {
        $heads = $this->heads();

        return collect($heads['offices'])->contains('id', $user->id) || $heads['director_iv']?->id === $user->id;
    }

    /**
     * Each office's head today (OfficeHeads), and the Director IV. Worked
     * out once.
     *
     * @return array{offices: array<int, User>, director_iv: ?User}
     */
    private function heads(): array
    {
        if ($this->heads !== null) {
            return $this->heads;
        }

        $directors = User::query()->where('status', 'active')->whereRaw('LOWER(TRIM(position)) = ?', ['director iv'])->get();

        return $this->heads = [
            'offices' => array_map(fn (array $head) => $head['user'], $this->officeHeads->all()),
            'director_iv' => $directors->count() === 1 ? $directors->first() : null,
        ];
    }
}
