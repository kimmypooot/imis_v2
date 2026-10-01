<?php

namespace App\Support\Portal;

use App\Enums\UnitType;
use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use App\Models\Committee;
use App\Models\OrganizationalUnit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The dashboard's division directory — the legacy IMIS "Category →
 * Division → System" drill-down, built from data this platform already
 * has:
 *
 * - Categories are unit types (Regional Support Units, Field Offices, …)
 *   followed by Committees, then "Other" for systems with no office.
 * - A division is an active organizational unit or committee that has at
 *   least one system the viewer can see. A system appears under the unit
 *   that manages it, plus anywhere it's also listed (application_listings).
 *
 * Only systems visibleTo() the viewer are considered, and each is shaped
 * by ApplicationResource, so launch URLs, roles, and maintenance state
 * follow exactly the same rules as everywhere else. Systems are sent once
 * (keyed by id) and divisions reference them, so a system listed in three
 * places isn't sent three times.
 */
class SystemDirectory
{
    public const OTHER_KEY = 'other';

    public function __construct(private readonly ?User $user, private readonly Request $request) {}

    /**
     * @return array{categories: array<int, array<string, mixed>>, systems: array<int|string, array<string, mixed>>}
     */
    public function build(): array
    {
        $applications = Application::query()
            ->visibleTo($this->user)
            ->withAccessFor($this->user)
            ->with([
                'organizationalUnit' => fn ($query) => $query->active(),
                'listedUnits' => fn ($query) => $query->active(),
                'listedCommittees' => fn ($query) => $query->active(),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $units = collect();
        $committees = collect();
        $placements = [];

        foreach ($applications as $application) {
            $targets = collect([$application->organizationalUnit])
                ->merge($application->listedUnits)
                ->filter()
                ->each(fn (OrganizationalUnit $unit) => $units->put($unit->id, $unit))
                ->map(fn (OrganizationalUnit $unit) => "unit-{$unit->id}");

            $targets = $targets->merge(
                $application->listedCommittees
                    ->each(fn (Committee $committee) => $committees->put($committee->id, $committee))
                    ->map(fn (Committee $committee) => "committee-{$committee->id}"),
            );

            foreach ($targets->whenEmpty(fn ($targets) => $targets->push(self::OTHER_KEY))->unique() as $key) {
                $placements[$key][] = $application->id;
            }
        }

        $systems = ApplicationResource::collection($applications)->resolve($this->request);

        return [
            'categories' => $this->categories($units, $committees, $placements),
            'systems' => array_column($systems, null, 'id'),
        ];
    }

    /**
     * @param  Collection<int, OrganizationalUnit>  $units
     * @param  Collection<int, Committee>  $committees
     * @param  array<string, array<int, int>>  $placements
     * @return array<int, array<string, mixed>>
     */
    private function categories(Collection $units, Collection $committees, array $placements): array
    {
        $categories = [];

        foreach (UnitType::cases() as $type) {
            $divisions = $units
                ->filter(fn (OrganizationalUnit $unit) => $unit->unit_type === $type)
                ->sortBy([['sort_order', 'asc'], ['name', 'asc']])
                ->map(fn (OrganizationalUnit $unit) => $this->division(
                    "unit-{$unit->id}", 'unit', $unit->name, $unit->short_name, $unit->description, $placements,
                ));

            if ($divisions->isNotEmpty()) {
                $categories[] = ['key' => $type->value, 'label' => $type->pluralLabel(), 'type' => 'unit', 'divisions' => $divisions->values()];
            }
        }

        $committeeDivisions = $committees
            ->sortBy('name')
            ->map(fn (Committee $committee) => $this->division(
                "committee-{$committee->id}", 'committee', $committee->name, $committee->short_name, $committee->description, $placements,
            ));

        if ($committeeDivisions->isNotEmpty()) {
            $categories[] = ['key' => 'committees', 'label' => 'Committees', 'type' => 'committee', 'divisions' => $committeeDivisions->values()];
        }

        if (isset($placements[self::OTHER_KEY])) {
            $categories[] = [
                'key' => self::OTHER_KEY,
                'label' => 'Other',
                'type' => 'other',
                'divisions' => [$this->division(
                    self::OTHER_KEY, 'other', 'Other Systems', null, 'Systems not yet assigned to an office.', $placements,
                )],
            ];
        }

        return $categories;
    }

    /**
     * @param  array<string, array<int, int>>  $placements
     * @return array<string, mixed>
     */
    private function division(string $key, string $type, string $name, ?string $shortName, ?string $description, array $placements): array
    {
        return [
            'key' => $key,
            'type' => $type,
            'name' => $name,
            'short_name' => $shortName,
            'description' => $description,
            'system_ids' => $placements[$key] ?? [],
        ];
    }
}
