<?php

namespace App\Support\Executive\Sources;

use App\Models\Employee;
use Illuminate\Support\Collection;

/**
 * Who is in service — an employee record that isn't separated, on an
 * active account (as on the HR dashboard and Staffing page) — and how a
 * count splits by office, for the IMIS figures' breakdowns.
 */
final class Staff
{
    /**
     * @return Collection<int, Employee>
     */
    public static function inService(): Collection
    {
        return Employee::query()->with('user.organizationalUnit')->get()
            ->reject(fn (Employee $employee) => $employee->isSeparated() || ! $employee->user->isActive())
            ->values();
    }

    public static function office(Employee $employee): string
    {
        $unit = $employee->user->organizationalUnit;

        return $unit ? ($unit->short_name ?: $unit->name) : 'No office set';
    }

    /**
     * @param  Collection<int, mixed>  $items
     * @return array<int, array{label: string, value: int}>
     */
    public static function byOffice(Collection $items, callable $office): array
    {
        return $items->countBy($office)->sortDesc()->take(50)
            ->map(fn (int $count, string $label) => ['label' => $label, 'value' => $count])
            ->values()->all();
    }
}
