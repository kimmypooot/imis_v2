<?php

namespace App\Support\HR\Pds;

use App\Enums\LdModality;
use App\Enums\LdType;
use App\Support\Tables\XlsxReader;
use RuntimeException;

/**
 * Reads a filled-in CS Form No. 212 (Revised 2026) workbook — the official
 * Excel file from the CSC — into the same shapes the PDS editor saves:
 * one payload per section, keyed like PdsSchema.
 *
 * It only reads; nothing is validated or stored here (see PdsImport).
 * The sheets C1–C4 are the form; C5–C11 continue its lists. Rows are found
 * by their headings, not by fixed numbers, because people insert rows to
 * fit longer lists. Tick boxes are Excel form controls linked to cells
 * that hold 1 (ticked) or 0.
 */
class PdsWorkbookReader
{
    /** A row number past the end of any sheet. */
    private const END = 1000000;

    /** @var array<string, array<int, array<string, string>>> */
    private array $sheets = [];

    /** @var array<int, string> */
    private array $warnings = [];

    /**
     * @return array{sections: array<string, array<string, mixed>>, warnings: array<int, string>, identity: array{surname: ?string, first_name: ?string}}
     */
    public function read(string $path): array
    {
        $this->sheets = (new XlsxReader)->read($path);
        $this->warnings = [];
        $this->assertIsPds();

        $sections = [
            'personal' => $this->personal(),
            'family' => $this->family(),
            'children' => ['rows' => $this->children()],
            'education' => ['rows' => $this->education()],
            'eligibility' => ['rows' => $this->eligibility()],
            'work_experience' => ['rows' => $this->workExperience()],
            'learning_development' => ['rows' => $this->learningDevelopment()],
            'voluntary_work' => ['rows' => $this->voluntaryWork()],
            ...$this->otherInformation(),
            'questions' => $this->questions(),
            'references' => ['rows' => $this->references()],
            'government_id' => $this->governmentId(),
        ];

        return [
            'sections' => $sections,
            'warnings' => $this->warnings,
            'identity' => ['surname' => $this->cell('C1', 'D', 10), 'first_name' => $this->cell('C1', 'D', 11)],
        ];
    }

    private function assertIsPds(): void
    {
        $looksRight = isset($this->sheets['C1'], $this->sheets['C2'], $this->sheets['C3'], $this->sheets['C4'])
            && preg_match('/SURNAME/i', (string) ($this->sheets['C1'][10]['B'] ?? ''))
            && preg_match('/HEIGHT/i', (string) ($this->sheets['C1'][22]['B'] ?? ''))
            && str_starts_with((string) ($this->sheets['C4'][2]['A'] ?? ''), '34');

        if (! $looksRight) {
            throw new RuntimeException('This is not the CS Form No. 212 (Revised 2026) workbook. Use the official Excel file from the CSC, with its sheets C1 to C4 unchanged.');
        }
    }

    // ── Section I and II ─────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function personal(): array
    {
        $c = fn (string $col, int $row) => $this->cell('C1', $col, $row);

        $civil = $this->pick('C1', [
            'D17' => 'single', 'E17' => 'married', 'D18' => 'widowed', 'E19' => 'separated', 'D20' => 'other',
        ], 'civil status');
        $dual = $this->ticked('C1', 'K', 13);

        $residential = $this->address('C1', [17, 19, 22], 24);
        $permanent = $this->address('C1', [25, 27, 29], 31);
        $same = $residential === $permanent && array_filter($residential) !== [];

        return [
            'name_extension' => $c('N', 11),
            'place_of_birth' => $c('D', 15),
            'civil_status' => $civil,
            'height_m' => $this->number($c('D', 22)),
            'weight_kg' => $this->number($c('D', 24)),
            'blood_type' => $c('D', 25) ? strtoupper(str_replace(' ', '', $c('D', 25))) : null,
            'umid_no' => $c('D', 27),
            'pagibig_no' => $c('D', 29),
            'philhealth_no' => $c('D', 31),
            'philsys_no' => $c('D', 32),
            'tin_no' => $c('D', 33),
            'citizenship' => $dual ? 'dual' : 'filipino',
            'dual_citizenship_by' => $dual ? ($this->ticked('C1', 'L', 14) ? 'birth' : ($this->ticked('C1', 'M', 14) ? 'naturalization' : null)) : null,
            'dual_citizenship_country' => $dual ? $this->dualCountry() : null,
            ...$this->prefixed('residential', $residential),
            'permanent_same_as_residential' => $same,
            ...$this->prefixed('permanent', $same ? [] : $permanent),
            'telephone_no' => $c('I', 32),
            'mobile_no' => $c('I', 33),
            'email' => $c('I', 34) ? strtolower($c('I', 34)) : null,
        ];
    }

