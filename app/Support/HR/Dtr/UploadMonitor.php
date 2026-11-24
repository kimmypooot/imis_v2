<?php

namespace App\Support\HR\Dtr;

use App\Enums\UnitType;
use App\Models\BiometricPunch;
use App\Models\Employee;
use App\Models\OrganizationalUnit;
use Illuminate\Support\Carbon;

/**
 * DTR: which offices' biometric logs have reached the system for a month.
 * The Regional Support Units count as one (the Regional Office, whose logs
 * HRD generates itself); every field and satellite office stands alone. A
 * punch belongs to an office through the employee carrying its biometric
 * ID, so this works from the consolidated file HRD uploads.
 */
class UploadMonitor
{
    public const REGIONAL = 'regional';

    /**
     * @return list<array{key: string, office: string, employees: int, with_id: int, with_punches: int, last_punch: ?string, people: list<array{name: string, reason: string}>, status: string}>
     */
    public function forMonth(Carbon $month): array
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();

        $punched = BiometricPunch::query()
            ->whereNull('voided_at')
            ->whereBetween('punched_at', [$from, $to])
            ->groupBy('biometric_id')
            ->selectRaw('biometric_id, max(punched_at) as last_at')
            ->pluck('last_at', 'biometric_id');

        $units = OrganizationalUnit::query()->active()->workingOffices()->get(['id', 'unit_type', 'name', 'short_name'])->keyBy('id');

        $groups = [self::REGIONAL => ['office' => 'Regional Office', 'sort' => '', 'employees' => 0, 'with_id' => 0, 'with_punches' => 0, 'last' => null, 'people' => []]];
        foreach ($units as $unit) {
            if ($unit->unit_type !== UnitType::RegionalSupportUnit) {
                $groups[(string) $unit->id] = ['office' => $unit->name, 'sort' => $unit->name, 'employees' => 0, 'with_id' => 0, 'with_punches' => 0, 'last' => null, 'people' => []];
            }
        }

        Employee::query()->with(['user:id,name,organizational_unit_id', 'assignments' => fn ($query) => $query->reassignments()->orderBy('effective_date')->orderBy('id')])
            ->whereNull('separation_date')
            ->get(['id', 'user_id', 'biometric_id'])
            ->each(function (Employee $employee) use (&$groups, $units, $punched, $from, $to) {
                $last = $employee->biometric_id !== null && $employee->biometric_id !== '' ? $punched->get($employee->biometric_id) : null;

                // A transferee counts where they worked that month, so each office is judged on its own staff.
                foreach ($this->unitsDuring($employee, $from, $to) as $unitId) {
                    $unit = $units->get($unitId);
                    $key = $unit && $unit->unit_type !== UnitType::RegionalSupportUnit ? (string) $unit->id : self::REGIONAL;

                    $groups[$key]['employees']++;
                    $hasId = $employee->biometric_id !== null && $employee->biometric_id !== '';
                    if ($hasId) {
                        $groups[$key]['with_id']++;
                    }
                    if ($last === null) {
                        $groups[$key]['people'][] = ['name' => $employee->user?->name ?? '—', 'reason' => $hasId ? 'No punches' : 'No Biometric ID'];
                    }
                    if ($last !== null) {
                        $groups[$key]['with_punches']++;
                        $groups[$key]['last'] = max($groups[$key]['last'] ?? '', (string) $last);
                    }
                }
            });

        $rows = collect($groups)->map(fn (array $group, string $key) => [
            'key' => $key,
            'office' => $group['office'],
            'employees' => $group['employees'],
            'with_id' => $group['with_id'],
            'with_punches' => $group['with_punches'],
            'last_punch' => $group['last'] ? substr($group['last'], 0, 10) : null,
            'people' => collect($group['people'])->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
            'status' => match (true) {
                $group['with_punches'] === 0 => 'missing',
                $group['with_punches'] < $group['with_id'] => 'partial',
                default => 'received',
            },
            'sort' => $key === self::REGIONAL ? '' : $group['sort'],
        ])->sortBy('sort')->map(fn (array $row) => collect($row)->except('sort')->all())->values()->all();

        return $rows;
    }

    /**
     * The offices someone belonged to during the period, from their
     * reassignments: where they were on the first day, then any office they
     * were moved to within the period. A detail or designation leaves them
     * in their mother unit.
     *
     * @return list<int|null>
     */
    private function unitsDuring(Employee $employee, Carbon $from, Carbon $to): array
    {
        $moves = $employee->assignments;

        if ($moves->isEmpty()) {
            return [$employee->user?->organizational_unit_id];
        }

        $before = $moves->filter(fn ($move) => $move->effective_date->lte($from))->last();
        $start = $before ? $before->to_unit_id : $moves->first()->from_unit_id;
        $within = $moves->filter(fn ($move) => $move->effective_date->gt($from) && $move->effective_date->lte($to))->pluck('to_unit_id');

        return collect([$start])->merge($within)->unique()->values()->all();
    }
}
