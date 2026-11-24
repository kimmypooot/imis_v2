<?php

namespace App\Support\HR\Dtr;

use App\Models\BrandAsset;
use App\Support\Branding\Branding;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * DTR: Civil Service Form No. 48 as an Excel workbook, laid out cell for
 * cell like the printed form (the same 17-column grid, row heights, fonts
 * and borders as `HR/DtrPrint`), one sheet per employee and office served,
 * set to print on A4 portrait, one page each.
 *
 * Worked hours read as hours.minutes ("9.49" is 9 h 49 min), late and
 * undertime as hh:mm, and a zero as "0", as the printed form does. A signed
 * sheet carries its validation code and link, never the signature image: a
 * workbook can be edited, so it is not the signed copy.
 *
 * Written as plain OOXML (no spreadsheet package), like the leave workbooks.
 */
class DtrWorkbook
{
    /** Column widths of the form, in centimetres (A to Q). */
    private const COLUMNS = [1.108, 1.695, 0.432, 1.259, 1.259, 0.171, 0.28, 0.52, 0.716, 0.236, 0.215, 1.695, 0.716, 3.565, 3.935, 0.432, 1.977];

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

    /**
     * @param  list<array{employee: string, sheet: array<string, mixed>}>  $entries
     */
    public function build(array $entries, string $generatedAt): string
    {
        $this->sheets = [];
        $this->styles = [];
        $this->styleList = [];
        $this->usedNames = [];
        $this->logo = $this->logoBytes();
        // The default font sets the column unit (a 10 pt Arial digit is 7 px), which the widths below assume.
        $this->style(10, false, false, 'left', 'center', false, '');

        foreach ($entries as $entry) {
            $this->addSheet($entry['employee'], $entry['sheet'], $generatedAt);
        }

        if ($this->sheets === []) {
            throw new RuntimeException('There is no DTR to export.');
        }

        return $this->zip();
    }

