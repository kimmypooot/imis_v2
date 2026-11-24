<?php

namespace App\Support\HR\Dtr;

use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\OrganizationalUnit;
use App\Support\HR\OfficeHeads;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * DTR: a month of an employee's Daily Time Record as sheets for the page
 * and the printed CS Form No. 48, one sheet per office the employee served
 * in that month (split at reassignments, as the Regional Office's
 * per-division sheets are). Each carries the office, the work schedule in
 * force at its end, and the head who verifies it.
 *
 * Head of a sheet: the head-of-office designation (officer-in-charge or
 * caretaker) covering the sheet's last day; otherwise the office's
 * regular head as OfficeHeads knows it today; otherwise none.
 */
class DtrSheets
{
    public function __construct(
        private readonly DailyTimeRecord $record = new DailyTimeRecord,
        private readonly DtrPeriods $periods = new DtrPeriods,
        private readonly WorkSchedules $schedules = new WorkSchedules,
        private readonly DtrSignatures $signatures = new DtrSignatures,
    ) {}

    /**
     * The first day of the month in a `YYYY-MM` query value, or this month
     * when it is missing or not a month.
     */
    public static function parseMonth(mixed $value): Carbon
    {
        return is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)
            ? Carbon::createFromFormat('!Y-m', $value)
            : now()->startOfMonth();
    }

    /**
     * Validation rules for a printed sheet's `from` and `to`: dates in one
     * month, the end not before the start.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rangeRules(mixed $from): array
    {
        return [
            'from' => ['bail', 'required', 'string', 'date_format:Y-m-d'],
            'to' => [
                'bail', 'required', 'string', 'date_format:Y-m-d',
                function (string $attribute, mixed $value, Closure $fail) use ($from) {
                    // Both are Y-m-d strings here, so comparing them as text compares the dates.
                    if (! is_string($from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
                        return;
                    }
                    if ($value < $from) {
                        $fail('The end can\'t be before the start.');
                    } elseif (! str_starts_with($value, substr($from, 0, 7))) {
                        $fail('A sheet covers days in one month.');
                    }
                },
            ],
        ];
    }

    /**
     * @return array{employee: array{id: int, name: string, position: ?string}, month: string, label: string, sheets: list<array<string, mixed>>}
     */
    public function forMonth(Employee $employee, Carbon $month): array
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth()->startOfDay();

        return [
            ...$this->header($employee),
            'month' => $from->format('Y-m'),
            'label' => $from->format('F Y'),
            'sheets' => $this->periods->for($employee, $from, $to)
                ->map(fn (array $segment) => $this->sheet($employee, $segment['from'], $segment['to'], $segment['unit_id']))
                ->values()->all(),
        ];
    }

    /**
     * One sheet for exactly these days, in the office in force on the last.
     *
     * @return array<string, mixed>
     */
    public function forRange(Employee $employee, Carbon $from, Carbon $to): array
    {
        $segments = $this->periods->for($employee, $from, $to);
        if ($segments->count() > 1) {
            throw ValidationException::withMessages(['to' => 'This range crosses a reassignment: print each office\'s sheet separately.']);
        }
        $segment = $segments->last();

        return $this->sheet($employee, $from->copy()->startOfDay(), $to->copy()->startOfDay(), $segment['unit_id']);
    }

    /**
     * @return array{employee: array{id: int, name: string, position: ?string}}
     */
    public function header(Employee $employee): array
    {
        return ['employee' => ['id' => $employee->id, 'name' => $employee->user->name, 'position' => $employee->user->position]];
    }

    /**
     * @return array<string, mixed>
     */
    private function sheet(Employee $employee, Carbon $from, Carbon $to, ?int $unitId): array
    {
        $unit = $unitId ? OrganizationalUnit::query()->find($unitId) : null;
        $record = $this->record->build($employee, $from, $to);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'period' => self::periodLabel($from, $to),
            'unit' => $unit ? ($unit->short_name ?: $unit->name) : null,
            'schedule' => $this->schedules->on($employee, $to)?->name,
            'head' => $this->head($unitId, $to),
            'rows' => $record['days']->all(),
            'totals' => $record['totals'],
            'signature' => $this->signature($employee, $from, $to, $record),
        ];
    }

    /**
     * The employee's current signing of exactly this sheet, if any.
     *
     * @return array{code: string, signed_at: string, status: string, image_url: ?string, verify_url: string}|null
     */
    private function signature(Employee $employee, Carbon $from, Carbon $to, array $record): ?array
    {
        $signature = $this->signatures->current($employee, $from, $to);

        return $signature ? [
            'code' => $signature->code,
            'signed_at' => $signature->signed_at->toIso8601String(),
            'status' => $this->signatures->status($signature, $this->signatures->hashOf($employee, $from, $to, $record)),
            'image_url' => $signature->signature_path ? route('dtr.signatures.image', $signature) : null,
            // From the configured address, so a QR printed on an intranet address still opens outside it.
            'verify_url' => rtrim((string) config('app.url'), '/').route('dtr.verify', $signature->code, false),
        ] : null;
    }

    /**
     * "October 1-18", "October 5" or "October 25 - November 3".
     */
    public static function periodLabel(Carbon $from, Carbon $to): string
    {
        if ($from->isSameDay($to)) {
            return $from->format('F j');
        }

        return $from->isSameMonth($to)
            ? $from->format('F j').'-'.$to->format('j')
            : $from->format('F j').' - '.$to->format('F j');
    }

    private function head(?int $unitId, Carbon $date): ?string
    {
        if (! $unitId) {
            return null;
        }

        $designation = EmployeeAssignment::query()->headingOn($date)->where('to_unit_id', $unitId)
            ->with('employee.user')->orderByDesc('effective_date')->orderByDesc('id')->first();

        // Without a designation that day, the office's regular head: today's officer-in-charge doesn't apply to a past sheet.
        return ($designation?->employee?->user ?? (app(OfficeHeads::class)->regular()[$unitId]['user'] ?? null))?->name;
    }
}
