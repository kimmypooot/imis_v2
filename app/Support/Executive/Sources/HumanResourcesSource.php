<?php

namespace App\Support\Executive\Sources;

use App\Enums\PermissionName;
use App\Models\Employee;
use App\Models\PlantillaItem;

/**
 * Human Resources: who is in service, plantilla items filled and vacant,
 * and who reaches compulsory retirement (65, RA 8291) within a year.
 *
 * Starter figures, until management agrees which ones to track: each is
 * a total computed the same way as on the HR dashboard.
 */
class HumanResourcesSource implements MetricSource
{
    public function key(): string
    {
        return 'hr';
    }

    public function label(): string
    {
        return 'Human Resources';
    }

    public function kind(): string
    {
        return 'module';
    }

    public function collect(): array
    {
        $staff = Staff::inService();
        $items = PlantillaItem::query()->active()->with('incumbency')->get();
        $filled = $items->filter(fn (PlantillaItem $item) => $item->incumbency !== null)->count();
        $until = today()->addYear();
        $retiring = $staff->filter(function (Employee $employee) use ($until) {
            $sixtyFifth = $employee->user->birthday?->copy()->addYears(65);

            return $sixtyFifth && $sixtyFifth->gte(today()) && $sixtyFifth->lte($until);
        });

        return [
            [
                'key' => 'staff.in_service',
                'label' => 'Employees in service',
                'value' => $staff->count(),
                'unit' => 'count',
                'breakdown' => Staff::byOffice($staff, Staff::office(...)),
                'href' => '/admin/hr/employees',
                'permission' => PermissionName::EmployeesView->value,
            ],
            [
                'key' => 'plantilla.fill_rate',
                'label' => 'Plantilla items filled',
                'value' => $items->isEmpty() ? null : round($filled / $items->count() * 100, 1),
                'unit' => 'percent',
                'note' => "{$filled} of {$items->count()} items",
                'href' => '/admin/hr/staffing',
                'permission' => PermissionName::PlantillaView->value,
            ],
            [
                'key' => 'plantilla.vacant',
                'label' => 'Vacant plantilla items',
                'value' => $items->count() - $filled,
                'unit' => 'count',
                'href' => '/admin/hr/staffing',
                'permission' => PermissionName::PlantillaView->value,
            ],
            [
                'key' => 'staff.compulsory_retirement_12m',
                'label' => 'Reaching compulsory retirement within 12 months',
                'value' => $retiring->count(),
                'unit' => 'count',
                'breakdown' => Staff::byOffice($retiring, Staff::office(...)),
                'href' => '/admin/hr',
                'permission' => PermissionName::EmployeesView->value,
            ],
        ];
    }
}
