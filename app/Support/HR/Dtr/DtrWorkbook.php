<?php

namespace App\Support\HR\Dtr;

use App\Models\BrandAsset;
use App\Support\Branding\Branding;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * DTR: Civil Service Form No. 48 as an Excel workbook, laid out cell for
 * cell like the printed form (the same 17-column grid, row heights, fonts
 * and borders as `HR/DtrPrint`), one sheet per employee and office served,
 * set to print on A4 portrait, one page each.
 *
 * Worked hours read as hours.minutes ("9.49" is 9 h 49 min), late and
 * undertime as hh:mm, and a zero as "0", as the printed form does. A signed
 * sheet carries the same QR code as the printed form (it opens the validation
 * page for the sheet's current signing), never the signature image: a
 * workbook can be edited, so it is not the signed copy. Without the GD
 * extension the QR cell falls back to the code and link as text.
 *
 * Written as plain OOXML (no spreadsheet package), like the leave workbooks.
 */
class DtrWorkbook
{
    /** Column widths of the form, in centimetres (A to Q). */
    private const COLUMNS = [1.108, 1.695, 0.432, 1.259, 1.259, 0.171, 0.28, 0.52, 0.716, 0.236, 0.215, 1.695, 0.716, 3.565, 3.935, 0.432, 1.977];

    public const SINGLE = 'single';

    public const DOUBLE = 'double';

    /** The double form's columns (the "two DTR in one paper" report), scaled to {@see DOUBLE_FORM_WIDTH} cm. */
    private const DOUBLE_COLUMNS = [4.1, 8.35, 2.73, 1.25, 3.11, 1.24, 2.84, 3.43, 2.05, 0.97, 4.09, 3.28, 0.66, 6.85, 3.96, 1.23, 5.22];

    private const DOUBLE_FORM_WIDTH = 10.15;

    /** How many of the 17 columns each cell spans, as `HR/DtrPrint` has them. */
    private const SPANS = [
        'single' => [
            'title' => 5, 'logo' => 5, 'agency' => 7, 'blank' => 5, 'formNo' => 7, 'nameLabel' => 5, 'nameValue' => 12,
            'refLabel' => 5, 'refText' => 9, 'countLabel' => 2, 'periodLabel' => 5, 'periodText' => 9,
            'morning' => 3, 'afternoon' => 7, 'worked' => 1, 'remarks' => 5, 'breakOut' => 2, 'breakIn' => 3, 'timeOut' => 4,
            'totalLabel' => 9, 'totalValue' => 4, 'totalRightLabel' => 2, 'totalRightValue' => 2,
            'qrLabel' => 3, 'signLabel' => 5, 'signature' => 9, 'head' => 14, 'qr' => 3, 'footLabel' => 3, 'footValue' => 11, 'footer' => 14,
        ],
        'double' => [
            'title' => 3, 'logo' => 4, 'agency' => 10, 'blank' => 4, 'formNo' => 10, 'nameLabel' => 3, 'nameValue' => 14,
            'refLabel' => 3, 'refText' => 10, 'countLabel' => 3, 'periodLabel' => 3, 'periodText' => 10,
            'morning' => 5, 'afternoon' => 6, 'worked' => 2, 'remarks' => 3, 'breakOut' => 4, 'breakIn' => 3, 'timeOut' => 3,
            'totalLabel' => 5, 'totalValue' => 3, 'totalRightLabel' => 7, 'totalRightValue' => 2,
            'qrLabel' => 4, 'signLabel' => 7, 'signature' => 6, 'head' => 13, 'qr' => 4, 'footLabel' => 6, 'footValue' => 7, 'footer' => 13,
        ],
    ];

    /** Edges as the template draws them: l, r, t, b; `T` is the heavy top edge above the certification. */
    private const ALL = 'lrtb';

    /** @var list<array{name: string, cells: array<int, array<int, array{0: string|int, 1: int}>>, merges: list<string>, heights: array<int, float>}> */
    private array $sheets = [];

    /** @var array<string, int> */
    private array $styles = [];

    /** @var list<array{size: float, bold: bool, italic: bool, h: string, v: string, wrap: bool, edges: string}> */
    private array $styleList = [];

    /** @var array<string, int> */
    private array $usedNames = [];

    /** The agency logo (PNG bytes) drawn in the header, as on the printed form. */
    private ?string $logo = null;

    /** The same logo faded to 12%, drawn behind the form(s) as the print does (null without the GD extension). */
    private ?string $watermark = null;

    /**
     * @param  list<array{employee: string, sheet: array<string, mixed>}>  $entries
     * @param  string  $layout  `single`: one form per sheet; `double`: two identical, narrower forms side by side, to cut down the middle
     * @param  string  $printedBy  who the double form says printed it
     */
    public function build(array $entries, string $generatedAt, string $layout = self::SINGLE, string $printedBy = ''): string
    {
        $this->sheets = [];
        $this->styles = [];
        $this->styleList = [];
        $this->usedNames = [];
        $layout = $layout === self::DOUBLE ? self::DOUBLE : self::SINGLE;
        $this->logo = $this->logoBytes();
        $this->watermark = $this->fadedLogo();
        // The default font sets the column unit (a 10 pt Arial digit is 7 px), which the widths below assume.
        $this->style(10, false, false, 'left', 'center', false, '');

        foreach ($entries as $entry) {
            $this->addSheet($entry['employee'], $entry['sheet'], $generatedAt, $layout, $printedBy);
        }

        if ($this->sheets === []) {
            throw new RuntimeException('There is no DTR to export.');
        }

        return $this->zip();
    }

    /**
     * @param  array<string, mixed>  $sheet
     */
    private function addSheet(string $employee, array $sheet, string $generatedAt, string $layout, string $printedBy): void
    {
        $double = $layout === self::DOUBLE;
        $name = $this->sheetName($employee, (string) ($sheet['period'] ?? ''));
        $grid = new class
        {
            /** @var array<int, array<int, array{0: string|int, 1: int}>> */
            public array $cells = [];

            /** @var list<string> */
            public array $merges = [];

            /** @var array<int, float> */
            public array $heights = [];
        };

        $height = function (int $row, float $cm) use ($grid) {
            $grid->heights[$row] = round($cm * 28.3465, 2);
        };

        $qr = $this->qrPng($sheet['signature']['verify_url'] ?? null);
        if ($qr !== null) {
            $sheet['qr_drawn'] = true;
        }

        $columns = $double ? self::doubleColumns() : self::COLUMNS;
        $copies = $double ? 2 : 1;
        $g = self::SPANS[$double ? self::DOUBLE : self::SINGLE];

        foreach (range(0, $copies - 1) as $copy) {
            // The second form starts after the first and the gap between them.
            $shift = $copy * (count(self::COLUMNS) + 1);
            $this->form($grid, $shift, $g, $double, $employee, $sheet, $generatedAt, $printedBy);
        }

        foreach ($this->rowHeights($double) as $row => $cm) {
            $height($row, $cm);
        }

        $this->sheets[] = [
            'name' => $name,
            'cells' => $grid->cells,
            'merges' => $grid->merges,
            'heights' => $grid->heights,
            'layout' => $layout,
            'columns' => $copies === 2 ? [...$columns, 0.2, ...$columns] : $columns,
            'last_row' => $double ? 54 : 53,
            'qr' => $qr,
        ];
    }

    /**
     * The row heights of the form in cm, as `HR/DtrPrint` sets them. The
     * double form has one more row ("Printed by:").
     *
     * @return array<int, float>
     */
    private function rowHeights(bool $double): array
    {
        $heights = [1 => 0.529, 2 => 0.492, 3 => 0.46, 4 => 0.492, 5 => 0.704, 6 => 0.598, 7 => 0.492, 8 => 0.566, 9 => 0.423, 10 => 0.423];
        for ($row = 11; $row <= 45; $row++) {
            $heights[$row] = 0.423;
        }

        return $heights + [46 => 0.99, 47 => 0.884, 48 => 0.143, 49 => 0.318, 50 => 0.99, 51 => 0.318, 52 => 0.386]
            + ($double ? [53 => 0.386, 54 => 0.423] : [53 => 0.423]);
    }

    /**
     * One copy of the form, laid out like the printed table: the same rows,
     * with each cell spanning as many of the 17 columns as `$g` says.
     *
     * @param  array<string, int>  $g
     * @param  array<string, mixed>  $sheet
     */
    private function form(object $grid, int $shift, array $g, bool $double, string $employee, array $sheet, string $generatedAt, string $printedBy): void
    {
        $box = function (int $row, int $col, int $rows, int $cols, string|int|array $value, int $style) use ($grid, $shift) {
            $col += $shift;
            for ($r = $row; $r < $row + $rows; $r++) {
                for ($c = $col; $c < $col + $cols; $c++) {
                    $grid->cells[$r][$c] = [($r === $row && $c === $col) ? $value : '', $style];
                }
            }
            if ($rows > 1 || $cols > 1) {
                $grid->merges[] = $this->ref($row, $col).':'.$this->ref($row + $rows - 1, $col + $cols - 1);
            }
        };
        $s = fn (float $size, bool $bold, bool $italic, string $h, bool $wrap, string $edges, string $v = 'center', string $font = 'Arial') => $this->style($size, $bold, $italic, $h, $v, $wrap, $edges, $font);
        $totals = $sheet['totals'];

        // Title, logo space and agency.
        $logoAt = 1 + $g['title'];
        $agencyAt = $logoAt + $g['logo'];
        $box(1, 1, 4, $g['title'], 'DAILY TIME RECORD', $double
            ? $s(12, true, false, 'center', true, self::ALL, 'center', 'Microsoft Sans Serif')
            : $s(13, true, false, 'center', false, self::ALL, 'center', 'Microsoft Sans Serif'));
        $box(1, $logoAt, 3, $g['logo'], '', $s(8, false, false, 'center', false, 't'));
        $box(1, $agencyAt, 3, $g['agency'], [
            ['t' => "Republic of the Philippines\n", 'size' => 8, 'bold' => false],
            ['t' => "CIVIL SERVICE COMMISSION\n", 'size' => 9, 'bold' => true],
            ['t' => 'CSC Building, IBP Road, Constitution Hills, Diliman, 1126 Quezon City', 'size' => 6, 'bold' => false],
        ], $s(8, false, false, 'left', true, 'tr'));
        $box(4, $logoAt, 1, $g['blank'], '', $s(8, false, false, 'left', false, ''));
        $box(4, $logoAt + $g['blank'], 1, $g['formNo'], 'Civil Service Form No. 48', $s(8, true, false, 'right', false, 'r'));

        // Employee, time reference, period and the day counts.
        $countAt = 17;
        $box(5, 1, 1, $g['nameLabel'], "Employee's Name", $s(8.5, false, false, 'right', false, self::ALL));
        $box(5, 1 + $g['nameLabel'], 1, $g['nameValue'], mb_strtoupper($employee), $s(11, true, false, 'left', false, self::ALL));
        $box(6, 1, 2, $g['refLabel'], 'Time Reference', $s(9, false, false, 'right', false, self::ALL));
        $box(6, 1 + $g['refLabel'], 2, $g['refText'], $sheet['schedule'] ?? 'No work schedule assigned', $s($double ? 8 : 10, false, false, 'left', true, self::ALL));
        $labelAt = 1 + $g['refLabel'] + $g['refText'];
        $box(6, $labelAt, 1, $g['countLabel'], 'Regular Days', $s(9, false, false, 'right', false, self::ALL));
        $box(6, $countAt, 1, 1, (int) $totals['regular_days'], $s(10, false, false, 'center', false, self::ALL));
        $box(7, $labelAt, 1, $g['countLabel'], 'Weekends', $s(9, false, false, 'right', false, self::ALL));
        $box(7, $countAt, 1, 1, (int) $totals['weekend_days'], $s(10, false, false, 'center', false, self::ALL));
        $box(8, 1, 1, $g['periodLabel'], 'For the period of', $s(9, false, false, 'right', false, self::ALL));
        $box(8, 1 + $g['periodLabel'], 1, $g['periodText'], (string) $sheet['period'], $s(10, true, false, 'left', false, self::ALL));
        $box(8, $labelAt, 1, $g['countLabel'], 'Holiday', $s(9, false, false, 'right', false, self::ALL));
        $box(8, $countAt, 1, 1, (int) $totals['holiday_days'], $s(10, false, false, 'center', false, self::ALL));

        // Column headings.
        $morningEnd = 2 + $g['morning'] - 1;
        $afternoonAt = $morningEnd + 1;
        $workedAt = $afternoonAt + $g['afternoon'];
        $remarksAt = $workedAt + $g['worked'];
        $box(9, 1, 2, 1, 'Day', $s(10, true, false, 'center', false, self::ALL));
        $box(9, 2, 1, $g['morning'], 'MORNING', $s(10, true, false, 'center', false, self::ALL));
        $box(9, $afternoonAt, 1, $g['afternoon'], 'AFTERNOON', $s(10, true, false, 'center', false, self::ALL));
        $box(9, $workedAt, 2, $g['worked'], 'Worked Hrs.', $s(8, true, false, 'center', true, self::ALL));
        $box(9, $remarksAt, 2, $g['remarks'], 'Remarks', $s(10, true, false, 'center', false, self::ALL));
        $breakOutAt = 3;
        $breakInAt = $breakOutAt + $g['breakOut'];
        $timeOutAt = $breakInAt + $g['breakIn'];
        $box(10, 2, 1, 1, 'Time-in', $s(8.5, false, false, 'center', false, self::ALL));
        $box(10, $breakOutAt, 1, $g['breakOut'], 'Break-out', $s(8.5, false, false, 'center', false, self::ALL));
        $box(10, $breakInAt, 1, $g['breakIn'], 'Break-in', $s(8.5, false, false, 'center', false, self::ALL));
        $box(10, $timeOutAt, 1, $g['timeOut'], 'Time-out', $s(8.5, false, false, 'center', false, self::ALL));

        // Days 1 to 31, as on the form.
        $byDay = [];
        foreach ($sheet['rows'] as $row) {
            $byDay[(int) substr((string) $row['date'], 8)] = $row;
        }
        $cell = $s(9, false, false, 'center', false, self::ALL);
        $remark = $s(7.5, false, false, 'center', false, self::ALL);
        for ($day = 1; $day <= 31; $day++) {
            $r = 10 + $day;
            $row = $byDay[$day] ?? null;
            $box($r, 1, 1, 1, $day, $cell);
            $box($r, 2, 1, 1, $this->clock($row['in'] ?? null), $cell);
            $box($r, $breakOutAt, 1, $g['breakOut'], $this->clock($row['break_out'] ?? null), $cell);
            $box($r, $breakInAt, 1, $g['breakIn'], $this->clock($row['break_in'] ?? null), $cell);
            $box($r, $timeOutAt, 1, $g['timeOut'], $this->clock($row['out'] ?? null), $cell);
            $box($r, $workedAt, 1, $g['worked'], $this->worked($row['minutes'] ?? 0), $cell);
            $box($r, $remarksAt, 1, $g['remarks'], (string) ($row['remark'] ?? ''), $remark);
        }

        // Totals.
        $left = [
            ['Total hours work', $this->worked($totals['minutes']) ?: '0'],
            ['Total hours late', $this->hoursMinutes($totals['late_minutes'])],
            ['Total hours undertime', $this->hoursMinutes($totals['undertime_minutes'])],
            ['Total no. of absences', (int) $totals['absences']],
        ];
        $right = [
            ['Total no. of days travelled', (int) $totals['travel_days']],
            ['Total no. of days on-leave', (int) $totals['leave_days']],
            ['Total no. of undertime', (int) $totals['undertime_count']],
            ['Total no. of late', (int) $totals['late_count']],
        ];
        $valueAt = 1 + $g['totalLabel'];
        $rightLabelAt = $valueAt + $g['totalValue'];
        $rightValueAt = $rightLabelAt + $g['totalRightLabel'];
        foreach ($left as $index => [$label, $value]) {
            $r = 42 + $index;
            $box($r, 1, 1, $g['totalLabel'], $label, $s(9, true, false, 'right', false, self::ALL));
            $box($r, $valueAt, 1, $g['totalValue'], $value, $s(10, $index === 0, false, 'left', false, self::ALL));
            $box($r, $rightLabelAt, 1, $g['totalRightLabel'], $right[$index][0], $s(9, true, false, 'right', false, self::ALL));
            $box($r, $rightValueAt, 1, $g['totalRightValue'], $right[$index][1], $s(10, false, false, 'left', false, self::ALL));
        }

        // Certification.
        $box(46, 1, 1, 17, 'I CERTIFY on my honor that the above is true and correct report of the hours of work performed, record or which was made daily at the time of arrival and at departure from office', $s(7.5, false, false, 'left', true, 'lrbT'));

        // Signatures, the validation code and the footer.
        $signature = $sheet['signature'] ?? null;
        $headAt = 1 + $g['qr'];
        $signLabelAt = 1 + $g['qrLabel'];
        $box(47, 1, 2, $g['qrLabel'], 'Use this code to validate the authenticity of this document', $s(7.5, false, false, 'center', true, self::ALL));
        $box(47, $signLabelAt, 1, $g['signLabel'], "Employee's Signature", $s(8.5, false, false, 'left', false, self::ALL));
        $box(47, $signLabelAt + $g['signLabel'], 1, $g['signature'], $this->signatureNote($signature), $s(9, true, false, 'center', true, self::ALL));
        $box(48, $headAt, 2, $g['head'], 'Office Head Name/Signature', $s(8.5, false, false, 'left', false, self::ALL));
        $box(49, 1, $double ? 6 : 5, $g['qr'], $signature && ! ($sheet['qr_drawn'] ?? false) ? "Code\n{$signature['code']}\n\n{$signature['verify_url']}" : '', $s(7, false, false, 'center', true, self::ALL));
        $box(50, $headAt, 1, $g['head'], mb_strtoupper((string) ($sheet['head'] ?? '')), $s(11, true, false, 'center', false, self::ALL, 'bottom'));
        $box(51, $headAt, 1, $g['head'], 'Verified as to the prescribed office hours', $s(8, false, true, 'center', false, self::ALL));
        $box(52, $headAt, 1, $g['footLabel'], 'Generated Date:', $s(8.5, false, false, 'left', false, self::ALL));
        $box(52, $headAt + $g['footLabel'], 1, $g['footValue'], $generatedAt, $s(8.5, false, false, 'left', false, self::ALL));
        if ($double) {
            $box(53, $headAt, 1, $g['footLabel'], 'Printed by:', $s(8.5, false, false, 'left', false, self::ALL));
            $box(53, $headAt + $g['footLabel'], 1, $g['footValue'], $printedBy, $s(7.5, false, false, 'left', false, self::ALL));
        }
        $box($double ? 54 : 53, $headAt, 1, $g['footer'], 'This is a computer generated document.', $s(8.5, false, false, 'left', false, self::ALL));
    }

    /**
     * @param  array{code: string, signed_at: string, status: string}|null  $signature
     */
    private function signatureNote(?array $signature): string|array
    {
        if (! $signature) {
            return '';
        }

        if ($signature['status'] === 'changed') {
            return [['t' => 'CHANGED SINCE THE EMPLOYEE SIGNED IT. Print the current sheet and sign again.', 'size' => 6.5, 'bold' => true, 'color' => 'FFB00020']];
        }

        return 'E-signed '.date('F j, Y', (int) strtotime($signature['signed_at'])).' · Code '.$signature['code'];
    }

    private function clock(?string $value): string
    {
        if (! $value) {
            return '';
        }

        [$hour, $minute] = array_map('intval', explode(':', $value));

        return ($hour % 12 ?: 12).':'.str_pad((string) $minute, 2, '0', STR_PAD_LEFT).($hour < 12 ? 'AM' : 'PM');
    }

    private function worked(int $minutes): string
    {
        return $minutes ? intdiv($minutes, 60).'.'.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT) : '';
    }

    private function hoursMinutes(int $minutes): string
    {
        return $minutes ? str_pad((string) intdiv($minutes, 60), 2, '0', STR_PAD_LEFT).':'.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT) : '0';
    }

    private function sheetName(string $employee, string $period): string
    {
        $base = trim(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $employee)) ?: 'DTR';
        $base = mb_substr($base, 0, 31);
        $count = $this->usedNames[mb_strtolower($base)] = ($this->usedNames[mb_strtolower($base)] ?? 0) + 1;

        return $count === 1 ? $base : mb_substr($base, 0, 27).' ('.$count.')';
    }

    private function style(float $size, bool $bold, bool $italic, string $h, string $v, bool $wrap, string $edges, string $font = 'Arial'): int
    {
        $key = implode('|', [$size, (int) $bold, (int) $italic, $h, $v, (int) $wrap, $edges, $font]);
        if (! isset($this->styles[$key])) {
            $this->styles[$key] = count($this->styleList);
            $this->styleList[] = compact('size', 'bold', 'italic', 'h', 'v', 'wrap', 'edges', 'font');
        }

        return $this->styles[$key];
    }

    /**
     * Rich text: runs of `t` (text), `size`, `bold` and an optional ARGB `color`.
     *
     * @param  list<array<string, mixed>>  $runs
     */
    private function runs(array $runs): string
    {
        $xml = '';
        foreach ($runs as $run) {
            $color = isset($run['color']) ? "<color rgb=\"{$run['color']}\"/>" : '';
            $xml .= '<r><rPr>'.($run['bold'] ? '<b/>' : '')."<sz val=\"{$run['size']}\"/>{$color}".'<rFont val="Arial"/></rPr><t xml:space="preserve">'.$this->escape($run['t']).'</t></r>';
        }

        return $xml;
    }

    /** Cell address: columns run A to Z, then AA and on. */
    private function ref(int $row, int $col): string
    {
        $letters = '';
        for ($n = $col; $n > 0; $n = intdiv($n - 1, 26)) {
            $letters = chr(65 + ($n - 1) % 26).$letters;
        }

        return $letters.$row;
    }

    /**
     * The double form's 17 column widths in cm: the report's widths scaled so the form is 10.15 cm wide.
     *
     * @return list<float>
     */
    private static function doubleColumns(): array
    {
        $total = array_sum(self::DOUBLE_COLUMNS);

        return array_map(fn (float $width) => round($width * self::DOUBLE_FORM_WIDTH / $total, 4), self::DOUBLE_COLUMNS);
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function zip(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dtr');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);

        $overrides = '<Default Extension="png" ContentType="image/png"/>';
        if ($this->logo !== null) {
            $zip->addFromString('xl/media/logo.png', $this->logo);
        }
        if ($this->watermark !== null) {
            $zip->addFromString('xl/media/watermark.png', $this->watermark);
        }
        $sheetEntries = '';
        $rels = '';
        foreach ($this->sheets as $index => $sheet) {
            $n = $index + 1;
            $overrides .= "<Override PartName=\"/xl/worksheets/sheet{$n}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
            $sheetEntries .= '<sheet name="'.$this->escape($sheet['name'])."\" sheetId=\"{$n}\" r:id=\"rId{$n}\"/>";
            $rels .= "<Relationship Id=\"rId{$n}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet{$n}.xml\"/>";
            $zip->addFromString("xl/worksheets/sheet{$n}.xml", $this->sheetXml($sheet));
            if ($sheet['qr'] !== null) {
                $zip->addFromString("xl/media/qr{$n}.png", $sheet['qr']);
            }
            if ($this->logo !== null || $sheet['qr'] !== null) {
                $overrides .= "<Override PartName=\"/xl/drawings/drawing{$n}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.drawing+xml\"/>";
                $zip->addFromString("xl/worksheets/_rels/sheet{$n}.xml.rels", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing'.$n.'.xml"/></Relationships>');
                $zip->addFromString("xl/drawings/drawing{$n}.xml", $this->drawingXml($sheet));
                $image = fn (string $id, string $target) => "<Relationship Id=\"{$id}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/image\" Target=\"{$target}\"/>";
                $zip->addFromString("xl/drawings/_rels/drawing{$n}.xml.rels", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                    .($this->logo !== null ? $image('rId1', '../media/logo.png') : '')
                    .($this->watermark !== null ? $image('rId2', '../media/watermark.png') : '')
                    .($sheet['qr'] !== null ? $image('rId3', "../media/qr{$n}.png") : '')
                    .'</Relationships>');
            }
        }
        $styleRel = count($this->sheets) + 1;

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'.$overrides.'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView/></bookViews><sheets>'.$sheetEntries.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels."<Relationship Id=\"rId{$styleRel}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles\" Target=\"styles.xml\"/></Relationships>");
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        $zip->close();

        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /**
     * @param  array{cells: array<int, array<int, array{0: string|int, 1: int}>>, merges: list<string>, heights: array<int, float>, layout: string, columns: list<float>, last_row: int}  $sheet
     */
    private function sheetXml(array $sheet): string
    {
        $cols = '';
        foreach ($sheet['columns'] as $index => $cm) {
            $n = $index + 1;
            $cols .= "<col min=\"{$n}\" max=\"{$n}\" width=\"".round($cm * 37.795 / 7, 2).'" customWidth="1"/>';
        }

        $rows = '';
        ksort($sheet['cells']);
        foreach ($sheet['cells'] as $r => $cells) {
            ksort($cells);
            $xml = '';
            foreach ($cells as $c => [$value, $style]) {
                $ref = $this->ref($r, $c);
                if (is_int($value)) {
                    $xml .= "<c r=\"{$ref}\" s=\"{$style}\"><v>{$value}</v></c>";
                } elseif (is_array($value)) {
                    $xml .= "<c r=\"{$ref}\" s=\"{$style}\" t=\"inlineStr\"><is>".$this->runs($value).'</is></c>';
                } elseif ($value === '') {
                    $xml .= "<c r=\"{$ref}\" s=\"{$style}\"/>";
                } else {
                    $xml .= "<c r=\"{$ref}\" s=\"{$style}\" t=\"inlineStr\"><is><t xml:space=\"preserve\">".$this->escape($value).'</t></is></c>';
                }
            }
            $height = isset($sheet['heights'][$r]) ? " ht=\"{$sheet['heights'][$r]}\" customHeight=\"1\"" : '';
            $rows .= "<row r=\"{$r}\"{$height}>{$xml}</row>";
        }

        $merges = '<mergeCells count="'.count($sheet['merges']).'">'.implode('', array_map(fn (string $range) => "<mergeCell ref=\"{$range}\"/>", $sheet['merges'])).'</mergeCells>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'.($sheet['layout'] === self::DOUBLE ? '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>' : '').'<dimension ref="A1:'.$this->ref($sheet['last_row'], count($sheet['columns'])).'"/><sheetViews><sheetView showGridLines="0" workbookViewId="0"/></sheetViews><sheetFormatPr defaultRowHeight="15"/><cols>'.$cols.'</cols><sheetData>'.$rows.'</sheetData>'.$merges.'<printOptions horizontalCentered="1"/>'.($sheet['layout'] === self::DOUBLE ? '<pageMargins left="0.1" right="0.1" top="0.2" bottom="0.2" header="0" footer="0"/><pageSetup paperSize="9" fitToWidth="1" fitToHeight="1" orientation="portrait"/>' : '<pageMargins left="0.2" right="0.2" top="0.2" bottom="0.2" header="0" footer="0"/><pageSetup paperSize="9" scale="97" orientation="portrait"/>').''.($this->logo !== null || $sheet['qr'] !== null ? '<drawing r:id="rId1"/>' : '').'</worksheet>';
    }

    /**
     * The drawing of one sheet: the agency logo in each form's header box
     * (centred and a little low, 1.711 × 1.482 cm, as on the printed form)
     * and, on a double DTR, the logo faded behind both forms.
     *
     * @param  array{layout: string, columns: list<float>, qr: ?string}  $sheet
     */
    private function drawingXml(array $sheet): string
    {
        $double = $sheet['layout'] === self::DOUBLE;
        $pics = '';

        if ($this->logo === null) {
            // No agency logo to draw: only the QR, below.
        } elseif ($double) {
            $form = self::doubleColumns();
            $title = array_sum(array_slice($form, 0, self::SPANS['double']['title']));
            $logoWidth = array_sum(array_slice($form, self::SPANS['double']['title'], self::SPANS['double']['logo']));
            $formWidth = array_sum($form) + 0.2;
            foreach ([0, 1] as $copy) {
                [$col, $offset] = $this->anchorAt($sheet['columns'], $copy * $formWidth + $title + ($logoWidth - 1.711) / 2);
                $pics .= $this->picture(2 + $copy, 'rId1', "<xdr:oneCellAnchor><xdr:from><xdr:col>{$col}</xdr:col><xdr:colOff>{$offset}</xdr:colOff><xdr:row>0</xdr:row><xdr:rowOff>36000</xdr:rowOff></xdr:from><xdr:ext cx=\"615960\" cy=\"533520\"/>", 615960, 533520, '</xdr:oneCellAnchor>');
            }
        } else {
            $pics .= $this->picture(2, 'rId1', '<xdr:oneCellAnchor><xdr:from><xdr:col>5</xdr:col><xdr:colOff>38160</xdr:colOff><xdr:row>0</xdr:row><xdr:rowOff>36000</xdr:rowOff></xdr:from><xdr:ext cx="615960" cy="533520"/>', 615960, 533520, '</xdr:oneCellAnchor>');
        }

        if ($this->watermark !== null) {
            $pics .= $this->watermarkPicture($double);
        }

        if ($sheet['qr'] !== null) {
            $pics .= $this->qrPictures($sheet, $double);
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'.$pics.'</xdr:wsDr>';
    }

    /**
     * The faded logo behind the form(s), 16.5 cm wide, centred on the page and 5.4 cm down it, as
     * `.watermark` in HR/DtrPrint. The sheet is measured from its own corner: 0.25 cm in and 0.508 cm
     * down on a double DTR; on a single DTR (printed at 97%, centred) from where the form starts, in
     * the sheet's own unscaled units.
     */
    private function watermarkPicture(bool $double): string
    {
        $scale = $double ? 1.0 : 0.97;
        $left = $double ? 2.0 : (2.25 - (0.508 + (21.001 - 2 * 0.508 - array_sum(self::COLUMNS) * 0.97) / 2)) / $scale;
        $top = (5.4 - 0.508) / $scale;
        $width = (int) round(16.5 / $scale * 360000);
        $height = (int) round(16.5 / $scale * $this->aspect((string) $this->watermark) * 360000);

        return $this->picture(4, 'rId2', '<xdr:absoluteAnchor><xdr:pos x="'.(int) round($left * 360000).'" y="'.(int) round($top * 360000).'"/><xdr:ext cx="'.$width.'" cy="'.$height.'"/>', $width, $height, '</xdr:absoluteAnchor>', 'Faded agency logo');
    }

    /**
     * The QR in each form's QR cell, 2.3 cm square and centred in it, as on the print.
     *
     * @param  array{columns: list<float>}  $sheet
     */
    private function qrPictures(array $sheet, bool $double): string
    {
        $span = self::SPANS[$double ? self::DOUBLE : self::SINGLE]['qr'];
        $form = $double ? self::doubleColumns() : self::COLUMNS;
        $cellWidth = array_sum(array_slice($form, 0, $span));
        $heights = $this->rowHeights($double);
        $cellHeight = array_sum(array_slice($heights, 48, $double ? 6 : 5, true));
        $size = 2.3;
        $above = (int) round(($cellHeight - $size) / 2 * 360000);
        $edge = (int) round($size * 360000);
        $formWidth = array_sum($form) + 0.2;
        $pics = '';

        foreach (range(0, $double ? 1 : 0) as $copy) {
            [$col, $offset] = $this->anchorAt($sheet['columns'], $copy * $formWidth + ($cellWidth - $size) / 2);
            $pics .= $this->picture(10 + $copy, 'rId3', "<xdr:oneCellAnchor><xdr:from><xdr:col>{$col}</xdr:col><xdr:colOff>{$offset}</xdr:colOff><xdr:row>48</xdr:row><xdr:rowOff>{$above}</xdr:rowOff></xdr:from><xdr:ext cx=\"{$edge}\" cy=\"{$edge}\"/>", $edge, $edge, '</xdr:oneCellAnchor>', 'QR code');
        }

        return $pics;
    }

    /**
     * The QR for the validation link as a PNG, or null without a link or GD.
     */
    private function qrPng(?string $url): ?string
    {
        if (! $url || ! function_exists('imagecreate') || ! class_exists(QRCode::class)) {
            return null;
        }

        try {
            return (new QRCode(new QROptions([
                'outputInterface' => QRGdImagePNG::class,
                'outputBase64' => false,
                'eccLevel' => EccLevel::M,
                'scale' => 8,
                'addQuietzone' => false,
            ])))->render($url);
        } catch (Throwable) {
            return null;
        }
    }

    private function picture(int $id, string $rel, string $anchorOpen, int $cx, int $cy, string $anchorClose, string $name = 'Agency logo'): string
    {
        return $anchorOpen
            ."<xdr:pic><xdr:nvPicPr><xdr:cNvPr id=\"{$id}\" name=\"{$name}\" descr=\"{$name}\"/><xdr:cNvPicPr><a:picLocks noChangeAspect=\"1\"/></xdr:cNvPicPr></xdr:nvPicPr>"
            ."<xdr:blipFill><a:blip r:embed=\"{$rel}\"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>"
            ."<xdr:spPr><a:xfrm><a:off x=\"0\" y=\"0\"/><a:ext cx=\"{$cx}\" cy=\"{$cy}\"/></a:xfrm><a:prstGeom prst=\"rect\"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic><xdr:clientData/>"
            .$anchorClose;
    }

    /**
     * The column and the offset inside it (EMU) at `$x` cm from the sheet's left edge.
     *
     * @param  list<float>  $columns  widths in cm
     * @return array{0: int, 1: int}
     */
    private function anchorAt(array $columns, float $x): array
    {
        foreach ($columns as $index => $width) {
            if ($x < $width || $index === count($columns) - 1) {
                return [$index, (int) round($x * 360000)];
            }
            $x -= $width;
        }

        return [0, 0];
    }

    /** Height over width of a PNG. */
    private function aspect(string $png): float
    {
        $size = @getimagesizefromstring($png);

        return $size && $size[0] > 0 ? $size[1] / $size[0] : 1.0;
    }

    /**
     * The logo at 12% opacity, like the print's watermark, or null when the
     * GD extension is not there (the double form is then drawn without it).
     */
    private function fadedLogo(): ?string
    {
        if ($this->logo === null || ! function_exists('imagecreatefromstring')) {
            return null;
        }

        $source = @imagecreatefromstring($this->logo);
        if ($source === false) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, 600 / max(1, $width));
        $target = imagecreatetruecolor((int) max(1, round($width * $scale)), (int) max(1, round($height * $scale)));
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $source, 0, 0, 0, 0, imagesx($target), imagesy($target), $width, $height);

        for ($y = 0; $y < imagesy($target); $y++) {
            for ($x = 0; $x < imagesx($target); $x++) {
                $color = imagecolorat($target, $x, $y);
                $alpha = ($color >> 24) & 0x7F;
                $faded = 127 - (int) round((127 - $alpha) * 0.12);
                imagesetpixel($target, $x, $y, ($faded << 24) | ($color & 0xFFFFFF));
            }
        }

        ob_start();
        imagepng($target);

        return (string) ob_get_clean();
    }

    /** The agency logo from Admin → Branding when it is a PNG, else the default one. */
    private function logoBytes(): ?string
    {
        $saved = BrandAsset::query()->where('key', 'logo')->value('path');
        $file = $saved && Storage::disk('public')->exists($saved) ? Storage::disk('public')->path($saved) : null;

        if (! $file || (@getimagesize($file)[2] ?? null) !== IMAGETYPE_PNG) {
            $file = public_path(Branding::ASSETS['logo']['default']);
        }

        if (! is_file($file) || (@getimagesize($file)[2] ?? null) !== IMAGETYPE_PNG) {
            return null;
        }

        return (string) file_get_contents($file);
    }

    private function stylesXml(): string
    {
        $fonts = '';
        $borders = '';
        $xfs = '';
        $borderIds = [];

        foreach ($this->styleList as $index => $style) {
            $fonts .= '<font>'.($style['bold'] ? '<b/>' : '').($style['italic'] ? '<i/>' : '')."<sz val=\"{$style['size']}\"/><name val=\"{$style['font']}\"/></font>";

            if (! isset($borderIds[$style['edges']])) {
                $borderIds[$style['edges']] = count($borderIds);
                $edge = fn (string $side, string $letter) => str_contains($style['edges'], $letter) ? "<{$side} style=\"thin\"><color auto=\"1\"/></{$side}>" : "<{$side}/>";
                $top = str_contains($style['edges'], 'T') ? '<top style="thick"><color auto="1"/></top>' : $edge('top', 't');
                $borders .= '<border>'.$edge('left', 'l').$edge('right', 'r').$top.$edge('bottom', 'b').'<diagonal/></border>';
            }

            $wrap = $style['wrap'] ? ' wrapText="1"' : '';
            $xfs .= "<xf numFmtId=\"0\" fontId=\"{$index}\" fillId=\"0\" borderId=\"{$borderIds[$style['edges']]}\" xfId=\"0\" applyFont=\"1\" applyBorder=\"1\" applyAlignment=\"1\"><alignment horizontal=\"{$style['h']}\" vertical=\"{$style['v']}\"{$wrap}/></xf>";
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="'.count($this->styleList).'">'.$fonts.'</fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="'.count($borderIds).'">'.$borders.'</borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="'.count($this->styleList).'">'.$xfs.'</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }
}
