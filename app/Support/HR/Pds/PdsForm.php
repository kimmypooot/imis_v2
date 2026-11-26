<?php

namespace App\Support\HR\Pds;

/**
 * CS Form No. 212 (Revised 2026) filled in from a PDS: for each page, the
 * sheet of CSC's workbook it is (C1–C11), the text going into each of its
 * cells (by cell or merged range, e.g. "D10:N10") and the boxes ticked (by
 * the cell each check box is linked to). The print page lays these onto
 * Excel's own rendering of the sheet (pdsFormGeometry.js) and the Excel
 * copy (PdsFormWorkbook) writes them into CSC's workbook, so both show the
 * same entries in the same places.
 *
 * C1–C4 are the form, with its own row counts; lists that don't fit
 * continue on the matching sheet (C5–C11), as the form instructs, with as
 * many continuation pages as needed.
 */
class PdsForm
{
    /**
     * Each list: where it sits on the form (sheet, first row, rows) and on
     * its continuation sheet.
     *
     * @var array<string, array{form: array{0: string, 1: int, 2: int}, more: array{0: string, 1: int, 2: int}}>
     */
    public const LISTS = [
        'children' => ['form' => ['C1', 37, 13], 'more' => ['C7', 4, 20]],
        'education' => ['form' => ['C1', 55, 5], 'more' => ['C8', 6, 37]],
        'eligibility' => ['form' => ['C2', 5, 7], 'more' => ['C9', 5, 24]],
        'work_experience' => ['form' => ['C2', 18, 24], 'more' => ['C6', 7, 33]],
        'learning_development' => ['form' => ['C3', 5, 17], 'more' => ['C5', 6, 44]],
        'voluntary_work' => ['form' => ['C3', 27, 9], 'more' => ['C10', 6, 29]],
        'other_information' => ['form' => ['C3', 39, 7], 'more' => ['C11', 4, 31]],
    ];

    /**
     * Each sheet's signature and date cells.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const SIGNATURE = [
        'C1' => ['D61:I61', 'L61:N61'], 'C2' => ['D43:H43', 'J43:M43'], 'C3' => ['C47:F47', 'I47:K47'],
        'C4' => ['G60:J62', 'G64:J64'], 'C5' => ['C50:E50', 'H50:K50'], 'C6' => ['D40:F40', 'I40:M40'],
        'C7' => ['C24:F24', 'I24:L24'], 'C8' => ['D43:I43', 'L43:N43'], 'C9' => ['D29:F29', 'I29:K29'],
        'C10' => ['C35:F35', 'I35:K35'], 'C11' => ['C35:E35', 'H35:K35'],
    ];

    /** Lists whose entries are centred in their cells (here and on their continuation sheets). */
    private const CENTRED = ['education', 'eligibility', 'work_experience', 'other_information'];

    /** The order continuation sheets come in. */
    private const CONTINUATION_ORDER = ['C5', 'C6', 'C7', 'C8', 'C9', 'C10', 'C11'];

    /** One row per level on C1 (rows 55–59). */
    private const LEVELS = ['elementary', 'secondary', 'vocational', 'college', 'graduate'];

    private const NA = 'N/A';

    /** @var array<string, mixed> */
    private array $identity = [];

    /** @var array<string, mixed> */
    private array $sections = [];