    /**
     * @param  array<int, int>  $rows  the street/house, subdivision/barangay and city/province rows
     * @return array<string, ?string>
     */
    private function address(string $sheet, array $rows, int $zipRow): array
    {
        $c = fn (string $col, int $row) => $this->cell($sheet, $col, $row);

        return [
            'house_no' => $c('I', $rows[0]), 'street' => $c('L', $rows[0]),
            'subdivision' => $c('I', $rows[1]), 'barangay' => $c('L', $rows[1]),
            'city' => $c('I', $rows[2]), 'province' => $c('L', $rows[2]),
            'zip_code' => $c('I', $zipRow),
        ];
    }

    /**
     * @param  array<string, ?string>  $address
     * @return array<string, ?string>
     */
    private function prefixed(string $prefix, array $address): array
    {
        $keys = ['house_no', 'street', 'subdivision', 'barangay', 'city', 'province', 'zip_code'];

        return collect($keys)->mapWithKeys(fn (string $key) => ["{$prefix}_{$key}" => $address[$key] ?? null])->all();
    }

    /**
     * The dual-citizenship country is a drop-down whose cell holds the
     * chosen item's position in the list kept in column Q (from row 11).
     */
    private function dualCountry(): ?string
    {
        $index = (int) ($this->raw('C1', 'J', 16) ?? 0);

        return $index > 1 ? $this->cell('C1', 'Q', 10 + $index) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function family(): array
    {
        $c = fn (string $col, int $row) => $this->cell('C1', $col, $row);

        return [
            'spouse_surname' => $c('D', 36), 'spouse_first_name' => $c('D', 37), 'spouse_name_extension' => $c('H', 37),
            'spouse_middle_name' => $c('D', 38), 'spouse_occupation' => $c('D', 39), 'spouse_employer' => $c('D', 40),
            'spouse_business_address' => $c('D', 41), 'spouse_telephone_no' => $c('D', 42),
            'father_surname' => $c('D', 44), 'father_first_name' => $c('D', 45), 'father_name_extension' => $c('H', 45),
            'father_middle_name' => $c('D', 46),
            'mother_surname' => $c('D', 48), 'mother_first_name' => $c('D', 49), 'mother_middle_name' => $c('D', 50),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function children(): array
    {
        $continued = $this->findSheet('C7');

        return $this->collect([
            ['sheet' => 'C1', 'rows' => $this->between('C1', $this->rowWhere('C1', 'I', '/^23\./') + 1, $this->rowWhere('C1', 'I', '/^\(Continue on sheet C7/i')), 'map' => ['I', 'M']],
            ['sheet' => $continued, 'rows' => $this->between($continued, $this->rowWhere($continued, 'I', '/NAME of CHILDREN/i') + 1, $this->rowWhere($continued, 'A', '/^SIGNATURE/i')), 'map' => ['I', 'L']],
        ], fn (string $sheet, int $row, array $map) => [
            'full_name' => $this->cell($sheet, $map[0], $row),
            'date_of_birth' => $this->date($this->raw($sheet, $map[1], $row)),
        ]);
    }

    // ── Section III to VII ───────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    private function education(): array
    {
        $levels = ['/ELEMENTARY/i' => 'elementary', '/SECONDARY/i' => 'secondary', '/VOCATIONAL/i' => 'vocational', '/COLLEGE/i' => 'college', '/GRADUATE/i' => 'graduate'];
        $level = function (string $sheet, int $row) use ($levels): ?string {
            $label = ($this->cell($sheet, 'B', $row) ?? '').' '.($this->cell($sheet, 'A', $row) ?? '');
            foreach ($levels as $pattern => $value) {
                if (preg_match($pattern, $label)) {
                    return $value;
                }
            }

            return null;
        };

        $first = $this->rowWhere('C1', 'J', '/^From$/i') + 1;
        $continued = $this->findSheet('C8');

        return $this->collect([
            ['sheet' => 'C1', 'rows' => $this->between('C1', $first, $this->rowWhere('C1', 'A', '/^\(Continue on sheet C8/i')), 'map' => null],
            ['sheet' => $continued, 'rows' => $this->between($continued, $this->rowWhere($continued, 'J', '/^From$/i') + 1, $this->rowWhere($continued, 'A', '/^SIGNATURE/i')), 'map' => null],
        ], fn (string $sheet, int $row) => [
            'level' => $level($sheet, $row),
            'school' => $this->cell($sheet, 'D', $row),
            'degree' => $this->cell($sheet, 'G', $row),
            'attendance_from' => $this->year($this->cell($sheet, 'J', $row)),
            'attendance_to' => $this->year($this->cell($sheet, 'K', $row)),
            'highest_level_units' => $this->cell($sheet, 'L', $row),
            'year_graduated' => $this->year($this->cell($sheet, 'M', $row)),
            'honors' => $this->cell($sheet, 'N', $row),
        ], ignore: ['level']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function eligibility(): array
    {
        $continued = $this->findSheet('C9');

        return $this->collect([
            ['sheet' => 'C2', 'rows' => $this->between('C2', $this->rowWhere('C2', 'L', '/^NUMBER$/i') + 1, $this->rowWhere('C2', 'A', '/^\(Continue on sheet C9/i')), 'map' => ['L', 'M']],
            ['sheet' => $continued, 'rows' => $this->between($continued, $this->rowWhere($continued, 'J', '/^NUMBER$/i') + 1, $this->rowWhere($continued, 'A', '/^SIGNATURE/i')), 'map' => ['J', 'K']],
        ], fn (string $sheet, int $row, array $map) => [
            'eligibility' => $this->cell($sheet, 'A', $row),
            'rating' => $this->cell($sheet, 'F', $row),
            'exam_date' => $this->date($this->raw($sheet, 'G', $row)),
            'exam_place' => $this->cell($sheet, 'I', $row),
            'license_number' => $this->cell($sheet, $map[0], $row),
            'license_valid_until' => $this->date($this->raw($sheet, $map[1], $row)),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function workExperience(): array
    {
        $continued = $this->findSheet('C6');

        return $this->collect([
            ['sheet' => 'C2', 'rows' => $this->between('C2', $this->rowWhere('C2', 'A', '/^From$/i') + 1, $this->rowWhere('C2', 'A', '/^\(Continue on sheet C6/i')), 'map' => null],
            ['sheet' => $continued, 'rows' => $this->between($continued, $this->rowWhere($continued, 'A', '/^From$/i') + 1, $this->rowWhere($continued, 'A', '/^SIGNATURE/i')), 'map' => null],
        ], fn (string $sheet, int $row) => [
            'date_from' => $this->date($this->raw($sheet, 'A', $row)),
            'date_to' => $this->date($this->raw($sheet, 'C', $row)),
            'position_title' => $this->cell($sheet, 'D', $row),
            'agency' => $this->cell($sheet, 'G', $row),
            'monthly_salary' => $this->number($this->cell($sheet, 'J', $row)),
            'salary_grade' => $this->cell($sheet, 'K', $row),
            'appointment_status' => $this->cell($sheet, 'L', $row),
            'is_government' => strtoupper(substr((string) $this->cell($sheet, 'M', $row), 0, 1)) === 'Y',
        ], ignore: ['is_government']);
    }

    /**
     * Item 29, from C3 and its continuation C5. The form has no delivery
     * mode, so that is left as "other" for HR to correct in the L&D log.
     *
     * @return array<int, array<string, mixed>>
     */
    private function learningDevelopment(): array
    {
        $continued = $this->findSheet('C5');

        return $this->collect([
            ['sheet' => 'C3', 'rows' => $this->between('C3', $this->rowWhere('C3', 'E', '/^From$/i') + 1, $this->rowWhere('C3', 'A', '/^\\(Continue on sheet C5/i')), 'map' => null],
            ['sheet' => $continued, 'rows' => $this->between($continued, $this->rowWhere($continued, 'E', '/^From$/i') + 1, $this->rowWhere($continued, 'A', '/^SIGNATURE/i')), 'map' => null],
        ], function (string $sheet, int $row) {
            [$type, $other] = LdType::fromText($this->cell($sheet, 'H', $row));

            return [
                'title' => $this->cell($sheet, 'A', $row),
                'date_from' => $this->date($this->raw($sheet, 'E', $row)),
                'date_to' => $this->date($this->raw($sheet, 'F', $row)),
                'hours' => $this->number($this->cell($sheet, 'G', $row)),
                'ld_type' => $type?->value,
                'ld_type_other' => $other,
                'sponsor' => $this->cell($sheet, 'I', $row),
                'modality' => LdModality::Other->value,
            ];
        }, ignore: ['modality', 'ld_type', 'ld_type_other']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function voluntaryWork(): array
    {
        $continued = $this->findSheet('C10');
        $fromRows = $this->rowsWhere('C3', 'E', '/^From$/i');
        $first = ($fromRows[1] ?? self::END) + 1;

        return $this->collect([
            ['sheet' => 'C3', 'rows' => $this->between('C3', $first, $this->rowWhere('C3', 'A', '/^\(Continue on sheet C10/i')), 'map' => null],
            ['sheet' => $continued, 'rows' => $this->between($continued, $this->rowWhere($continued, 'E', '/^From$/i') + 1, $this->rowWhere($continued, 'A', '/^SIGNATURE/i')), 'map' => null],
        ], fn (string $sheet, int $row) => [
            'organization' => $this->cell($sheet, 'A', $row),
            'date_from' => $this->date($this->raw($sheet, 'E', $row)),
            'date_to' => $this->date($this->raw($sheet, 'F', $row)),
            'hours' => $this->number($this->cell($sheet, 'G', $row)),
            'position' => $this->cell($sheet, 'H', $row),
        ]);
    }

    /**
     * Items 31–33 sit side by side: skills in column A, distinctions in C
     * (D on the continuation sheet reads the same), memberships in I.
     *
     * @return array<string, array{rows: array<int, array{detail: string}>}>
     */
    private function otherInformation(): array
    {
        $continued = $this->findSheet('C11');
        $blocks = [
            ['C3', $this->between('C3', $this->rowWhere('C3', 'B', '/^SPECIAL SKILLS/i') + 1, $this->rowWhere('C3', 'A', '/^\(Continue on sheet C11/i'))],
            [$continued, $this->between($continued, $this->rowWhere($continued, 'A', '/^SPECIAL SKILLS/i') + 1, $this->rowWhere($continued, 'A', '/^SIGNATURE/i'))],
        ];
        $columns = ['skills' => 'A', 'distinctions' => 'C', 'memberships' => 'I'];

        $out = [];
        foreach ($columns as $section => $col) {
            $out[$section] = ['rows' => []];
            foreach ($blocks as [$sheet, $rows]) {
                foreach ($rows as $row) {
                    if (($value = $this->cell($sheet, $col, $row)) !== null) {
                        $out[$section]['rows'][] = ['detail' => $value];
                    }
                }
            }
        }

        return $out;
    }

    // ── Questions 34–40, references, ID ──────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function questions(): array
    {
        // question => the row holding its Yes (column H) / No (column J) boxes
        $rows = [
            'q34a' => 3, 'q34b' => 8, 'q35a' => 13, 'q35b' => 18, 'q36' => 23, 'q37' => 27,
            'q38a' => 31, 'q38b' => 34, 'q39' => 37, 'q40a' => 43, 'q40b' => 45, 'q40c' => 47,
        ];
        // Where each question's free-text answers live: from its own row up to the next.
        $blocks = [
            'q34_details' => [3, 12], 'q35a_details' => [13, 17], 'q35b_details' => [18, 22], 'q36_details' => [23, 26],
            'q37_details' => [27, 30], 'q38a_details' => [31, 33], 'q38b_details' => [34, 36], 'q39_country' => [37, 40],
            'q40a_group' => [43, 44], 'q40b_id' => [45, 46], 'q40c_id' => [47, 49],
        ];

        $answers = [];
        foreach ($rows as $key => $row) {
            $yes = $this->ticked('C4', 'H', $row);
            $no = $this->ticked('C4', 'J', $row);

            if ($yes === $no) {
                $this->warnings[] = 'Question '.strtoupper(substr($key, 1)).($yes ? ' has both Yes and No ticked' : ' has neither Yes nor No ticked').'; it was read as No.';
            }
            $answers[$key] = $yes && ! $no;
        }

        foreach ($blocks as $key => [$from, $to]) {
            $answers[$key] = $this->freeText($from, $to);
        }

        [$answers['q35b_date_filed'], $answers['q35b_case_status']] = [
            $this->date($this->labelled('Date Filed', 18, 22)),
            $this->labelled('Status of Case', 18, 24),
        ];

        return $answers;
    }

    /**
     * The first answer typed in rows $from–$to (columns H–N), skipping the
     * form's own prompts ("If YES, give details: ____").
     */
    private function freeText(int $from, int $to): ?string
    {
        foreach (range($from, $to) as $row) {
            foreach (range('H', 'N') as $col) {
                $text = $this->cell('C4', $col, $row);
                if ($text !== null && ! preg_match('/^[01]$/', $text) && ! $this->isPrompt($text)) {
                    return $text;
                }
            }
        }

        return null;
    }

    /**
     * The value beside a prompt such as "Date Filed:" (the next filled cell on its row).
     */
    private function labelled(string $label, int $from, int $to): ?string
    {
        foreach (range($from, $to) as $row) {
            $cells = $this->sheets['C4'][$row] ?? [];
            foreach ($cells as $col => $value) {
                if (stripos($value, $label) === 0) {
                    foreach ($cells as $next => $text) {
                        if (strcmp($next, $col) > 0 && strlen($next) === 1 && ($clean = $this->clean($text)) !== null && ! $this->isPrompt($clean)) {
                            return $clean;
                        }
                    }
                }
            }
        }

        return null;
    }

    private function isPrompt(string $text): bool
    {
        return (bool) preg_match('/^(if yes|date filed|status of case)|_{3,}|^(a|b)\. within/i', $text);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function references(): array
    {
        $rows = $this->between('C4', $this->rowWhere('C4', 'A', '/^NAME$/i') + 1, $this->rowWhere('C4', 'A', '/^42\./'));

        return $this->collect([['sheet' => 'C4', 'rows' => $rows, 'map' => null]], fn (string $sheet, int $row) => [
            'name' => $this->cell($sheet, 'A', $row),
            'address' => $this->cell($sheet, 'G', $row),
            'contact' => $this->cell($sheet, 'H', $row),
        ]);
    }

    /**
     * @return array<string, ?string>
     */
    private function governmentId(): array
    {
        return [
            'government_id_type' => $this->cell('C4', 'D', 61),
            'government_id_number' => $this->cell('C4', 'D', 62),
            'government_id_issuance' => $this->cell('C4', 'D', 64),
        ];
    }

    // ── Cell helpers ─────────────────────────────────────────────────

    /**
     * Rows of one repeating list across the form sheet and its
     * continuation, skipping rows with nothing in them. `$ignore` names
     * keys that don't count as content (a level label, a Y/N default).
     *
     * @param  array<int, array{sheet: ?string, rows: array<int, int>, map: ?array<int, string>}>  $sources
     * @param  array<int, string>  $ignore
     * @return array<int, array<string, mixed>>
     */
    private function collect(array $sources, callable $row, array $ignore = []): array
    {
        $out = [];

        foreach ($sources as $source) {
            if ($source['sheet'] === null) {
                continue;
            }
            foreach ($source['rows'] as $number) {
                $values = $row($source['sheet'], $number, $source['map']);
                $content = collect($values)->except($ignore)->filter(fn ($value) => $value !== null && $value !== '');

                if ($content->isNotEmpty()) {
                    $out[] = $values;
                }
            }
        }

        return $out;
    }

    private function findSheet(string $prefix): ?string
    {
        foreach (array_keys($this->sheets) as $name) {
            if ($name === $prefix || str_starts_with($name, $prefix.'_')) {
                return $name;
            }
        }

        return null;
    }

    /**
     * The first row in `$col` whose text matches (the end of the sheet when none does).
     */
    private function rowWhere(?string $sheet, string $col, string $pattern): int
    {
        return $this->rowsWhere($sheet, $col, $pattern)[0] ?? self::END;
    }

    /**
     * @return array<int, int>
     */
    private function rowsWhere(?string $sheet, string $col, string $pattern): array
    {
        if ($sheet === null) {
            return [];
        }

        return collect($this->sheets[$sheet] ?? [])
            ->filter(fn (array $cells) => isset($cells[$col]) && preg_match($pattern, $cells[$col]))
            ->keys()
            ->map(fn ($row) => (int) $row)
            ->values()
            ->all();
    }

    /**
     * Row numbers in `[$from, $to)` that exist in the sheet.
     *
     * @return array<int, int>
     */
    private function between(?string $sheet, int $from, int $to): array
    {
        if ($sheet === null || $from >= self::END) {
            return [];
        }

        return collect(array_keys($this->sheets[$sheet] ?? []))
            ->filter(fn ($row) => $row >= $from && $row < $to)
            ->map(fn ($row) => (int) $row)
            ->values()
            ->all();
    }

    private function raw(?string $sheet, string $col, int $row): ?string
    {
        return $sheet === null ? null : $this->clean($this->sheets[$sheet][$row][$col] ?? null);
    }

    /**
     * Text of a cell; blank and "N/A"-style fillers come back null.
     */
    private function cell(?string $sheet, string $col, int $row): ?string
    {
        return $this->raw($sheet, $col, $row);
    }

    private function clean(?string $value): ?string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value));

        return $value === '' || preg_match('/^(n\/?a|none|-+|—)$/i', $value) ? null : $value;
    }

    private function ticked(string $sheet, string $col, int $row): bool
    {
        return in_array(strtoupper((string) ($this->sheets[$sheet][$row][$col] ?? '')), ['1', 'TRUE'], true);
    }

    /**
     * @param  array<string, string>  $options  linked cell => value
     */
    private function pick(string $sheet, array $options, string $label): ?string
    {
        $chosen = [];
        foreach ($options as $address => $value) {
            if ($this->ticked($sheet, $address[0], (int) substr($address, 1))) {
                $chosen[] = $value;
            }
        }

        if (count($chosen) > 1) {
            $this->warnings[] = "More than one {$label} is ticked; the first was used.";
        }

        return $chosen[0] ?? null;
    }

    /**
     * A date as Y-m-d: an Excel date number, or text. Text with a slash
     * is day/month/year (as the form asks) unless the first part can't be
     * a month, or only the second part can't be one. "Present" and
     * anything unreadable return null / the raw text for validation to catch.
     */
    private function date(?string $value): ?string
    {
        if ($value === null || preg_match('/^(present|to date)$/i', $value)) {
            return null;
        }

        if (($serial = XlsxReader::excelDate($value)) !== null) {
            return $serial;
        }

        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $m)) {
            [$a, $b] = [(int) $m[1], (int) $m[2]];
            [$day, $month] = ($b > 12 && $a <= 12) ? [$b, $a] : [$a, $b];

            return checkdate($month, $day, (int) $m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $month, $day) : $value;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : $value;
    }

    private function year(?string $value): ?int
    {
        return $value !== null && preg_match('/^\d{4}$/', $value) ? (int) $value : null;
    }

    private function number(?string $value): int|float|string|null
    {
        if ($value === null) {
            return null;
        }

        $digits = str_replace([',', '₱', ' '], '', $value);
        if (preg_match('/^\d+(\.\d+)?/', $digits, $m)) {
            return str_contains($m[0], '.') ? (float) $m[0] : (int) $m[0];
        }

        return null;
    }
}
