<?php

namespace App\Support\HR;

use App\Enums\HeadCapacity;
use App\Enums\UnitType;
use App\Models\EmployeeAssignment;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who heads each office today. Someone designated head of the office (an
 * officer-in-charge or caretaker, HR-1b) takes the regular head's place
 * while the designation runs. Otherwise it's the one active account in the
 * office holding the head's position, by type of office:
 *
 * - Regional Support Units: the Director IV (the ORD), else the Chief
 *   Human Resource Specialist (a division), else the Attorney VI (LSD);
 * - Field and Satellite Offices: the Director II.
 *
 * An office with no such person, or more than one, has no head here. Shown
 * on Organizational Units; leave supervisors follow it (Supervisors).
 */
class OfficeHeads
{
    /** Head positions by type of office, first match wins. */
    public const TITLES = [
        UnitType::RegionalSupportUnit->value => ['director iv', 'chief human resource specialist', 'attorney vi'],
        UnitType::FieldOffice->value => ['director ii'],
        UnitType::SatelliteOffice->value => ['director ii'],
    ];

    /** @var ?array<int, array{user: User, capacity: ?HeadCapacity}> */
    private ?array $heads = null;

    /**
     * @return array<int, array{user: User, capacity: ?HeadCapacity}> keyed by office id; `capacity` is null for the regular head
     */
    public function all(): array
    {
        if ($this->heads !== null) {
            return $this->heads;
        }

        $titles = collect(self::TITLES)->flatten()->unique()->values();
        $candidates = User::query()->where('status', 'active')->with('organizationalUnit')
            ->whereNotNull('organizational_unit_id')
            ->whereRaw('LOWER(TRIM(position)) IN ('.$titles->map(fn () => '?')->implode(', ').')', $titles->all())
            ->get()
            ->groupBy('organizational_unit_id');

        $heads = $candidates->map(function (Collection $people) {
            $order = self::TITLES[$people->first()->organizationalUnit->unit_type?->value] ?? [];

            foreach ($order as $title) {
                $holders = $people->filter(fn (User $user) => mb_strtolower(trim((string) $user->position)) === $title);
                if ($holders->count() === 1) {
                    return ['user' => $holders->first(), 'capacity' => null];
                }
                if ($holders->count() > 1) {
                    return null; // more than one: HR has to sort out who
                }
            }

            return null;
        })->filter()->all();

        // The latest designation of an office wins.
        EmployeeAssignment::query()->headingOn()->with('employee.user')->orderBy('effective_date')->orderBy('id')->get()
            ->each(function (EmployeeAssignment $designation) use (&$heads) {
                if ($designation->to_unit_id && $designation->employee?->user?->isActive()) {
                    $heads[$designation->to_unit_id] = ['user' => $designation->employee->user, 'capacity' => $designation->capacity()];
                }
            });

        return $this->heads = $heads;
    }

    public function of(?int $officeId): ?User
    {
        return $officeId ? ($this->all()[$officeId]['user'] ?? null) : null;
    }

    public function isHead(User $user): bool
    {
        return collect($this->all())->contains(fn (array $head) => $head['user']->id === $user->id);
    }
}