    /**
     * @param  array{identity: array<string, mixed>, sections: array<string, mixed>}  $pds  PdsReader::read() or a submission's snapshot
     * @param  array{name: string, signed_at: string}|null  $signature  who e-signed it and when; null for the current (unsigned) PDS
     * @return array<int, array{sheet: string, cells: array<int, array{at: string, text: string, align?: string, kind?: string}>, checks: array<int, string>}>
     */
    public function pages(array $pds, ?array $signature = null): array
    {
        $this->identity = $pds['identity'] ?? [];
        $this->sections = $pds['sections'] ?? [];

        $pages = [
            'C1' => ['sheet' => 'C1', 'cells' => [...$this->personal(), ...$this->family()], 'checks' => $this->personalChecks()],
            'C2' => ['sheet' => 'C2', 'cells' => [], 'checks' => []],
            'C3' => ['sheet' => 'C3', 'cells' => [], 'checks' => []],
            'C4' => ['sheet' => 'C4', 'cells' => [...$this->questions(), ...$this->references(), ...$this->governmentId()], 'checks' => $this->questionChecks()],
        ];
        $continued = array_fill_keys(self::CONTINUATION_ORDER, []);

        foreach ($this->lists() as $list => $rows) {
            [$onForm, $more] = $this->split($list, $rows);
            [$sheet, $first] = self::LISTS[$list]['form'];
            array_push($pages[$sheet]['cells'], ...$this->listCells($list, $sheet, $first, $onForm));

            [$moreSheet, $moreFirst, $perPage] = self::LISTS[$list]['more'];
            foreach (array_chunk($more, $perPage) as $chunk) {
                $continued[$moreSheet][] = ['sheet' => $moreSheet, 'cells' => $this->listCells($list, $moreSheet, $moreFirst, $chunk), 'checks' => []];
            }
        }

        $all = [...array_values($pages), ...array_merge(...array_values($continued))];

        return array_map(fn (array $page) => [
            ...$page,
            'cells' => array_values(array_filter(
                [...$page['cells'], ...$this->signatureCells($page['sheet'], $signature)],
                fn (array $cell) => $cell['text'] !== '',
            )),
        ], $all);
    }

    // ── Page 1: I. Personal information, II. Family background ────────

    /**
     * @return array<int, array<string, string>>
     */
    private function personal(): array
    {
        $id = $this->identity;
        $p = $this->sections['personal'] ?? [];
        $dual = ($p['citizenship'] ?? null) === 'dual';

        $cells = [
            $this->cell('D10:N10', $this->upper($id['surname'] ?? null)),
            $this->cell('D11:K11', $this->upper($id['first_name'] ?? null)),
            $this->cell('N11', $this->upper($id['name_extension'] ?? null) ?: self::NA, 'center'),
            $this->cell('D12:N12', $this->upper($id['middle_name'] ?? null) ?: self::NA),
            $this->cell('D13:F14', $this->na($this->dmy($id['date_of_birth'] ?? null))),
            $this->cell('D15:F15', $this->na($this->text($p['place_of_birth'] ?? null))),
            $this->cell('E20:F21', ($p['civil_status'] ?? null) === 'other' ? $this->text($p['civil_status_other'] ?? null) : ''),
            $this->cell('J16:M16', $dual ? $this->text($p['dual_citizenship_country'] ?? null) : ''),
            $this->cell('D22:F23', $this->na($this->text($p['height_m'] ?? null)), 'center'),
            $this->cell('D24:F24', $this->na($this->text($p['weight_kg'] ?? null)), 'center'),
            $this->cell('D25:F26', $this->na($this->text($p['blood_type'] ?? null)), 'center'),
            $this->cell('D27:F28', $this->na($this->text($p['umid_no'] ?? null)), 'center'),
            $this->cell('D29:F30', $this->na($this->text($p['pagibig_no'] ?? null)), 'center'),
            $this->cell('D31:F31', $this->na($this->text($p['philhealth_no'] ?? null)), 'center'),
            $this->cell('D32:F32', $this->na($this->text($p['philsys_no'] ?? null)), 'center'),
            $this->cell('D33:F33', $this->na($this->text($p['tin_no'] ?? null)), 'center'),
            $this->cell('D34:F34', $this->na($this->text($id['agency_employee_no'] ?? null)), 'center'),
            $this->cell('I32:N32', $this->na($this->text($p['telephone_no'] ?? null)), 'center'),
            $this->cell('I33:N33', $this->na($this->text($p['mobile_no'] ?? null)), 'center'),
            $this->cell('I34:N34', $this->na($this->text($p['email'] ?? null)), 'center'),
        ];

        foreach (['residential' => [17, 19, 22, 24], 'permanent' => [25, 27, 29, 31]] as $kind => [$first, $second, $third, $zip]) {
            $source = $kind === 'permanent' && ! empty($p['permanent_same_as_residential']) ? 'residential' : $kind;
            $part = fn (string $key) => $this->na($this->text($p["{$source}_{$key}"] ?? null));
            array_push($cells,
                $this->cell("I{$first}:K{$first}", $part('house_no'), 'center'),
                $this->cell("L{$first}:N{$first}", $part('street'), 'center'),
                $this->cell("I{$second}:K".($second + ($kind === 'residential' ? 1 : 0)), $part('subdivision'), 'center'),
                $this->cell("L{$second}:N".($second + ($kind === 'residential' ? 1 : 0)), $part('barangay'), 'center'),
                $this->cell("I{$third}:K{$third}", $part('city'), 'center'),
                $this->cell("L{$third}:N{$third}", $part('province'), 'center'),
                $this->cell("I{$zip}:N{$zip}", $part('zip_code'), 'center'),
            );
        }

        return $cells;
    }

