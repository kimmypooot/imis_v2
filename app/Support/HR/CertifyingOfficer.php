<?php

namespace App\Support\HR;

use App\Models\PlantillaItem;
use App\Support\Settings\Settings;

/**
 * Who certifies printed Service Records (System Settings → Human
 * Resources). HR picks the position; the name is whoever holds that
 * position's plantilla item now, unless HR typed a name to print instead
 * (e.g. while the item is vacant or someone else signs).
 */
class CertifyingOfficer
{
    /**
     * The positions that may certify, as printed, with the plantilla item
     * each one is: its title and, where several offices have one, the
     * office's code.
     *
     * @var array<string, array{title: string, office?: string}>
     */
    public const POSITIONS = [
        'Chief Human Resource Specialist, Human Resource Division' => ['title' => 'Chief Human Resource Specialist', 'office' => 'HRD'],
        'Director IV' => ['title' => 'Director IV'],
        'Director III' => ['title' => 'Director III'],
    ];

    /**
     * @return array{name: ?string, position: ?string}
     */
    public function current(): array
    {
        $position = Settings::get('hr_certifying_officer_position');

        return [
            'name' => Settings::get('hr_certifying_officer_name') ?? ($position ? $this->holderOf($position) : null),
            'position' => $position,
        ];
    }

    /**
     * Each position's current holder, for the settings form.
     *
     * @return array<string, ?string>
     */
    public function holders(): array
    {
        return collect(array_keys(self::POSITIONS))->mapWithKeys(fn (string $position) => [$position => $this->holderOf($position)])->all();
    }

    /**
     * The name of whoever holds the position's item now, or null when it's
     * vacant or more than one item matches (HR then types the name).
     */
    public function holderOf(string $position): ?string
    {
        $item = self::POSITIONS[$position] ?? null;
        if ($item === null) {
            return null;
        }

        $holders = PlantillaItem::query()->active()
            ->whereRaw('LOWER(TRIM(position_title)) = ?', [strtolower($item['title'])])
            ->when($item['office'] ?? null, fn ($query, string $office) => $query->whereHas('organizationalUnit', fn ($query) => $query->where('code', $office)))
            ->whereHas('incumbency')
            ->with('incumbency.employee.user')
            ->get();

        return $holders->count() === 1 ? $holders->first()->incumbency->employee?->user?->name : null;
    }
}