    /**
     * @param  array<string, mixed>  $sheet
     */
    private function addSheet(string $employee, array $sheet, string $generatedAt): void
    {
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

        $box = function (int $row, int $col, int $rows, int $cols, string|int|array $value, int $style) use ($grid) {
            for ($r = $row; $r < $row + $rows; $r++) {
                for ($c = $col; $c < $col + $cols; $c++) {
                    $grid->cells[$r][$c] = [($r === $row && $c === $col) ? $value : '', $style];
                }
            }
            if ($rows > 1 || $cols > 1) {
                $grid->merges[] = $this->ref($row, $col).':'.$this->ref($row + $rows - 1, $col + $cols - 1);
            }
        };
        $height = function (int $row, float $cm) use ($grid) {
            $grid->heights[$row] = round($cm * 28.3465, 2);
        };

        $totals = $sheet['totals'];
        $s = fn (float $size, bool $bold, bool $italic, string $h, bool $wrap, string $edges, string $v = 'center', string $font = 'Arial') => $this->style($size, $bold, $italic, $h, $v, $wrap, $edges, $font);

        // Title, logo space and agency.
        $box(1, 1, 4, 5, 'DAILY TIME RECORD', $s(13, true, false, 'center', false, self::ALL, 'center', 'Microsoft Sans Serif'));
        $box(1, 6, 3, 5, '', $s(8, false, false, 'center', false, 't'));
        $box(1, 11, 3, 7, [
            ['t' => "Republic of the Philippines\n", 'size' => 8, 'bold' => false],
            ['t' => "CIVIL SERVICE COMMISSION\n", 'size' => 9, 'bold' => true],
            ['t' => 'CSC Building, IBP Road, Constitution Hills, Diliman, 1126 Quezon City', 'size' => 6, 'bold' => false],
        ], $s(8, false, false, 'left', true, 'tr'));
        $box(4, 6, 1, 5, '', $s(8, false, false, 'left', false, ''));
        $box(4, 11, 1, 7, 'Civil Service Form No. 48', $s(8, true, false, 'right', false, 'r'));
        foreach ([1 => 0.529, 2 => 0.492, 3 => 0.46, 4 => 0.492] as $row => $cm) {
            $height($row, $cm);
        }

        // Employee, time reference, period and the day counts.
        $box(5, 1, 1, 5, "Employee's Name", $s(8.5, false, false, 'right', false, self::ALL));
        $box(5, 6, 1, 12, mb_strtoupper($employee), $s(11, true, false, 'left', false, self::ALL));
        $box(6, 1, 2, 5, 'Time Reference', $s(9, false, false, 'right', false, self::ALL));
        $box(6, 6, 2, 9, $sheet['schedule'] ?? 'No work schedule assigned', $s(10, false, false, 'left', true, self::ALL));
        $box(6, 15, 1, 2, 'Regular Days', $s(9, false, false, 'right', false, self::ALL));
        $box(6, 17, 1, 1, (int) $totals['regular_days'], $s(10, false, false, 'center', false, self::ALL));
        $box(7, 15, 1, 2, 'Weekends', $s(9, false, false, 'right', false, self::ALL));
        $box(7, 17, 1, 1, (int) $totals['weekend_days'], $s(10, false, false, 'center', false, self::ALL));
        $box(8, 1, 1, 5, 'For the period of', $s(9, false, false, 'right', false, self::ALL));
        $box(8, 6, 1, 9, (string) $sheet['period'], $s(10, true, false, 'left', false, self::ALL));
        $box(8, 15, 1, 2, 'Holiday', $s(9, false, false, 'right', false, self::ALL));
        $box(8, 17, 1, 1, (int) $totals['holiday_days'], $s(10, false, false, 'center', false, self::ALL));
        foreach ([5 => 0.704, 6 => 0.598, 7 => 0.492, 8 => 0.566] as $row => $cm) {
            $height($row, $cm);
        }

        // Column headings.
        $box(9, 1, 2, 1, 'Day', $s(10, true, false, 'center', false, self::ALL));
        $box(9, 2, 1, 3, 'MORNING', $s(10, true, false, 'center', false, self::ALL));
        $box(9, 5, 1, 7, 'AFTERNOON', $s(10, true, false, 'center', false, self::ALL));
        $box(9, 12, 2, 1, 'Worked Hrs.', $s(8, true, false, 'center', true, self::ALL));
        $box(9, 13, 2, 5, 'Remarks', $s(10, true, false, 'center', false, self::ALL));
        $box(10, 2, 1, 1, 'Time-in', $s(8.5, false, false, 'center', false, self::ALL));
        $box(10, 3, 1, 2, 'Break-out', $s(8.5, false, false, 'center', false, self::ALL));
        $box(10, 5, 1, 3, 'Break-in', $s(8.5, false, false, 'center', false, self::ALL));
        $box(10, 8, 1, 4, 'Time-out', $s(8.5, false, false, 'center', false, self::ALL));
        $height(9, 0.423);
        $height(10, 0.423);

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
            $box($r, 3, 1, 2, $this->clock($row['break_out'] ?? null), $cell);
            $box($r, 5, 1, 3, $this->clock($row['break_in'] ?? null), $cell);
            $box($r, 8, 1, 4, $this->clock($row['out'] ?? null), $cell);
            $box($r, 12, 1, 1, $this->worked($row['minutes'] ?? 0), $cell);
            $box($r, 13, 1, 5, (string) ($row['remark'] ?? ''), $remark);
            $height($r, 0.423);
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
        foreach ($left as $index => [$label, $value]) {
            $r = 42 + $index;
            $box($r, 1, 1, 9, $label, $s(9, true, false, 'right', false, self::ALL));
            $box($r, 10, 1, 4, $value, $s(10, $index === 0, false, 'left', false, self::ALL));
            $box($r, 14, 1, 2, $right[$index][0], $s(9, true, false, 'right', false, self::ALL));
            $box($r, 16, 1, 2, $right[$index][1], $s(10, false, false, 'left', false, self::ALL));
            $height($r, 0.423);
        }

        // Certification.
        $box(46, 1, 1, 17, 'I CERTIFY on my honor that the above is true and correct report of the hours of work performed, record or which was made daily at the time of arrival and at departure from office', $s(7.5, false, false, 'left', true, 'lrbT'));
        $height(46, 0.99);

        // Signatures, the validation code and the footer.
        $signature = $sheet['signature'] ?? null;
        $box(47, 1, 2, 3, 'Use this code to validate the authenticity of this document', $s(7.5, false, false, 'center', true, self::ALL));
        $box(47, 4, 1, 5, "Employee's Signature", $s(8.5, false, false, 'left', false, self::ALL));
        $box(47, 9, 1, 9, $this->signatureNote($signature), $s(9, true, false, 'center', true, self::ALL));
        $box(48, 4, 2, 14, 'Office Head Name/Signature', $s(8.5, false, false, 'left', false, self::ALL));
        $box(49, 1, 5, 3, $signature ? "Code\n{$signature['code']}\n\n{$signature['verify_url']}" : '', $s(7, false, false, 'center', true, self::ALL));
        $box(50, 4, 1, 14, mb_strtoupper((string) ($sheet['head'] ?? '')), $s(11, true, false, 'center', false, self::ALL, 'bottom'));
        $box(51, 4, 1, 14, 'Verified as to the prescribed office hours', $s(8, false, true, 'center', false, self::ALL));
        $box(52, 4, 1, 3, 'Generated Date:', $s(8.5, false, false, 'left', false, self::ALL));
        $box(52, 7, 1, 11, $generatedAt, $s(8.5, false, false, 'left', false, self::ALL));
        $box(53, 4, 1, 14, 'This is a computer generated document.', $s(8.5, false, false, 'left', false, self::ALL));
        foreach ([47 => 0.884, 48 => 0.143, 49 => 0.318, 50 => 0.99, 51 => 0.318, 52 => 0.386, 53 => 0.423] as $row => $cm) {
            $height($row, $cm);
        }

        $this->sheets[] = ['name' => $name, 'cells' => $grid->cells, 'merges' => $grid->merges, 'heights' => $grid->heights];
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

    private function ref(int $row, int $col): string
    {
        return chr(64 + $col).$row;
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

        $overrides = $this->logo !== null ? '<Default Extension="png" ContentType="image/png"/>' : '';
        if ($this->logo !== null) {
            $zip->addFromString('xl/media/logo.png', $this->logo);
        }
        $sheetEntries = '';
        $rels = '';
        foreach ($this->sheets as $index => $sheet) {
            $n = $index + 1;
            $overrides .= "<Override PartName=\"/xl/worksheets/sheet{$n}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
            $sheetEntries .= '<sheet name="'.$this->escape($sheet['name'])."\" sheetId=\"{$n}\" r:id=\"rId{$n}\"/>";
            $rels .= "<Relationship Id=\"rId{$n}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet{$n}.xml\"/>";
            $zip->addFromString("xl/worksheets/sheet{$n}.xml", $this->sheetXml($sheet));
            if ($this->logo !== null) {
                $overrides .= "<Override PartName=\"/xl/drawings/drawing{$n}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.drawing+xml\"/>";
                $zip->addFromString("xl/worksheets/_rels/sheet{$n}.xml.rels", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing'.$n.'.xml"/></Relationships>');
                $zip->addFromString("xl/drawings/drawing{$n}.xml", $this->drawingXml());
                $zip->addFromString("xl/drawings/_rels/drawing{$n}.xml.rels", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/logo.png"/></Relationships>');
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
     * @param  array{cells: array<int, array<int, array{0: string|int, 1: int}>>, merges: list<string>, heights: array<int, float>}  $sheet
     */
    private function sheetXml(array $sheet): string
    {
        $cols = '';
        foreach (self::COLUMNS as $index => $cm) {
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

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><dimension ref="A1:Q53"/><sheetViews><sheetView showGridLines="0" workbookViewId="0"/></sheetViews><sheetFormatPr defaultRowHeight="15"/><cols>'.$cols.'</cols><sheetData>'.$rows.'</sheetData>'.$merges.'<printOptions horizontalCentered="1"/><pageMargins left="0.2" right="0.2" top="0.2" bottom="0.2" header="0" footer="0"/><pageSetup paperSize="9" scale="97" orientation="portrait"/>'.($this->logo !== null ? '<drawing r:id="rId1"/>' : '').'</worksheet>';
    }

    /** The logo sits in the header box (F1:J3), centred and a little low, as on the printed form: 1.711 × 1.482 cm. */
    private function drawingXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<xdr:oneCellAnchor><xdr:from><xdr:col>5</xdr:col><xdr:colOff>38160</xdr:colOff><xdr:row>0</xdr:row><xdr:rowOff>36000</xdr:rowOff></xdr:from><xdr:ext cx="615960" cy="533520"/>'
            .'<xdr:pic><xdr:nvPicPr><xdr:cNvPr id="2" name="Agency logo" descr="Agency logo"/><xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr>'
            .'<xdr:blipFill><a:blip r:embed="rId1"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
            .'<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="615960" cy="533520"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic><xdr:clientData/></xdr:oneCellAnchor></xdr:wsDr>';
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