    /**
     * Check boxes on C1, by their linked cells.
     *
     * @return array<int, string>
     */
    private function personalChecks(): array
    {
        $p = $this->sections['personal'] ?? [];
        $checks = [
            'D16' => ($this->identity['sex_at_birth'] ?? null) === 'male',
            'E16' => ($this->identity['sex_at_birth'] ?? null) === 'female',
            'D17' => ($p['civil_status'] ?? null) === 'single',
            'E17' => ($p['civil_status'] ?? null) === 'married',
            'D18' => ($p['civil_status'] ?? null) === 'widowed',
            'E19' => ($p['civil_status'] ?? null) === 'separated',
            'D20' => ($p['civil_status'] ?? null) === 'other',
            'J13' => ($p['citizenship'] ?? null) === 'filipino',
            'K13' => ($p['citizenship'] ?? null) === 'dual',
            'L14' => ($p['citizenship'] ?? null) === 'dual' && ($p['dual_citizenship_by'] ?? null) === 'birth',
            'M14' => ($p['citizenship'] ?? null) === 'dual' && ($p['dual_citizenship_by'] ?? null) === 'naturalization',
        ];

        return array_keys(array_filter($checks));
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function family(): array
    {
        $f = $this->sections['family'] ?? [];
        $t = fn (string $key) => $this->na($this->text($f[$key] ?? null));
        $u = fn (string $key) => $this->na($this->upper($f[$key] ?? null));

        return [
            $this->cell('D36:H36', $u('spouse_surname')),
            $this->cell('D37:F37', $u('spouse_first_name')),
            $this->cell('H37', $u('spouse_name_extension'), 'center'),
            $this->cell('D38:H38', $u('spouse_middle_name')),
            $this->cell('D39:H39', $t('spouse_occupation')),
            $this->cell('D40:H40', $t('spouse_employer')),
            $this->cell('D41:H41', $t('spouse_business_address')),
            $this->cell('D42:H42', $t('spouse_telephone_no')),
            $this->cell('D44:H44', $u('father_surname')),
            $this->cell('D45:F45', $u('father_first_name')),
            $this->cell('H45', $u('father_name_extension'), 'center'),
            $this->cell('D46:H46', $u('father_middle_name')),
            $this->cell('D48:H48', $u('mother_surname')),
            $this->cell('D49:H49', $u('mother_first_name')),
            $this->cell('D50:H50', $u('mother_middle_name')),
        ];
    }

    // ── Page 4: questions 34–40, references, ID ───────────────────────

    /**
     * Yes (column H) and No (column J) boxes, by the row they sit on.
     *
     * @var array<string, int>
     */
    private const QUESTION_ROWS = [
        'q34a' => 3, 'q34b' => 8, 'q35a' => 13, 'q35b' => 18, 'q36' => 23, 'q37' => 27,
        'q38a' => 31, 'q38b' => 34, 'q39' => 37, 'q40a' => 43, 'q40b' => 45, 'q40c' => 47,
    ];

    /**
     * @return array<int, string>
     */
    private function questionChecks(): array
    {
        $q = $this->sections['questions'] ?? [];
        $checks = [];
        foreach (self::QUESTION_ROWS as $key => $row) {
            if (($q[$key] ?? null) === true) {
                $checks[] = "H{$row}";
            } elseif (($q[$key] ?? null) === false) {
                $checks[] = "J{$row}";
            }
        }

        return $checks;
    }

    /**
     * Each answer on the blank line under its question's "If YES" prompt.
     *
     * @return array<int, array<string, string>>
     */
    private function questions(): array
    {
        $q = $this->sections['questions'] ?? [];
        // The details only when the answer is Yes: the prompt reads "If YES,
        // give details", so a No (or no answer) leaves its line blank.
        $answer = fn (string $question, string $text) => ($q[$question] ?? null) === true ? $text : '';
        $t = fn (string $question, string $key) => $answer($question, $this->text($q[$key] ?? null));
        // Item 34 has one answer: on a's line, or on b's when only b is Yes.
        $onlyB = ($q['q34a'] ?? null) !== true && ($q['q34b'] ?? null) === true;

        $lines = [
            'I6:M6' => $onlyB ? '' : $t('q34a', 'q34_details'),
            'I11:M11' => $onlyB ? $t('q34b', 'q34_details') : '',
            'I15:M15' => $t('q35a', 'q35a_details'),
            'L19:M19' => $t('q35b', 'q35b_details'),
            'L20:M20' => $answer('q35b', $this->dmy($q['q35b_date_filed'] ?? null)),
            'L21:M21' => $t('q35b', 'q35b_case_status'),
            'I25:M25' => $t('q36', 'q36_details'),
            'I29:M29' => $t('q37', 'q37_details'),
            'L32:M32' => $t('q38a', 'q38a_details'),
            'L35:M35' => $t('q38b', 'q38b_details'),
            'I39:M39' => $t('q39', 'q39_country'),
            'M44' => $t('q40a', 'q40a_group'),
            'M46' => $t('q40b', 'q40b_id'),
            'M48' => $t('q40c', 'q40c_id'),
        ];

        // Each answer centred on its line.
        return array_map(fn (string $at, string $text) => $this->cell($at, $text, 'center'), array_keys($lines), $lines);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function references(): array
    {
        $cells = [];
        // None at all: N/A on the first line, as the guide asks.
        foreach (array_slice($this->sections['references'] ?? [], 0, 3) ?: [[]] as $i => $row) {
            $r = 52 + $i;
            array_push($cells,
                $this->cell("A{$r}:F{$r}", $this->na($this->text($row['name'] ?? null)), 'center'),
                $this->cell("G{$r}", $this->na($this->text($row['address'] ?? null)), 'center'),
                $this->cell("H{$r}:J{$r}", $this->na($this->text($row['contact'] ?? null)), 'center'),
            );
        }

        return $cells;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function governmentId(): array
    {
        $g = $this->sections['government_id'] ?? [];

        return [
            $this->cell('D61:E61', $this->na($this->text($g['government_id_type'] ?? null))),
            $this->cell('D62:E63', $this->na($this->text($g['government_id_number'] ?? null))),
            $this->cell('D64:E65', $this->na($this->text($g['government_id_issuance'] ?? null))),
        ];
    }

    // ── Lists (items 23 and 26–33) ─────────────────────────────────────

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function lists(): array
    {
        $s = $this->sections;
        $detail = fn (string $key) => array_map(fn (array $row) => $row['detail'] ?? '', $s[$key] ?? []);
        $skills = $detail('skills');
        $distinctions = $detail('distinctions');
        $memberships = $detail('memberships');
        $other = [];
        for ($i = 0; $i < max(count($skills), count($distinctions), count($memberships)); $i++) {
            $other[] = ['skills' => $skills[$i] ?? '', 'distinctions' => $distinctions[$i] ?? '', 'memberships' => $memberships[$i] ?? ''];
        }
        // Items 31–33 are separate lists side by side: one with nothing in it gets N/A on the first line.
        foreach (['skills' => $skills, 'distinctions' => $distinctions, 'memberships' => $memberships] as $column => $values) {
            if ($other !== [] && $values === []) {
                $other[0][$column] = self::NA;
            }
        }

        // Work experience starts from the current position (the guide):
        // the one still held first, then by start date, latest first.
        $work = $s['work_experience'] ?? [];
        usort($work, fn (array $a, array $b) => [empty($a['date_to']) ? 0 : 1, $b['date_from'] ?? ''] <=> [empty($b['date_to']) ? 0 : 1, $a['date_from'] ?? '']);

        return [
            'children' => $s['children'] ?? [],
            'education' => $s['education'] ?? [],
            'eligibility' => $s['eligibility'] ?? [],
            'work_experience' => $work,
            'learning_development' => $s['learning_development'] ?? [],
            'voluntary_work' => $s['voluntary_work'] ?? [],
            'other_information' => $other,
        ];
    }

    /**
     * The rows that go on the form, by position (null = an empty row), and the rest.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{0: array<int, array<string, mixed>|null>, 1: array<int, array<string, mixed>>}
     */
    private function split(string $list, array $rows): array
    {
        if ($list === 'education') {
            $onForm = array_fill(0, count(self::LEVELS), null);
            $more = [];
            foreach ($rows as $row) {
                $slot = array_search($row['level'] ?? null, self::LEVELS, true);
                if ($slot !== false && $onForm[$slot] === null) {
                    $onForm[$slot] = $row;
                } else {
                    $more[] = $row;
                }
            }

            return [$onForm, $more];
        }

        $count = self::LISTS[$list]['form'][2];

        // An empty list gets one N/A line (an empty row, see rowCells).
        return [array_slice($rows, 0, $count) ?: [[]], array_slice($rows, $count)];
    }

    /**
     * @param  array<int, array<string, mixed>|null>  $rows
     * @return array<int, array<string, string>>
     */
    private function listCells(string $list, string $sheet, int $first, array $rows): array
    {
        $cells = [];
        foreach ($rows as $i => $row) {
            // A level with no school on C1 is not applicable: N/A across its row.
            foreach ($this->rowCells($list, $sheet, $row ?? []) as [$cols, $text, $align]) {
                $r = $first + $i;
                $range = str_contains($cols, ':') ? str_replace(':', "{$r}:", $cols).$r : $cols.$r;
                $cells[] = $this->cell($range, $text, $align);
            }
        }

        return $cells;
    }

    /**
     * One row's entries as [columns, text, alignment]. Empty cells of a row
     * read N/A, as the guide asks; an empty row ([]) is N/A throughout.
     * Items 31–33 are separate lists, so their blanks stay blank.
     *
     * @param  array<string, mixed>  $row
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    private function rowCells(string $list, string $sheet, array $row): array
    {
        $cells = $this->rowEntries($list, $sheet, $row);
        if (in_array($list, self::CENTRED, true)) {
            $cells = array_map(fn (array $cell) => [$cell[0], $cell[1], 'center'], $cells);
        }

        if ($row === []) {
            return array_map(fn (array $cell) => [$cell[0], self::NA, 'center'], $cells);
        }
        if ($list === 'other_information') {
            return $cells;
        }

        return array_map(fn (array $cell) => [$cell[0], $this->na($cell[1]), $cell[1] === '' ? 'center' : $cell[2]], $cells);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    private function rowEntries(string $list, string $sheet, array $row): array
    {
        $t = fn (string $key) => $this->text($row[$key] ?? null);
        $d = fn (string $key) => $this->dmy($row[$key] ?? null);

        return match ($list) {
            'children' => $sheet === 'C1'
                ? [['I:L', $t('full_name'), 'left'], ['M:N', $d('date_of_birth'), 'center']]
                : [['I:K', $t('full_name'), 'left'], ['L', $d('date_of_birth'), 'center']],
            'education' => [
                ...($sheet === 'C8' ? [['A:C', $this->levelLabel($row['level'] ?? null), 'left']] : []),
                ['D:F', $t('school'), 'left'], ['G:I', $t('degree'), 'left'],
                ['J', $t('attendance_from'), 'center'], ['K', $t('attendance_to'), 'center'],
                ['L', $t('highest_level_units'), 'center'], ['M', $t('year_graduated'), 'center'], ['N', $t('honors'), 'center'],
            ],
            'eligibility' => $sheet === 'C2'
                ? [['A:E', $t('eligibility'), 'left'], ['F', $t('rating'), 'center'], ['G:H', $d('exam_date'), 'center'],
                    ['I:K', $t('exam_place'), 'left'], ['L', $t('license_number'), 'center'], ['M', $d('license_valid_until'), 'center']]
                : [['A:E', $t('eligibility'), 'left'], ['F', $t('rating'), 'center'], ['G:H', $d('exam_date'), 'center'],
                    ['I', $t('exam_place'), 'left'], ['J', $t('license_number'), 'center'], ['K', $d('license_valid_until'), 'center']],
            'work_experience' => [
                ['A:B', $d('date_from'), 'center'], ['C', ($row['date_to'] ?? null) ? $d('date_to') : 'PRESENT', 'center'],
                ['D:F', $t('position_title'), 'left'], ['G:I', $t('agency'), 'left'], ['J', $this->money($row['monthly_salary'] ?? null), 'right'],
                ['K', $this->payGrade($t('salary_grade')), 'center'], ['L', $t('appointment_status'), 'center'], ['M', ! empty($row['is_government']) ? 'Y' : 'N', 'center'],
            ],
            'learning_development' => [
                ['A:D', $t('title'), 'left'], ['E', $d('date_from'), 'center'], ['F', $d('date_to'), 'center'],
                ['G', $t('hours'), 'center'], ['H', $t('ld_type'), 'center'], ['I:K', $t('sponsor'), 'left'],
            ],
            'voluntary_work' => [
                ['A:D', $t('organization'), 'left'], ['E', $d('date_from'), 'center'], ['F', $d('date_to'), 'center'],
                ['G', $t('hours'), 'center'], ['H:K', $t('position'), 'left'],
            ],
            'other_information' => [['A:B', $t('skills'), 'left'], ['C:H', $t('distinctions'), 'left'], ['I:K', $t('memberships'), 'left']],
        };
    }

    private function levelLabel(?string $level): string
    {
        return match ($level) {
            'elementary' => 'ELEMENTARY', 'secondary' => 'SECONDARY', 'vocational' => 'VOCATIONAL / TRADE COURSE',
            'college' => 'COLLEGE', 'graduate' => 'GRADUATE STUDIES', default => '',
        };
    }

    // ── Signature and helpers ──────────────────────────────────────────

    /**
     * @param  array{name: string, signed_at: string}|null  $signature
     * @return array<int, array<string, string>>
     */
    private function signatureCells(string $sheet, ?array $signature): array
    {
        if ($signature === null) {
            return [];
        }
        [$sign, $date] = self::SIGNATURE[$sheet];

        return [
            ['at' => $sign, 'text' => "Electronically signed through IMIS by {$signature['name']}", 'align' => 'center', 'kind' => 'signature'],
            $this->cell($date, $this->dmy($signature['signed_at']), 'center'),
        ];
    }

    /**
     * @return array{at: string, text: string, align?: string}
     */
    private function cell(string $at, string $text, string $align = 'left'): array
    {
        return $align === 'left' ? ['at' => $at, 'text' => $text] : ['at' => $at, 'text' => $text, 'align' => $align];
    }

    /** "Do not leave blank entries. Put N/A if not applicable." (CSC's guide to the PDS) */
    private function na(string $text): string
    {
        return $text === '' ? self::NA : $text;
    }

    private function text(mixed $value): string
    {
        return $value === null ? '' : trim((string) $value);
    }

    private function upper(mixed $value): string
    {
        return mb_strtoupper($this->text($value));
    }

    /** dd/mm/yyyy, as the form asks. */
    private function dmy(mixed $value): string
    {
        $value = $this->text($value);
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
            return $value;
        }

        return "{$m[3]}/{$m[2]}/{$m[1]}";
    }

    /** Monthly salary in figures, as the guide shows it: ₱21,877.00. */
    private function money(mixed $value): string
    {
        return is_numeric($value) ? '₱'.number_format((float) $value, 2) : $this->text($value);
    }

    /** Salary/job/pay grade and step in the form's "00-0" format (e.g. 9-1 → 09-1). */
    private function payGrade(string $value): string
    {
        return preg_match('/^(\d{1,2})-(\d{1,2})$/', $value, $m) ? sprintf('%02d-%d', $m[1], $m[2]) : $value;
    }
}
