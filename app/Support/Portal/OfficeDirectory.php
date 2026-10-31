<?php

namespace App\Support\Portal;

use App\Enums\EmploymentStatus;
use App\Enums\HeadCapacity;
use App\Enums\UnitType;
use App\Enums\UserStatus;
use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\Position;
use App\Models\User;
use App\Support\HR\OfficeHeads;
use App\Support\HR\UnitLeadership;
use Illuminate\Support\Collection;

/**
 * The Organizational Units page: top-level offices grouped as Regional
 * Support Units and Field Offices (Satellite Offices count as field
 * offices), each with the people serving there now. A person is listed
 * under the office they currently serve in, so a reassignment moves them.
 *
 * Within an office the order is: its leaders first (the head, then any
 * other leader such as the ORD's Director III), then everyone else by
 * salary grade, highest first, then by surname. Contract of Service and
 * Job Order workers come last. The grade is that of the position matching
 * the person's title.
 *
 * Only what an internal directory needs is exposed (name, position,
 * photo, and whether they head the office), for active accounts.
 */
class OfficeDirectory
{
    /** Not government employees: listed after everyone else. */
    private const LAST = [EmploymentStatus::ContractOfService, EmploymentStatus::JobOrder];

    public function __construct(
        private readonly UnitLeadership $leadership,
        private readonly OfficeHeads $heads,
    ) {}

    /**
     * @return array<int, array{key: string, label: string, units: array<int, array<string, mixed>>}>
     */
    public function groups(): array
    {
        $units = OrganizationalUnit::query()
            ->active()
            ->roots()
            ->withCount('children')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $people = User::query()
            ->where('status', UserStatus::Active)
            ->whereIn('organizational_unit_id', $units->pluck('id'))
            ->get(['id', 'name', 'last_name', 'position', 'organizational_unit_id', 'profile_photo_path']);

        $grades = Position::query()->get(['title', 'salary_grade'])
            ->mapWithKeys(fn (Position $position) => [$this->normalize($position->title) => (int) $position->salary_grade]);

        $trailing = Employee::query()
            ->whereIn('user_id', $people->pluck('id'))
            ->whereIn('employment_status', array_map(fn (EmploymentStatus $status) => $status->value, self::LAST))
            ->pluck('user_id')
            ->flip();

        $people = $people->groupBy('organizational_unit_id');
        $leaders = $this->leadership->all();
        $headOf = $this->heads->all();

        $groups = [
            'regional_support_units' => ['label' => UnitType::RegionalSupportUnit->pluralLabel(), 'units' => []],
            'field_offices' => ['label' => UnitType::FieldOffice->pluralLabel(), 'units' => []],
            'other' => ['label' => UnitType::Other->pluralLabel(), 'units' => []],
        ];

        foreach ($units as $unit) {
            $key = match ($unit->unit_type) {
                UnitType::RegionalSupportUnit => 'regional_support_units',
                UnitType::FieldOffice, UnitType::SatelliteOffice => 'field_offices',
                default => 'other',
            };

            $groups[$key]['units'][] = $this->present(
                $unit,
                $people->get($unit->id, collect()),
                $leaders[$unit->id] ?? [],
                $headOf[$unit->id]['user']->id ?? null,
                $grades,
                $trailing,
            );
        }

        return collect($groups)
            ->filter(fn (array $group) => $group['units'] !== [])
            ->map(fn (array $group, string $key) => ['key' => $key, ...$group])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, User>  $people
     * @param  list<array{user: User, capacity: ?HeadCapacity}>  $leaders
     * @param  Collection<string, int>  $grades  salary grade by normalized position title
     * @param  Collection<int, int>  $trailing  user ids listed last (keys)
     * @return array<string, mixed>
     */
    private function present(OrganizationalUnit $unit, Collection $people, array $leaders, ?int $headId, Collection $grades, Collection $trailing): array
    {
        // Leaders keep the order UnitLeadership gives (the head, then the rest).
        $rank = [];
        $roles = [];
        foreach ($leaders as $index => $leader) {
            $rank[$leader['user']->id] = $index;

            if ($leader['user']->id === $headId) {
                $roles[$leader['user']->id] = $leader['capacity']?->shortLabel() ?? 'Head';
            }
        }

        $employees = $people
            ->sortBy(fn (User $user) => [
                $trailing->has($user->id) ? 1 : 0,
                $rank[$user->id] ?? PHP_INT_MAX,
                -($grades[$this->normalize($user->position)] ?? 0),
                mb_strtolower((string) ($user->last_name ?: $user->name)),
            ])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'position' => $user->position,
                'profile_photo_url' => $user->profile_photo_url,
                'role' => $roles[$user->id] ?? null,
            ])
            ->values()
            ->all();

        return [
            'id' => $unit->id,
            'name' => $unit->name,
            'short_name' => $unit->short_name,
            'description' => $unit->description,
            'children_count' => $unit->children_count,
            'employee_count' => count($employees),
            'employees' => $employees,
        ];
    }

    private function normalize(?string $title): string
    {
        return mb_strtolower(trim((string) $title));
    }
}
