<?php

namespace App\Support\HR\Dtr;

use App\Enums\UnitType;
use App\Models\BiometricPunch;
use App\Models\Employee;
use App\Models\OrganizationalUnit;
use Illuminate\Support\Carbon;

/**
 * DTR: which offices' biometric logs have reached the system for a month
 * (Contract of Service personnel aren't counted: they file no DTR).
 * The Regional Support Units count as one (the Regional Office, whose logs
 * HRD generates itself); every field and satellite office stands alone. A
 * punch belongs to an office through the employee carrying its biometric
 * ID, so this works from the consolidated file HRD uploads.
 */
class UploadMonitor
{
    public const REGIONAL = 'regional';

    /**
     * Each office lists all its staff for the month (`staff`): missing ones
     * first (no Biometric ID, then no punches), each with the days they
     * punched and their last punch, so HRD sees exactly who is missing.
     *
     * Each person counts once, in the office they ended the month in; one who
     * moved during the month carries the office(s) they came from
     * (`moved_from`).
     *
     * @return list<array{key: string, office: string, employees: int, with_id: int, with_punches: int, last_punch: ?string, staff: list<array{employee_id: int, name: string, photo_url: ?string, position: ?string, biometric_id: ?string, days: int, last_punch: ?string, status: string, moved_from: list<string>}>, status: string}>
     */
    public function forMonth(Carbon $month): array
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();

        $punched = BiometricPunch::query()
            ->whereNull('voided_at')
            ->whereBetween('punched_at', [$from, $to])
            ->groupBy('biometric_id')
            ->selectRaw('biometric_id, max(punched_at) as last_at, count(distinct date(punched_at)) as days')
            ->get()
            ->keyBy('biometric_id');

        $units = OrganizationalUnit::query()->active()->workingOffices()->get(['id', 'unit_type', 'name', 'short_name'])->keyBy('id');

        $groups = [self::REGIONAL => ['office' => 'Regional Office', 'sort' => '', 'employees' => 0, 'with_id' => 0, 'with_punches' => 0, 'last' => null, 'staff' => []]];
        foreach ($units as $unit) {
            if ($unit->unit_type !== UnitType::RegionalSupportUnit) {
                $groups[(string) $unit->id] = ['office' => $unit->name, 'sort' => $unit->name, 'employees' => 0, 'with_id' => 0, 'with_punches' => 0, 'last' => null, 'staff' => []];
            }
        }

        Employee::query()->with(['user:id,name,position,organizational_unit_id,profile_photo_path', 'assignments' => fn ($query) => $query->reassignments()->orderBy('effective_date')->orderBy('id')])
            ->whereNull('separation_date')
            ->keepsDtr()
            ->get(['id', 'user_id', 'biometric_id'])
            ->each(function (Employee $employee) use (&$groups, $units, $punched, $from, $to) {
                $hasId = $employee->biometric_id !== null && $employee->biometric_id !== '';
                $punches = $hasId ? $punched->get($employee->biometric_id) : null;
                $last = $punches?->last_at;
                $person = [
                    'employee_id' => $employee->id,
                    'name' => $employee->user?->name ?? '—',
                    'photo_url' => $employee->user?->profile_photo_url,
                    'position' => $employee->user?->position,
                    'biometric_id' => $hasId ? (string) $employee->biometric_id : null,
                    'days' => (int) ($punches?->days ?? 0),
                    'last_punch' => $last ? substr((string) $last, 0, 10) : null,
                    'status' => ! $hasId ? 'no_id' : ($last === null ? 'no_punches' : 'in'),
                ];

                // Each person counts once, under the office they ended the period in, so the offices
                // add up to the headcount; a move within the month is noted on their row instead.
                $keyOf = function ($unitId) use ($units): string {
                    $unit = $units->get($unitId);

                    return $unit && $unit->unit_type !== UnitType::RegionalSupportUnit ? (string) $unit->id : self::REGIONAL;
                };
                $key = $keyOf($this->unitAtEnd($employee, $to));
                $person['moved_from'] = collect($this->unitsDuring($employee, $from, $to))->map($keyOf)->unique()
                    ->reject(fn (string $other) => $other === $key)
                    ->map(fn (string $other) => $groups[$other]['office'] ?? null)->filter()->values()->all();

                $groups[$key]['employees']++;
                $groups[$key]['staff'][] = $person;
                if ($hasId) {
                    $groups[$key]['with_id']++;
                }
                if ($last !== null) {
                    $groups[$key]['with_punches']++;
                    $groups[$key]['last'] = max($groups[$key]['last'] ?? '', (string) $last);
                }
            });

        $rows = collect($groups)->map(fn (array $group, string $key) => [
            'key' => $key,
            'office' => $group['office'],
            'employees' => $group['employees'],
            'with_id' => $group['with_id'],
            'with_punches' => $group['with_punches'],
            'last_punch' => $group['last'] ? substr($group['last'], 0, 10) : null,
            // Missing first (no ID, then no punches), then by name.
            'staff' => collect($group['staff'])->sortBy([
                fn (array $a, array $b) => ['no_id' => 0, 'no_punches' => 1, 'in' => 2][$a['status']] <=> ['no_id' => 0, 'no_punches' => 1, 'in' => 2][$b['status']],
                fn (array $a, array $b) => strnatcasecmp($a['name'], $b['name']),
            ])->values()->all(),
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
     * The office someone was in on the last day of the period, from their
     * reassignments (their account's office when they have none).
     */
    private function unitAtEnd(Employee $employee, Carbon $to): ?int
    {
        $moves = $employee->assignments;

        if ($moves->isEmpty()) {
            return $employee->user?->organizational_unit_id;
        }

        $latest = $moves->filter(fn ($move) => $move->effective_date->lte($to))->last();

        return $latest ? $latest->to_unit_id : $moves->first()->from_unit_id;
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
