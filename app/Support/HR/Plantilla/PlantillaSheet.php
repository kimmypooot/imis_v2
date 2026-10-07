<?php

namespace App\Support\HR\Plantilla;

use App\Enums\AppointmentNature;
use App\Enums\EmploymentStatus;
use App\Models\OrganizationalUnit;
use App\Models\PlantillaItem;
use App\Models\User;
use App\Support\Tables\CsvExport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Completes the plantilla from a spreadsheet: HR downloads every active
 * item with its current details (CSV, opens in Excel), fills in the
 * blanks — item numbers, grades, places of assignment, and the holder's
 * step, status, nature and start date — and uploads it back. Each row is
 * checked and shown before anything is saved.
 *
 * A blank cell never clears a value; it means "leave as is". The holder's
 * details go through Plantilla::correct, so the holder's status and
 * position follow as when HR corrects one by one. Your own appointment is
 * never changed here (separation of duties).
 */
class PlantillaSheet
{
    /** CSV column => [field, label]. The first is the key and is never changed. */
    public const COLUMNS = [
        ['item_id', 'Item ID (do not change)'],
        ['holder', 'Holder (for reference)'],
        ['position_title', 'Position title (change on the Positions page)'],
        ['item_number', 'Item number'],
        ['salary_grade', 'Salary grade'],
        ['place_of_assignment', 'Place of assignment'],
        ['step', 'Step'],
        ['status', 'Status'],
        ['nature', 'Nature of appointment'],
        ['date_from', 'Start date (YYYY-MM-DD)'],
    ];

    private const ITEM_FIELDS = ['item_number', 'salary_grade', 'organizational_unit_id'];

    private const HOLDER_FIELDS = ['step', 'status', 'nature', 'date_from'];

    private const LABELS = [
        'item_number' => 'Item number',
        'salary_grade' => 'Salary grade',
        'organizational_unit_id' => 'Place of assignment',
        'step' => 'Step',
        'status' => 'Status',
        'nature' => 'Nature',
        'date_from' => 'Start date',
    ];

    public function __construct(private readonly Plantilla $plantilla) {}

    /**
     * The template: every active item with what's on record now.
     */
    public function template(): StreamedResponse
    {
        $query = PlantillaItem::query()->active()
            ->with(['organizationalUnit', 'position', 'incumbency.employee.user'])
            ->orderBy('organizational_unit_id')->orderBy('position_title')->orderBy('id');

        return CsvExport::stream('imis-plantilla-to-complete', array_column(self::COLUMNS, 1), $query, function (PlantillaItem $item) {
            $holder = $item->incumbency;

            return [
                $item->id,
                $holder?->employee->user->name ?? '(vacant)',
                $item->position_title,
                $item->item_number,
                $item->salary_grade,
                $item->organizationalUnit?->name,
                $holder?->step,
                $holder?->status?->label(),
                $holder?->nature?->label(),
                $holder?->date_from?->toDateString(),
            ];
        });
    }

