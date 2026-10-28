<?php

namespace App\Support\HR;

use App\Enums\HeadCapacity;
use App\Enums\UnitType;
use App\Models\User;

/**
 * Every current leader of a unit, for display (the public directory) —
 * not supervision. Starts from OfficeHeads' resolved head (so a field
 * office's OIC/Caretaker designation still applies the same way, capacity
 * included), then adds any other active account holding a title in
 * EXTRA_TITLES for that unit's type, with no capacity of its own. An
 * office having more than one leader (the ORD's Director IV and Director
 * III, both currently active) is normal here, unlike OfficeHeads, which
 * exists to pick exactly one approver for Leave.
 */
class UnitLeadership
{
    /** Titles shown alongside OfficeHeads' pick, by type of office. */
    public const EXTRA_TITLES = [
        UnitType::RegionalSupportUnit->value => ['director iii'],
    ];

    public function __construct(private readonly OfficeHeads $officeHeads) {}

    /**
     * @return array<int, list<array{user: User, capacity: ?HeadCapacity}>> keyed by organizational_unit_id
     */
    public function all(): array
    {
        $byUnit = [];

        foreach ($this->officeHeads->all() as $unitId => $head) {
            $byUnit[$unitId] = [$head];
        }

        $titles = collect(self::EXTRA_TITLES)->flatten()->unique()->values();

        if ($titles->isEmpty()) {
            return $byUnit;
        }

        User::query()->where('status', 'active')->with('organizationalUnit')
            ->whereNotNull('organizational_unit_id')
            ->whereRaw('LOWER(TRIM(position)) IN ('.$titles->map(fn () => '?')->implode(', ').')', $titles->all())
            ->get()
            ->each(function (User $user) use (&$byUnit) {
                $allowed = self::EXTRA_TITLES[$user->organizationalUnit?->unit_type?->value] ?? [];

                if (! in_array(mb_strtolower(trim((string) $user->position)), $allowed, true)) {
                    return;
                }

                $byUnit[$user->organizational_unit_id] ??= [];

                if (! collect($byUnit[$user->organizational_unit_id])->contains(fn (array $entry) => $entry['user']->id === $user->id)) {
                    $byUnit[$user->organizational_unit_id][] = ['user' => $user, 'capacity' => null];
                }
            });

        return $byUnit;
    }

    /**
     * @return list<array{user: User, capacity: ?HeadCapacity}>
     */
    public function at(int $unitId): array
    {
        return $this->all()[$unitId] ?? [];
    }
}
