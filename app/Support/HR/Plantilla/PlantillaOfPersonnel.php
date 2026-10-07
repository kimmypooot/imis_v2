<?php

namespace App\Support\HR\Plantilla;

use App\Enums\AppointmentNature;
use App\Enums\EmploymentStatus;
use App\Models\Employee;
use App\Models\OrganizationalUnit;
use App\Models\PlantillaItem;
use App\Models\Position;
use App\Models\User;
use App\Support\Tables\XlsxReader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Updates IMIS from the office's certified "Plantilla of Personnel"
 * workbook (the HR Division's own Excel format, one row per item under
 * office headings): item numbers and places of assignment, each holder's
 * step, status, nature and start date, the original appointment date on
 * the employee record, and the holder's sex, birthday and middle name.
 *
 * What it deliberately leaves alone:
 * - Position titles. IMIS keeps its full titles ("Senior Human Resource
 *   Specialist"); the sheet's DBM forms ("SENIOR PS (SENIOR HRS)") are
 *   only used to recognise the position. A row whose position isn't the
 *   one its holder holds in IMIS (a promotion not yet recorded) gets no
 *   plantilla changes, only a warning.
 * - Salary grades. They come from the Positions list; a different grade
 *   on the sheet is reported for HR to correct there.
 * - TIN and eligibility. They belong to the employee's own PDS.
 *
 * The sheet doesn't give a nature of appointment, so it's inferred: a
 * last-promotion date means a Promotion starting then (on the assumption
 * date when the cell gives one, "6/22/2026 (A: 7/15/2026)"); none ("-")
 * means the Original appointment. Each inference is shown before saving.
 *
 * As with PlantillaSheet, a blank cell never clears a value, and nothing
 * is saved until apply() is given the checked rows.
 */
class PlantillaOfPersonnel
{
    /**
     * Office headings on the sheet => office short name. Checked in order
     * against the heading text; null leaves the place of assignment as is
     * (HR hasn't decided which Leyte office "FO No. 6 Leyte" is).
     */
    private const OFFICES = [
        'OFFICE OF THE DIRECTOR' => 'ORD',
        '(MSD)' => 'MSD',
        '(PALD)' => 'PALD',
        '(ESD)' => 'ESD',
        '(HRD)' => 'HRD',
        '(PSED)' => 'PSED',
        '(LSD)' => 'LSD',
        'BILIRAN' => 'BFO',
        'WESTERN SAMAR' => 'SFO',
        'SOUTHERN LEYTE' => 'SLFO',
        'NORTHERN SAMAR' => 'NSFO',
        'EASTERN SAMAR' => 'ESFO',
        'FO NO. 6 LEYTE' => null,
    ];

    /** The sheet's status codes. */
    private const STATUSES = [
        'P' => EmploymentStatus::Permanent,
        'T' => EmploymentStatus::Temporary,
        'C' => EmploymentStatus::Coterminous,
        'CT' => EmploymentStatus::Coterminous,
    ];

    /** Words the sheet abbreviates in position titles, and what they stand for. */
    private const TITLE_WORDS = [
        'admin' => 'administrative',
        'asst' => 'assistant',
        'sp' => 'special',
        'ps' => 'human resource specialist',
        'hrs' => 'human resource specialist',
        'hr' => 'human resource',
    ];

    /** Honorifics some accounts carry in the first name ("Atty. Marilyn"). */
    private const HONORIFICS = ['atty', 'dr', 'engr', 'hon', 'dir'];

    private const LABELS = [
        'item_number' => 'Item number',
        'organizational_unit_id' => 'Place of assignment',
        'step' => 'Step',
        'status' => 'Status',
        'nature' => 'Nature',
        'date_from' => 'Start date',
        'original_appointment_date' => 'Original appointment',
        'sex' => 'Sex',
        'birthday' => 'Birthday',
        'middle_name' => 'Middle name',
    ];

    public function __construct(
        private readonly Plantilla $plantilla,
        private readonly XlsxReader $reader,
    ) {}

    /**
     * The item rows of the workbook's plantilla sheet (the first sheet
     * with an "ITEM NO." heading), each with the office heading it's under.
     *
     * @return array<int, array<string, mixed>>
     */
    public function read(string $path): array
    {
        foreach ($this->reader->read($path) as $sheet) {
            $header = collect($sheet)->search(fn (array $cells) => mb_strtoupper($cells['A'] ?? '') === 'ITEM NO.');
            if ($header === false) {
                continue;
            }

            $rows = [];
            $section = null;
            foreach ($sheet as $line => $cells) {
                if ($line <= $header || ! isset($cells['B'])) {
                    continue;
                }
                // An office heading: "29.0001 MANAGEMENT SERVICES DIVISION (MSD)".
                if (! isset($cells['A']) && preg_match('/^\d+(\.\d+)?\s/', $cells['B'])) {
                    $section = $cells['B'];

                    continue;
                }
                // An item always has something in the incumbent column (a name,
                // VACANT, or a note); the summary tables below the sheet don't.
                if (isset($cells['A'], $cells['H'])) {
                    $rows[] = ['line' => $line, 'section' => $section, ...$this->cells($cells)];
                }
            }

            return $rows;
        }

        throw new RuntimeException('No sheet in this workbook has an "ITEM NO." heading.');
    }

    /**
     * Matches each row to IMIS and works out what would change. Rows with
     * errors change nothing; warnings explain what's left as is.
     *
     * @param  array<int, array<string, mixed>>  $rows  from read()
     * @return Collection<int, array<string, mixed>>
     */
    public function check(array $rows): Collection
    {
        $users = User::query()->with(['employee'])->get();
        $offices = OrganizationalUnit::query()->get();
        $positions = Position::query()->get();
        $items = PlantillaItem::query()->with(['position', 'organizationalUnit', 'incumbency'])->get();
        $numbers = collect($rows)->map(fn (array $row) => $this->itemNumber($row['item_number']))->filter()->map(fn ($n) => mb_strtolower($n))->countBy();
        $matched = [];

        return collect($rows)->map(function (array $row) use ($users, $offices, $positions, $items, $numbers, &$matched) {
            $result = $this->blank($row);
            $number = $this->itemNumber($row['item_number']);
            [$officeId, $officeNote] = $this->office($row['section'], $offices);
            if ($officeNote) {
                $result['warnings'][] = $officeNote;
            }

            // Notes in the incumbent column ("(returned to OHRMD)") aren't people.
            if (str_starts_with($row['surname'], '(')) {
                $result['errors'][] = "Not an incumbent: \"{$row['surname']}\". Skipped.";

                return $result;
            }
            if ($number && $numbers[mb_strtolower($number)] > 1) {
                $result['errors'][] = "Item number {$number} appears on more than one line.";

                return $result;
            }

            $existing = $number ? $items->first(fn (PlantillaItem $item) => mb_strtolower((string) $item->item_number) === mb_strtolower($number)) : null;

            return mb_strtoupper($row['surname']) === 'VACANT'
                ? $this->checkVacant($result, $row, $number, $existing, $officeId, $positions)
                : $this->checkFilled($result, $row, $number, $existing, $officeId, $users, $items, $matched);
        });
    }

    /**
     * Saves the rows check() found ready, in one transaction.
     *
     * @param  Collection<int, array<string, mixed>>  $checked
     * @return array<string, array<int, int>> ids touched, by kind
     */
    public function apply(Collection $checked): array
    {
        $touched = ['items_created' => [], 'items_updated' => [], 'appointments_corrected' => [], 'employees_updated' => [], 'accounts_updated' => []];

        DB::transaction(function () use ($checked, &$touched) {
            foreach ($checked->filter(fn (array $row) => $row['ready']) as $row) {
                $apply = $row['apply'];

                if (isset($apply['create_item'])) {
                    $touched['items_created'][] = $this->plantilla->createItem($apply['create_item'])->id;
                }
                if (isset($apply['item'])) {
                    $this->plantilla->updateItem(PlantillaItem::query()->findOrFail($apply['item_id']), $apply['item']);
                    $touched['items_updated'][] = $apply['item_id'];
                }
                if (isset($apply['holder'])) {
                    $appointment = PlantillaItem::query()->findOrFail($apply['item_id'])->incumbency;
                    $this->plantilla->correct($appointment, $apply['holder']);
                    $touched['appointments_corrected'][] = $appointment->id;
                }
                if (isset($apply['employee'])) {
                    Employee::query()->findOrFail($apply['employee_id'])->fill($apply['employee'])->save();
                    $touched['employees_updated'][] = $apply['employee_id'];
                }
                if (isset($apply['user'])) {
                    // Saving recomposes the display name and queues the legacy sync (User, LegacyBridge).
                    User::query()->findOrFail($apply['user_id'])->fill($apply['user'])->save();
                    $touched['accounts_updated'][] = $apply['user_id'];
                }
            }
        });

        return $touched;
    }

    /**
     * Staff in service with an active account whom no row matched — on
     * IMIS but not on the plantilla (job orders, separations not recorded).
     *
     * @param  Collection<int, array<string, mixed>>  $checked
     * @return Collection<int, User>
     */
    public function notOnSheet(Collection $checked): Collection
    {
        $matched = $checked->pluck('user_id')->filter()->all();

        return Employee::query()->whereNull('separation_date')->whereNotIn('user_id', $matched)
            ->with('user')->get()->pluck('user')
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $row
     * @param  Collection<int, User>  $users
     * @param  Collection<int, PlantillaItem>  $items
     * @param  array<int, int>  $matched  user id => line, across rows
     * @return array<string, mixed>
     */
    private function checkFilled(array $result, array $row, ?string $number, ?PlantillaItem $existing, ?int $officeId, Collection $users, Collection $items, array &$matched): array
    {
        [$user, $note] = $this->person($row, $users);
        if ($note) {
            $user ? $result['warnings'][] = $note : $result['errors'][] = $note;
        }
        if (! $user) {
            return $result;
        }
        if (isset($matched[$user->id])) {
            $result['errors'][] = "{$user->name} is also on line {$matched[$user->id]}.";

            return $result;
        }
        $matched[$user->id] = $row['line'];
        $result['user_id'] = $user->id;
        $result['holder'] = $user->name;

        $employee = $user->employee;
        if (! $employee || $employee->isSeparated()) {
            $result['errors'][] = $employee ? 'Their employee record shows them separated.' : 'They have no employee record.';

            return $result;
        }

        $dates = $this->appointmentDates($row, $result);

        // Identity, from the account.
        $identity = [];
        $sex = ['M' => 'male', 'F' => 'female'][mb_strtoupper($row['sex'])] ?? null;
        if ($sex && $user->sex !== $sex) {
            $identity['sex'] = [$user->sex, $sex];
        }
        $birthday = $this->date($row['birthday'], 'Birthday', $result);
        if ($birthday && $user->birthday?->toDateString() !== $birthday) {
            $identity['birthday'] = [$user->birthday?->toDateString(), $birthday];
        }
        $middle = $row['middle_name'] !== '' ? mb_convert_case(mb_strtolower($row['middle_name']), MB_CASE_TITLE) : null;
        if ($middle && $this->squash($user->middle_name) !== $this->squash($middle)) {
            $identity['middle_name'] = [$user->middle_name, $middle];
        }
        $this->stage($result, 'user', $identity);

        // The employee record.
        $original = $dates['original'];
        if ($original && $employee->original_appointment_date?->toDateString() !== $original) {
            $this->stage($result, 'employee', ['original_appointment_date' => [$employee->original_appointment_date?->toDateString(), $original]]);
            $result['apply']['employee_id'] = $employee->id;
        }

        // The plantilla: the item they hold in IMIS now.
        $item = $items->first(fn (PlantillaItem $candidate) => $candidate->incumbency?->employee_id === $employee->id);
        $problem = match (true) {
            ! $item => 'They hold no item in IMIS; appoint them on the plantilla first.',
            $this->title($row['position']) !== $this->title($item->position_title) => "The sheet has them as {$row['position']}, but in IMIS they hold {$item->position_title}. Record the change on the plantilla first.",
            $existing && $existing->id !== $item->id => "Item number {$number} is already IMIS's item for {$existing->position_title}.",
            default => null,
        };
        if ($problem) {
            $result['warnings'][] = "Plantilla details not applied: {$problem}";

            return $this->finish($result);
        }

        $result['apply']['item_id'] = $item->id;
        $result['item'] = $number ?? $item->position_title;
        $this->gradeWarning($result, $row, $item->salary_grade, $item->position_title);
        if (! $number) {
            $result['warnings'][] = "Item number \"{$row['item_number']}\" isn't one; left as is.";
        }

        $this->stage($result, 'item', array_filter([
            'item_number' => $number && $item->item_number !== $number ? [$item->item_number, $number] : null,
            'organizational_unit_id' => $officeId && $item->organizational_unit_id !== $officeId ? [$item->organizational_unit_id, $officeId] : null,
        ]));

        $holder = $item->incumbency;
        $wanted = [];
        $step = filter_var($row['step'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => SalarySchedules::STEPS]]);
        if ($step !== false) {
            $wanted['step'] = $step;
        } elseif ($row['step'] !== '') {
            $result['warnings'][] = "Step \"{$row['step']}\" isn't 1–".SalarySchedules::STEPS.'; left as is.';
        }
        $status = self::STATUSES[mb_strtoupper($row['status'])] ?? null;
        if ($status) {
            $wanted['status'] = $status->value;
        } elseif ($row['status'] !== '') {
            $result['warnings'][] = "Status \"{$row['status']}\" isn't P, T or C; left as is.";
        }
        if ($dates['from']) {
            $wanted['nature'] = $dates['nature']->value;
            $wanted['date_from'] = $dates['from'];
        }
        $current = ['step' => $holder->step, 'status' => $holder->status?->value, 'nature' => $holder->nature?->value, 'date_from' => $holder->date_from?->toDateString()];
        $this->stage($result, 'holder', collect($wanted)->filter(fn ($value, $field) => $value !== $current[$field])->map(fn ($value, $field) => [$current[$field], $value])->all());

        return $this->finish($result);
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $row
     * @param  Collection<int, Position>  $positions
     * @return array<string, mixed>
     */
    private function checkVacant(array $result, array $row, ?string $number, ?PlantillaItem $existing, ?int $officeId, Collection $positions): array
    {
        $result['holder'] = '(vacant)';

        if (! $number) {
            $result['errors'][] = "Item number \"{$row['item_number']}\" isn't one; can't record this vacant item.";

            return $result;
        }
        $result['item'] = $number;

        if ($existing) {
            $result['apply']['item_id'] = $existing->id;
            if ($existing->incumbency) {
                $result['warnings'][] = 'The sheet shows it vacant, but IMIS has a holder; check the item page.';
            }
            $this->gradeWarning($result, $row, $existing->salary_grade, $existing->position_title);
            $this->stage($result, 'item', $officeId && $existing->organizational_unit_id !== $officeId
                ? ['organizational_unit_id' => [$existing->organizational_unit_id, $officeId]]
                : []);

            return $this->finish($result);
        }

        $position = $positions->first(fn (Position $position) => $this->title($position->title) === $this->title($row['position']));
        if (! $position) {
            $result['errors'][] = "No position on the Positions list matches {$row['position']}; add it there first.";

            return $result;
        }

        $this->gradeWarning($result, $row, $position->salary_grade, $position->title);
        $result['changes'][] = ['field' => 'New vacant item', 'from' => '—', 'to' => $position->title.($officeId ? '' : ' (no place of assignment)')];
        $result['apply']['create_item'] = array_filter([
            'item_number' => $number,
            'position_title' => $position->title,
            'organizational_unit_id' => $officeId,
        ]);

        return $this->finish($result);
    }

    /**
     * The account a row is about: same surname (either side may be a
     * hyphenated double surname) and a first name in common. Among several,
     * the one with an employee record in service; the others are likely
     * duplicate accounts and are named in the note.
     *
     * @param  array<string, mixed>  $row
     * @param  Collection<int, User>  $users
     * @return array{0: ?User, 1: ?string}
     */
    private function person(array $row, Collection $users): array
    {
        $surname = $this->surnameParts($row['surname']);
        $first = $this->nameTokens($row['first_name']);

        $candidates = $users->filter(fn (User $user) => array_intersect($surname, $this->surnameParts($user->last_name)) !== []
            && $this->firstNamesMeet($first, $this->nameTokens($user->first_name)))->values();

        if ($candidates->count() > 1) {
            $inService = $candidates->filter(fn (User $user) => $user->employee && ! $user->employee->isSeparated())->values();
            if ($inService->count() === 1) {
                $others = $candidates->reject(fn (User $user) => $user->is($inService->first()))
                    ->map(fn (User $user) => "#{$user->id} ({$user->username})")->implode(', ');

                return [$inService->first(), "Also matches account {$others}, which has no employee record (a duplicate?)."];
            }

            return [null, 'Matches more than one account: '.$candidates->map(fn (User $user) => "#{$user->id} ({$user->username})")->implode(', ').'.'];
        }

        return $candidates->isEmpty()
            ? [null, "No IMIS account for {$row['first_name']} {$row['surname']}."]
            : [$candidates->first(), null];
    }

    /**
     * The original appointment date, and the current period's start and
     * nature as inferred from the last promotion (see the class comment).
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $result
     * @return array{original: ?string, from: ?string, nature: ?AppointmentNature}
     */
    private function appointmentDates(array $row, array &$result): array
    {
        $original = $this->date($row['original'], 'Original appointment', $result);
        $promoted = $this->date($row['last_promotion'], 'Last promotion', $result, assumption: true);

        if ($promoted && $promoted !== $original) {
            $result['warnings'][] = "Inferred: a Promotion starting {$promoted} (from the last-promotion date).";

            return ['original' => $original, 'from' => $promoted, 'nature' => AppointmentNature::Promotion];
        }
        // A promotion date that's there but unusable (future, unreadable) is
        // still a promotion: don't fall back to the original appointment.
        $noPromotion = in_array($row['last_promotion'], ['', '-'], true) || $promoted === $original;
        if ($original && $noPromotion) {
            $result['warnings'][] = "Inferred: the Original appointment, starting {$original} (no promotion on the sheet).";

            return ['original' => $original, 'from' => $original, 'nature' => AppointmentNature::Original];
        }

        return ['original' => null, 'from' => null, 'nature' => null];
    }

    /**
     * A sheet date: an Excel date number, or text the way HR types it —
     * "8/3/2026" (month first, as Excel does here; day first only when
     * the first number can't be a month, "19/12/2007"), possibly with the
     * assumption date, "6/22/2026 (A: 7/15/2026)". Text dates and the ones
     * that don't make sense are noted on the row.
     *
     * @param  array<string, mixed>  $result
     */
    private function date(string $cell, string $label, array &$result, bool $assumption = false): ?string
    {
        if ($cell === '' || $cell === '-') {
            return null;
        }

        $date = XlsxReader::excelDate($cell);
        if (! $date) {
            preg_match_all('#(\d{1,2})/(\d{1,2})/(\d{4})#', $cell, $found, PREG_SET_ORDER);
            $pick = $assumption && preg_match('/A:\s*(\d{1,2}\/\d{1,2}\/\d{4})/', $cell, $after)
                ? collect($found)->first(fn (array $match) => $match[0] === $after[1])
                : ($found[0] ?? null);

            if ($pick) {
                [, $first, $second, $year] = array_map('intval', $pick);
                [$month, $day] = $first > 12 ? [$second, $first] : [$first, $second];
                $date = checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
            }
            $result['warnings'][] = $date ? "{$label} \"{$cell}\" read as {$date}." : "{$label} \"{$cell}\" isn't a date; left as is.";
        }

        if ($date && (Carbon::parse($date)->isFuture() || Carbon::parse($date)->year < 1900)) {
            $result['warnings'][] = "{$label} {$date} is out of range; left as is.";

            return null;
        }

        return $date;
    }

    /**
     * @param  Collection<int, OrganizationalUnit>  $offices
     * @return array{0: ?int, 1: ?string}
     */
    private function office(?string $section, Collection $offices): array
    {
        $heading = mb_strtoupper((string) $section);

        foreach (self::OFFICES as $needle => $shortName) {
            if (str_contains($heading, $needle)) {
                if ($shortName === null) {
                    return [null, "Place of assignment left as is (\"{$section}\" isn't mapped to an IMIS office)."];
                }
                $office = $offices->first(fn (OrganizationalUnit $unit) => $unit->short_name === $shortName);

                return $office ? [$office->id, null] : [null, "No IMIS office {$shortName}; place of assignment left as is."];
            }
        }

        return [null, 'Place of assignment left as is (no office heading recognised above this row).'];
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $row
     */
    private function gradeWarning(array &$result, array $row, ?int $grade, string $title): void
    {
        if ($row['salary_grade'] !== '' && (string) $grade !== $row['salary_grade']) {
            $result['warnings'][] = "The sheet has SG {$row['salary_grade']}, IMIS has ".($grade ? "SG {$grade}" : 'no grade')." for {$title}; correct it on the Positions list if the sheet is right.";
        }
    }

    /** An item number like CPS-117-2005; null for "NEW" and notes. */
    private function itemNumber(string $cell): ?string
    {
        return preg_match('/^[A-Z][A-Z0-9]*-\d+-\d{4}$/i', $cell) ? mb_strtoupper($cell) : null;
    }

    /**
     * A position title reduced for comparison: no parentheticals or
     * punctuation, the sheet's abbreviations spelled out.
     */
    private function title(string $title): string
    {
        $words = preg_split('/\s+/', trim(preg_replace(['/\([^)]*\)/', '/[^a-z0-9 ]/'], ' ', mb_strtolower($title))));

        return implode(' ', array_map(fn (string $word) => self::TITLE_WORDS[$word] ?? $word, array_filter($words)));
    }

    /** @return array<int, string> "FERRER-FUENTES" → ["ferrerfuentes", "ferrer", "fuentes"] */
    private function surnameParts(?string $surname): array
    {
        $parts = array_map(fn (string $part) => $this->squash($part), explode('-', (string) $surname));

        return array_values(array_filter(array_unique([$this->squash($surname), ...$parts])));
    }

    /** @return array<int, string> first-name words, without honorifics */
    private function nameTokens(?string $name): array
    {
        $words = preg_split('/[\s.]+/', mb_strtolower((string) $name));

        return array_values(array_filter(array_map(fn (string $word) => $this->squash($word), $words),
            fn (string $word) => mb_strlen($word) >= 2 && ! in_array($word, self::HONORIFICS, true)));
    }

    /**
     * @param  array<int, string>  $sheet
     * @param  array<int, string>  $account
     */
    private function firstNamesMeet(array $sheet, array $account): bool
    {
        foreach ($sheet as $word) {
            foreach ($account as $other) {
                // "Ma." on the account stands for Maria, and the like.
                if ($word === $other || str_starts_with($word, $other) || str_starts_with($other, $word)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function squash(?string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $value);

        return preg_replace('/[^a-z]/', '', mb_strtolower($ascii === false ? (string) $value : $ascii));
    }

    /**
     * @param  array<string, string>  $cells
     * @return array<string, string>
     */
    private function cells(array $cells): array
    {
        $columns = ['item_number' => 'A', 'position' => 'B', 'salary_grade' => 'C', 'step' => 'F', 'surname' => 'H', 'first_name' => 'I',
            'middle_name' => 'J', 'sex' => 'K', 'birthday' => 'L', 'original' => 'N', 'last_promotion' => 'O', 'status' => 'P'];

        return array_map(fn (string $column) => $cells[$column] ?? '', $columns);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function blank(array $row): array
    {
        return [
            'line' => $row['line'], 'item' => $row['item_number'], 'position' => $row['position'], 'holder' => null,
            'user_id' => null, 'changes' => [], 'warnings' => [], 'errors' => [], 'ready' => false, 'apply' => [],
        ];
    }

    /**
     * Records changes ([from, to] per field) under one part of the apply payload.
     *
     * @param  array<string, mixed>  $result
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     */
    private function stage(array &$result, string $part, array $changes): void
    {
        if ($changes === []) {
            return;
        }

        foreach ($changes as $field => [$from, $to]) {
            $result['changes'][] = ['field' => self::LABELS[$field], 'from' => $this->show($field, $from), 'to' => $this->show($field, $to)];
            $result['apply'][$part][$field] = $to;
        }

        if ($part === 'user') {
            $result['apply']['user_id'] = $result['user_id'];
        }
    }

    private function show(string $field, mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' => '—',
            $field === 'organizational_unit_id' => (string) OrganizationalUnit::query()->find($value)?->short_name,
            $field === 'status' => EmploymentStatus::from($value)->label(),
            $field === 'nature' => AppointmentNature::from($value)->label(),
            default => (string) $value,
        };
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function finish(array $result): array
    {
        $result['ready'] = $result['errors'] === [] && $result['changes'] !== [];

        return $result;
    }
}