    /**
     * Reads an uploaded CSV into rows of cells keyed by field, with the
     * spreadsheet line number. Returns null if it isn't this template.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function read(string $path): ?array
    {
        $handle = fopen($path, 'r');
        // Skip Excel's UTF-8 byte order mark before parsing, or a quoted first heading isn't read as quoted.
        if (fread($handle, 3) !== "\xEF\xBB\xBF") {
            rewind($handle);
        }
        $header = fgetcsv($handle, escape: '');

        if (! $header || $this->clean((string) $header[0]) !== self::COLUMNS[0][1]) {
            fclose($handle);

            return null;
        }

        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($handle, escape: '')) !== false) {
            $line++;
            if (array_filter($cells, fn ($cell) => trim((string) $cell) !== '') === []) {
                continue;
            }

            $row = ['line' => $line];
            foreach (self::COLUMNS as $index => [$field]) {
                $row[$field] = $this->clean($cells[$index] ?? '');
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    /**
     * Checks each row against what's on record: what would change, and
     * what's wrong. Only rows with changes and no errors can be applied.
     *
     * @param  array<int, array<string, mixed>>  $rows  from read()
     * @return Collection<int, array<string, mixed>>
     */
    public function check(array $rows, User $actor): Collection
    {
        $items = PlantillaItem::query()->with(['organizationalUnit', 'position', 'incumbency.employee.user'])
            ->whereIn('id', collect($rows)->pluck('item_id')->filter(fn ($id) => ctype_digit((string) $id)))
            ->get()->keyBy('id');
        $offices = OrganizationalUnit::query()->get();
        $taken = PlantillaItem::query()->whereNotNull('item_number')->pluck('id', 'item_number')
            ->mapWithKeys(fn ($id, $number) => [mb_strtolower($number) => $id]);
        $numbersInSheet = collect($rows)->pluck('item_number')->filter()->map(fn ($number) => mb_strtolower($number))->countBy();
        $seenItems = [];

        return collect($rows)->map(function (array $row) use ($items, $offices, $taken, $numbersInSheet, $actor, &$seenItems) {
            $errors = [];
            $item = ctype_digit((string) $row['item_id']) ? $items->get((int) $row['item_id']) : null;

            if (! $item || $item->isAbolished()) {
                return $this->result($row, null, [], ['No active item with this Item ID. Download a fresh template.']);
            }
            if (isset($seenItems[$item->id])) {
                return $this->result($row, $item, [], ["Item ID {$item->id} is also on line {$seenItems[$item->id]}."]);
            }
            $seenItems[$item->id] = $row['line'];

            $wanted = [];

            if ($row['item_number'] !== '') {
                $wanted['item_number'] = $row['item_number'];
                $key = mb_strtolower($row['item_number']);
                if (mb_strlen($row['item_number']) > 100) {
                    $errors[] = 'Item number is too long.';
                } elseif (($taken[$key] ?? $item->id) !== $item->id) {
                    $errors[] = "Item number {$row['item_number']} is already another item's.";
                } elseif ($numbersInSheet[$key] > 1) {
                    $errors[] = "Item number {$row['item_number']} appears on more than one line.";
                }
            }

            if ($row['salary_grade'] !== '') {
                $grade = filter_var($row['salary_grade'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => SalarySchedules::GRADES]]);
                if ($grade === false) {
                    $errors[] = 'Salary grade must be a whole number from 1 to '.SalarySchedules::GRADES.'.';
                } elseif ($item->position?->salary_grade !== null && $grade !== $item->position->salary_grade) {
                    $errors[] = "The grade comes from the Positions list ({$item->position->title}: SG {$item->position->salary_grade}). Change it there.";
                } else {
                    $wanted['salary_grade'] = $grade;
                }
            }

            if ($row['place_of_assignment'] !== '') {
                $name = mb_strtolower($row['place_of_assignment']);
                $office = $offices->first(fn (OrganizationalUnit $unit) => mb_strtolower($unit->name) === $name || mb_strtolower((string) $unit->short_name) === $name);
                $office
                    ? $wanted['organizational_unit_id'] = $office->id
                    : $errors[] = "No office called \"{$row['place_of_assignment']}\".";
            }

            $holder = $item->incumbency;
            $holderCells = array_filter(array_intersect_key($row, array_flip(self::HOLDER_FIELDS)), fn ($cell) => $cell !== '');

            if ($holderCells !== [] && ! $holder) {
                $errors[] = 'The item is vacant: appoint someone on the item page to give step, status, nature and start date.';
            } elseif ($holderCells !== [] && $holder->employee->user_id === $actor->id) {
                $errors[] = 'This is your own appointment; another HR officer has to complete it.';
            } elseif ($holderCells !== []) {
                if (isset($holderCells['step'])) {
                    $step = filter_var($holderCells['step'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => SalarySchedules::STEPS]]);
                    $step === false ? $errors[] = 'Step must be a whole number from 1 to '.SalarySchedules::STEPS.'.' : $wanted['step'] = $step;
                }
                if (isset($holderCells['status'])) {
                    $status = $this->matchEnum($holderCells['status'], EmploymentStatus::onPlantilla());
                    $status ? $wanted['status'] = $status->value : $errors[] = 'Status must be one of: '.$this->labels(EmploymentStatus::onPlantilla()).'.';
                }
                if (isset($holderCells['nature'])) {
                    $natures = array_values(array_filter(AppointmentNature::cases(), fn ($case) => $case !== AppointmentNature::StepIncrement));
                    $nature = $this->matchEnum($holderCells['nature'], $natures);
                    $nature ? $wanted['nature'] = $nature->value : $errors[] = 'Nature must be one of: '.$this->labels($natures).'.';
                }
                if (isset($holderCells['date_from'])) {
                    $date = $this->date($holderCells['date_from']);
                    if (! $date) {
                        $errors[] = 'Start date must be a date like 2019-03-01.';
                    } elseif ($date->isFuture() || $date->year < 1950) {
                        $errors[] = 'Start date must be between 1950 and today.';
                    } else {
                        $wanted['date_from'] = $date->toDateString();
                    }
                }
            }

            // Only what differs from the record is a change.
            $current = [
                'item_number' => $item->item_number,
                'salary_grade' => $item->salary_grade,
                'organizational_unit_id' => $item->organizational_unit_id,
                'step' => $holder?->step,
                'status' => $holder?->status?->value,
                'nature' => $holder?->nature?->value,
                'date_from' => $holder?->date_from?->toDateString(),
            ];
            $changes = collect($wanted)->filter(fn ($value, $field) => $value !== $current[$field])->all();

            return $this->result($row, $item, $changes, $errors, $current, $offices);
        });
    }

    /**
     * Applies the rows that check() found valid. Returns the items changed.
     *
     * @param  Collection<int, array<string, mixed>>  $checked
     * @return array<int, int> item ids
     */
    public function apply(Collection $checked): array
    {
        $ready = $checked->filter(fn (array $row) => $row['ready']);

        DB::transaction(function () use ($ready) {
            foreach ($ready as $row) {
                $item = PlantillaItem::query()->with('incumbency')->findOrFail($row['item_id']);

                $itemData = array_intersect_key($row['apply'], array_flip(self::ITEM_FIELDS));
                if ($itemData !== []) {
                    $this->plantilla->updateItem($item, $itemData);
                }

                $holderData = array_intersect_key($row['apply'], array_flip(self::HOLDER_FIELDS));
                if ($holderData !== [] && $item->incumbency) {
                    $this->plantilla->correct($item->incumbency, $holderData);
                }
            }
        });

        return $ready->pluck('item_id')->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $changes
     * @param  array<int, string>  $errors
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private function result(array $row, ?PlantillaItem $item, array $changes, array $errors, array $current = [], ?Collection $offices = null): array
    {
        $show = fn (string $field, mixed $value) => match (true) {
            $value === null => '—',
            $field === 'organizational_unit_id' => $offices?->firstWhere('id', $value)?->name ?? '—',
            $field === 'status' => EmploymentStatus::from($value)->label(),
            $field === 'nature' => AppointmentNature::from($value)->label(),
            default => (string) $value,
        };

        return [
            'line' => $row['line'],
            'item_id' => $item?->id,
            'item' => $item ? ($item->item_number ?: $item->position_title) : ($row['item_id'] ?: '—'),
            'position_title' => $item?->position_title,
            'holder' => $item?->incumbency?->employee->user->name,
            'changes' => collect($changes)->map(fn ($value, $field) => [
                'field' => self::LABELS[$field],
                'from' => $show($field, $current[$field] ?? null),
                'to' => $show($field, $value),
            ])->values()->all(),
            'errors' => $errors,
            'ready' => $item !== null && $errors === [] && $changes !== [],
            'apply' => $errors === [] ? $changes : [],
        ];
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  array<int, T>  $cases
     * @return T|null
     */
    private function matchEnum(string $cell, array $cases): mixed
    {
        $cell = mb_strtolower($cell);

        foreach ($cases as $case) {
            if ($cell === mb_strtolower($case->value) || $cell === mb_strtolower($case->label())) {
                return $case;
            }
        }

        return null;
    }

    /**
     * @param  array<int, EmploymentStatus|AppointmentNature>  $cases
     */
    private function labels(array $cases): string
    {
        return implode(', ', array_map(fn ($case) => $case->label(), $cases));
    }

    /** Accepts 2019-03-01, and 3/1/2019 as Excel shows dates here. */
    private function date(string $cell): ?Carbon
    {
        foreach (['!Y-m-d', '!n/j/Y', '!m/d/Y'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $cell);
                if ($date && $date->format(ltrim($format, '!')) === $cell) {
                    return $date;
                }
            } catch (Throwable) {
                // Try the next format.
            }
        }

        return null;
    }

    /** Trims a cell and undoes CsvExport's formula guard ('=…). */
    private function clean(string $cell): string
    {
        $cell = trim($cell);

        return preg_match("/^'[=+\-@]/", $cell) ? substr($cell, 1) : $cell;
    }
}
