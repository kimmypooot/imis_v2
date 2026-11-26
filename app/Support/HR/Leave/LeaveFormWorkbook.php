<?php

namespace App\Support\HR\Leave;

use App\Models\BrandAsset;
use App\Support\Branding\Branding;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * CS Form No. 6, Revised 2020 as an Excel workbook: CSC's own fillable
 * template (resources/templates/hr), with its boxes ticked and its text
 * boxes filled from LeaveForm, the agency's name, address and logo in
 * the header, and both sheets set to print on Folio (8.5 × 13 in), one
 * page each, fitted. Sheet 2 is the form's Instructions and Requirements.
 *
 * Only the template's own XML is edited (no spreadsheet package): the
 * form's borders, fonts and wording stay exactly as CSC issued them.
 */
class LeaveFormWorkbook
{
    public const TEMPLATE = 'templates/hr/cs-form-6-revised-2020.xlsx';

    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const NS_XDR = 'http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing';

    private const NS_A = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    private const EMU_PER_PX = 9525;

    /** The check boxes' legacy drawing, which older Excel and LibreOffice read. */
    private const VML = 'xl/drawings/vmlDrawing1.vml';

    /** Paper size 14 is Folio, 8.5 × 13 in. */
    private const FOLIO = '14';

    /** Folio, in inches. */
    private const PAPER_WIDTH = 8.5;

    private const PAPER_HEIGHT = 13.0;

    /** Close to the edge, but clear of what most printers can't reach. */
    private const MARGIN = 0.25;

    /** Rows 1–65 are the form (the template's print area). */
    private const LAST_ROW = 65;

    /**
     * The template's check boxes (form controls), by what they stand for.
     *
     * @var array<string, string>
     */
    private const BOXES = [
        'Check Box 5' => 'type.VL', 'Check Box 3' => 'type.FL', 'Check Box 4' => 'type.SL', 'Check Box 6' => 'type.ML',
        'Check Box 7' => 'type.PL', 'Check Box 8' => 'type.SPL', 'Check Box 9' => 'type.SOLO', 'Check Box 10' => 'type.STUDY',
        'Check Box 11' => 'type.VAWC', 'Check Box 12' => 'type.RL', 'Check Box 13' => 'type.SLBW', 'Check Box 14' => 'type.SEL',
        'Check Box 15' => 'type.AL',
        'Check Box 16' => 'detail.within_ph', 'Check Box 17' => 'detail.abroad', 'Check Box 18' => 'detail.in_hospital',
        'Check Box 19' => 'detail.out_patient', 'Check Box 20' => 'detail.masters_degree', 'Check Box 21' => 'detail.bar_board_review',
        'Check Box 22' => 'detail.monetization', 'Check Box 23' => 'detail.terminal',
        'Check Box 24' => 'commutation.no', 'Check Box 25' => 'commutation.yes',
        'Check Box 26' => 'recommendation.approval', 'Check Box 27' => 'recommendation.disapproval',
    ];

    /** @var array<int, int> column index (0-based) → width in pixels */
    private array $columns = [];

    /** @var array<int, array{name: string, sz: string, b: bool}> cell style → its font */
    private array $fonts = [];

    /** How much taller each row of the form is made, so it fills Folio. */
    private float $stretch = 1.0;

    /**
     * @param  array<string, mixed>  $form  LeaveForm::present()
     * @return string the .xlsx file's bytes
     */
    public function build(array $form): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cs6');
        copy(resource_path(self::TEMPLATE), $path);

        try {
            $zip = new ZipArchive;
            if ($zip->open($path) !== true) {
                throw new RuntimeException('The CS Form No. 6 template could not be opened.');
            }

            $sheet = $this->load($zip, 'xl/worksheets/sheet1.xml');
            $this->columns = $this->columnWidths($sheet);
            $this->fonts = $this->cellFonts($this->load($zip, 'xl/styles.xml'));
            $this->fillCells($sheet, $form);
            $controls = $this->controls($zip, $sheet);
            $this->stretch = $this->stretchRows($sheet);
            $this->folio($sheet);
            $this->footer($sheet, $form);
            $zip->addFromString('xl/worksheets/sheet1.xml', $sheet->saveXML());

            $instructions = $this->load($zip, 'xl/worksheets/sheet2.xml');
            $this->folio($instructions);
            $zip->addFromString('xl/worksheets/sheet2.xml', $instructions->saveXML());

            // The legacy VML is read once, changed in memory by both steps, and
            // written once: an entry replaced in an open archive can't be read back.
            $vml = (string) $zip->getFromName(self::VML);
            $vml = $this->tick($zip, $controls, $this->ticks($form), $vml);
            $zip->addFromString(self::VML, $this->stretchVml($vml));

            $drawing = $this->load($zip, 'xl/drawings/drawing1.xml');
            $this->fillTextBoxes($drawing, $form);
            $this->logo($zip, $drawing);
            $this->stretchOffsets($drawing);
            $zip->addFromString('xl/drawings/drawing1.xml', $drawing->saveXML());

            $this->scrubProperties($zip, $form);
            $zip->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    // ── Cells ────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $form
     */
    private function fillCells(DOMDocument $sheet, array $form): void
    {
        $agency = $form['agency'];
        $this->setCell($sheet, 'A2', [
            ['Republic of the Philippines', ['b' => false]],
            ["\n".mb_strtoupper($agency['name']), ['b' => true]],
            ["\n".$agency['address'], ['b' => false]],
        ]);
        $this->setCell($sheet, 'I1', [['ANNEX A', ['b' => true, 'sz' => 10, 'font' => 'Arial']]]);

        foreach ($form['credits']['rows'] ?? [] as $code => $row) {
            $column = $code === 'VL' ? 'D' : 'E';
            // CSC's template has three rows: the credits HR certified ("Total
            // Earned"), this application, and the balance after.
            $this->setCell($sheet, "{$column}56", $row['balance']);
            $this->setCell($sheet, "{$column}57", $row['less']);
            $this->setCell($sheet, "{$column}58", $row['after']);
        }
    }

    /**
     * Writes an inline string. Plain text takes the cell's own font; runs
     * are [text, overrides] pairs on top of it.
     *
     * @param  string|array<int, array{0: string, 1: array<string, mixed>}>  $value
     */
    private function setCell(DOMDocument $sheet, string $ref, string|array $value): void
    {
        $cell = $this->cell($sheet, $ref);
        while ($cell->firstChild) {
            $cell->removeChild($cell->firstChild);
        }
        $cell->setAttribute('t', 'inlineStr');
        $is = $cell->appendChild($sheet->createElementNS(self::NS_MAIN, 'is'));

        if (is_string($value)) {
            $this->text($sheet, $is, $value);

            return;
        }

        $font = $this->fonts[(int) $cell->getAttribute('s')] ?? ['name' => 'Arial', 'sz' => '10', 'b' => false];
        foreach ($value as [$text, $overrides]) {
            $run = $is->appendChild($sheet->createElementNS(self::NS_MAIN, 'r'));
            $props = $run->appendChild($sheet->createElementNS(self::NS_MAIN, 'rPr'));
            // CT_RPrElt order: rFont, b, sz.
            $props->appendChild($sheet->createElementNS(self::NS_MAIN, 'rFont'))->setAttribute('val', $overrides['font'] ?? $font['name']);
            if ($overrides['b'] ?? $font['b']) {
                $props->appendChild($sheet->createElementNS(self::NS_MAIN, 'b'));
            }
            $props->appendChild($sheet->createElementNS(self::NS_MAIN, 'sz'))->setAttribute('val', (string) ($overrides['sz'] ?? $font['sz']));
            $this->text($sheet, $run, $text);
        }
    }

    private function text(DOMDocument $sheet, DOMElement $parent, string $text): void
    {
        $t = $parent->appendChild($sheet->createElementNS(self::NS_MAIN, 't'));
        $t->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        $t->appendChild($sheet->createTextNode($text));
    }

    /** The cell, created in column order when the template has none. */
    private function cell(DOMDocument $sheet, string $ref): DOMElement
    {
        $xpath = $this->xpath($sheet);
        $existing = $xpath->query("//m:c[@r='{$ref}']")->item(0);
        if ($existing instanceof DOMElement) {
            return $existing;
        }

        preg_match('/^([A-Z]+)(\d+)$/', $ref, $parts);
        $row = $xpath->query("//m:sheetData/m:row[@r='{$parts[2]}']")->item(0);
        if (! $row instanceof DOMElement) {
            throw new RuntimeException("Row {$parts[2]} is missing from the CS Form No. 6 template.");
        }

        $cell = $sheet->createElementNS(self::NS_MAIN, 'c');
        $cell->setAttribute('r', $ref);
        foreach ($xpath->query('m:c', $row) as $sibling) {
            preg_match('/^([A-Z]+)/', $sibling->getAttribute('r'), $column);
            if (strlen($column[1]) > strlen($parts[1]) || (strlen($column[1]) === strlen($parts[1]) && strcmp($column[1], $parts[1]) > 0)) {
                return $row->insertBefore($cell, $sibling);
            }
        }

        return $row->appendChild($cell);
    }

    /**
     * @return array<int, array{name: string, sz: string, b: bool}>
     */
    private function cellFonts(DOMDocument $styles): array
    {
        $xpath = $this->xpath($styles);
        $fonts = $xpath->query('//m:fonts/m:font');
        $list = [];
        foreach ($xpath->query('//m:cellXfs/m:xf') as $xf) {
            $font = $fonts->item((int) $xf->getAttribute('fontId'));
            $list[] = [
                'name' => $xpath->query('m:name/@val', $font)->item(0)?->nodeValue ?? 'Arial',
                'sz' => $xpath->query('m:sz/@val', $font)->item(0)?->nodeValue ?? '10',
                'b' => $xpath->query('m:b', $font)->length > 0,
            ];
        }

        return $list;
    }

    /**
     * Folio, portrait, one page fitted and centered, quarter-inch margins,
     * no header or footer, with the template's A4 printer settings
     * dropped so they can't override the paper.
     */
    private function folio(DOMDocument $sheet): void
    {
        $xpath = $this->xpath($sheet);
        $root = $sheet->documentElement;

        $sheetPr = $xpath->query('m:sheetPr', $root)->item(0);
        $fit = $xpath->query('m:pageSetUpPr', $sheetPr)->item(0) ?? $sheetPr->appendChild($sheet->createElementNS(self::NS_MAIN, 'pageSetUpPr'));
        $fit->setAttribute('fitToPage', '1');

        foreach ($xpath->query('m:sheetViews/m:sheetView', $root) as $view) {
            $view->setAttribute('view', 'pageLayout');
            $view->removeAttribute('topLeftCell');
        }

        $margins = $xpath->query('m:pageMargins', $root)->item(0);
        foreach (['left', 'right', 'top', 'bottom'] as $side) {
            $margins->setAttribute($side, (string) self::MARGIN);
        }
        $margins->setAttribute('header', '0');
        $margins->setAttribute('footer', '0');

        $options = $xpath->query('m:printOptions', $root)->item(0) ?? $root->insertBefore($sheet->createElementNS(self::NS_MAIN, 'printOptions'), $margins);
        $options->setAttribute('horizontalCentered', '1');
        $options->setAttribute('verticalCentered', '1');

        $setup = $xpath->query('m:pageSetup', $root)->item(0);
        $setup->removeAttributeNS(self::NS_REL, 'id');
        $setup->removeAttribute('scale');
        $setup->setAttribute('paperSize', self::FOLIO);
        $setup->setAttribute('orientation', 'portrait');
        $setup->setAttribute('fitToWidth', '1');
        $setup->setAttribute('fitToHeight', '1');
    }

    /**
     * @return array<int, int>
     */
    private function columnWidths(DOMDocument $sheet): array
    {
        $widths = [];
        foreach ($this->xpath($sheet)->query('//m:cols/m:col') as $col) {
            for ($index = (int) $col->getAttribute('min'); $index <= (int) $col->getAttribute('max'); $index++) {
                $widths[$index - 1] = (int) floor((float) $col->getAttribute('width') * 7 + 5);
            }
        }

        return $widths;
    }

    // ── Filling Folio ────────────────────────────────────────────────

    /**
     * CSC's template is drawn for A4. Fitted to Folio's width it would
     * leave the bottom of the page empty, so every row of the form grows
     * by the same factor until the form fills the page between margins.
     * The check boxes ride along (their offsets within rows grow too).
     */
    private function stretchRows(DOMDocument $sheet): float
    {
        $xpath = $this->xpath($sheet);
        // Excel lays the columns out about 1% wider than their pixel widths,
        // and the rows about 2% shorter than their stated heights (measured
        // on this template in Excel 2016+).
        $widthInches = array_sum(array_slice($this->columns, 0, 9)) / 96 * 1.01;
        $scale = min(1.0, (self::PAPER_WIDTH - 2 * self::MARGIN) / max(1, $widthInches));

        $rows = [];
        foreach ($xpath->query('//m:sheetData/m:row') as $row) {
            if ((int) $row->getAttribute('r') <= self::LAST_ROW) {
                $rows[] = $row;
            }
        }
        $heightPoints = array_sum(array_map(fn (DOMElement $row) => (float) ($row->getAttribute('ht') ?: 12.75), $rows));

        // A little short of the full height, so rounding never makes the height the tighter fit.
        $target = (self::PAPER_HEIGHT - 2 * self::MARGIN) * 72 / $scale / 0.978 * 0.99;
        $stretch = max(1.0, $target / max(1, $heightPoints));

        foreach ($rows as $row) {
            $row->setAttribute('ht', (string) round((float) ($row->getAttribute('ht') ?: 12.75) * $stretch, 2));
            $row->setAttribute('customHeight', '1');
        }
        foreach ($sheet->getElementsByTagNameNS(self::NS_XDR, 'rowOff') as $offset) {
            $offset->textContent = (string) (int) round((int) $offset->textContent * $stretch);
        }

        return $stretch;
    }

    /** Text boxes and the logo keep their place within their rows. */
    private function stretchOffsets(DOMDocument $drawing): void
    {
        foreach ($drawing->getElementsByTagNameNS(self::NS_XDR, 'rowOff') as $offset) {
            $offset->textContent = (string) (int) round((int) $offset->textContent * $this->stretch);
        }
    }

    /** The legacy (VML) check boxes: anchor = col, px, row, px, col, px, row, px. */
    private function stretchVml(string $vml): string
    {
        return preg_replace_callback('/<x:Anchor>(.*?)<\/x:Anchor>/s', function (array $match) {
            $parts = array_map('intval', explode(',', $match[1]));
            if (count($parts) === 8) {
                $parts[3] = (int) round($parts[3] * $this->stretch);
                $parts[7] = (int) round($parts[7] * $this->stretch);
            }

            return '<x:Anchor>'.implode(', ', $parts).'</x:Anchor>';
        }, $vml) ?? $vml;
    }

    // ── Check boxes ──────────────────────────────────────────────────

    /**
     * Which boxes to tick.
     *
     * @param  array<string, mixed>  $form
     * @return array<int, string>
     */
    private function ticks(array $form): array
    {
        $ticks = ["type.{$form['type']}", $form['commutation_requested'] ? 'commutation.yes' : 'commutation.no'];
        foreach ($form['details'] as $key => $detail) {
            if ($detail['checked']) {
                $ticks[] = "detail.{$key}";
            }
        }
        if ($form['recommendation']) {
            $ticks[] = "recommendation.{$form['recommendation']}";
        }

        return $ticks;
    }

    /**
     * Each check box's control-properties part and VML shape id, by name.
     *
     * @return array<string, array{part: string, shape: string}>
     */
    private function controls(ZipArchive $zip, DOMDocument $sheet): array
    {
        $rels = $this->load($zip, 'xl/worksheets/_rels/sheet1.xml.rels');
        $targets = [];
        foreach ($rels->documentElement->childNodes as $rel) {
            if ($rel instanceof DOMElement) {
                $targets[$rel->getAttribute('Id')] = 'xl/'.ltrim(str_replace('../', '', $rel->getAttribute('Target')), '/');
            }
        }

        $controls = [];
        foreach ($sheet->getElementsByTagNameNS(self::NS_MAIN, 'control') as $control) {
            $controls[$control->getAttribute('name')] = [
                'part' => $targets[$control->getAttributeNS(self::NS_REL, 'id')] ?? '',
                'shape' => '_x0000_s'.$control->getAttribute('shapeId'),
            ];
        }

        return $controls;
    }

    /**
     * Sets every box: ticked or cleared (the template's sample has some
     * ticked), in both the control properties and the legacy VML that
     * older Excel and LibreOffice read.
     *
     * @param  array<string, array{part: string, shape: string}>  $controls
     * @param  array<int, string>  $ticks
     * @return string the VML, with the boxes set
     */
    private function tick(ZipArchive $zip, array $controls, array $ticks, string $vml): string
    {

        foreach (self::BOXES as $name => $meaning) {
            $control = $controls[$name] ?? null;
            if (! $control || ! $control['part']) {
                continue;
            }
            $checked = in_array($meaning, $ticks, true);

            $props = $this->load($zip, $control['part']);
            $checked ? $props->documentElement->setAttribute('checked', 'Checked') : $props->documentElement->removeAttribute('checked');
            $zip->addFromString($control['part'], $props->saveXML());

            $vml = preg_replace_callback(
                '/(<v:shape id="'.preg_quote($control['shape'], '/').'".*?<x:ClientData ObjectType="Checkbox">)(.*?)(<\/x:ClientData>)/s',
                function (array $match) use ($checked) {
                    $body = preg_replace('/\s*<x:Checked>\d<\/x:Checked>/', '', $match[2]);

                    return $match[1].$body.($checked ? '<x:Checked>1</x:Checked>' : '').$match[3];
                },
                $vml,
            ) ?? $vml;
        }

        return $vml;
    }

    // ── Text boxes ───────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $form
     */
    private function fillTextBoxes(DOMDocument $drawing, array $form): void
    {
        $details = $form['details'];

        // 2. NAME — one box under each of (Last), (First) and (Middle).
        $name = $this->anchor($drawing, 'TextBox 2');
        $first = $this->cloneAnchor($drawing, $name, 'TextBox First Name');
        $middle = $this->cloneAnchor($drawing, $name, 'TextBox Middle Name');
        $this->place($name, [4, 52], [7, 6]);
        $this->place($first, [7, 20], [8, 135]);
        $this->place($middle, [8, 140], [8, 318]);
        $this->fill($drawing, $name, $form['name']['last'], ['algn' => 'ctr']);
        $this->fill($drawing, $first, $form['name']['first'], ['algn' => 'ctr']);
        $this->fill($drawing, $middle, $form['name']['middle'], ['algn' => 'ctr']);

        // The office (the agency's full name) may take two lines, as CSC's system prints it.
        $this->place($this->anchor($drawing, 'TextBox 66'), [1, 0, 4, 0], [3, 125, 4, 323850]);
        $this->fillNamed($drawing, 'TextBox 66', $form['office'], ['sz' => 950, 'lines' => 2]);
        // 3–5: each value centered over its underline. The boxes span the
        // underlines and have no fill, so they never clip the labels.
        $this->place($this->anchor($drawing, 'TextBox 38'), [2, 84, 5, 0], [3, 77, 5, 300000]);
        $this->place($this->anchor($drawing, 'TextBox 39'), [4, 78, 5, 0], [8, 89, 5, 300000]);
        $this->place($this->anchor($drawing, 'TextBox 40'), [8, 189, 5, 0], [8, 291, 5, 300000]);
        foreach (['TextBox 38', 'TextBox 39', 'TextBox 40'] as $box) {
            $this->seeThrough($this->anchor($drawing, $box));
        }
        $this->fillNamed($drawing, 'TextBox 38', $form['filed_on'], ['algn' => 'ctr']);
        $this->fillNamed($drawing, 'TextBox 39', $form['position'], ['algn' => 'ctr']);
        $this->fillNamed($drawing, 'TextBox 40', $form['salary'], ['algn' => 'ctr']);
        // Nudged right: in the template it covers the end of "As of".
        $this->place($this->anchor($drawing, 'TextBox 51'), [2, 122], [4, 46]);

        // 6.A Others, 6.B details.
        $this->fillNamed($drawing, 'TextBox 48', $form['others'], ['b' => true, 'lines' => 2]);
        $this->fillNamed($drawing, 'TextBox 41', $details['within_ph']['text'], ['b' => true]);
        $this->fillNamed($drawing, 'TextBox 42', $details['abroad']['text'], ['b' => true]);
        $this->fillNamed($drawing, 'TextBox 43', $details['in_hospital']['text'], ['b' => true], 'TextBox 45');
        $this->fillNamed($drawing, 'TextBox 44', $details['out_patient']['text'], ['b' => true], 'TextBox 45');
        $this->fillNamed($drawing, 'TextBox 46', $details['special_illness']['text'], ['b' => true], 'TextBox 47');

        // 6.C. The applicant signs over "(Signature of Applicant)".
        $days = $form['days'].($form['is_emergency'] ? ' (emergency, filed upon return)' : '');
        $this->fillNamed($drawing, 'TextBox 49', $days);
        $this->fillNamed($drawing, 'TextBox 50', $form['inclusive_dates']);

        // 7.A–7.D.
        $this->fillNamed($drawing, 'TextBox 51', $form['credits']['as_of'] ?? '');
        $this->place($this->anchor($drawing, 'TextBox 56'), [2, 29, 58, 15000], [4, 95, 58, 400000]);
        $this->place($this->anchor($drawing, 'TextBox 57'), [8, 1, 58, 15000], [8, 315, 58, 400000]);
        $this->place($this->anchor($drawing, 'TextBox 65'), [3, 79, 64, 300000], [8, 82, 64, 706438]);
        $this->signature($drawing, 'TextBox 56', $form['certifier']);
        $this->lines($drawing, ['TextBox 52', 'TextBox 53', 'TextBox 54', 'TextBox 55'], $form['recommendation_reason']);
        $this->signature($drawing, 'TextBox 57', $form['recommender']);
        $this->fillNamed($drawing, 'TextBox 59', $form['approved_for']['with_pay'], ['algn' => 'ctr']);
        $this->fillNamed($drawing, 'TextBox 60', $form['approved_for']['without_pay'], ['algn' => 'ctr']);
        $this->fillNamed($drawing, 'TextBox 61', $form['approved_for']['others'], ['algn' => 'ctr']);
        $this->lines($drawing, ['TextBox 62', 'TextBox 63', 'TextBox 64'], $form['disapproval_reason']);
        $this->signature($drawing, 'TextBox 65', $form['approver']);

        // The control number under "Stamp of Date of Receipt", right of the title.
        $control = $this->cloneAnchor($drawing, $this->anchor($drawing, 'TextBox 57'), 'TextBox Control Number');
        $this->place($control, [8, 200, 2, 150000], [8, 325, 2, 560000]);
        $this->seeThrough($control);
        $this->paragraphs($drawing, $control, [
            [$form['reference_number'], ['b' => true, 'sz' => 1000, 'u' => true]],
            ['Control Number', ['b' => false, 'sz' => 900]],
        ], 'ctr');

        // When the head (or OIC) decided, at the foot of 7.C/7.D.
        $decided = $this->cloneAnchor($drawing, $this->anchor($drawing, 'TextBox 57'), 'TextBox Decided At');
        $this->place($decided, [8, 200, 64, 720000], [8, 325, 64, 960000]);
        $this->seeThrough($decided);
        $this->paragraphs($drawing, $decided, [[$form['decided_at'], ['b' => false, 'sz' => 900]]], 'r');
    }

    /**
     * "This is a system generated document." and who printed it, at the
     * foot of the form, in the bottom margin.
     *
     * @param  array<string, mixed>  $form
     */
    private function footer(DOMDocument $sheet, array $form): void
    {
        $xpath = $this->xpath($sheet);
        $root = $sheet->documentElement;
        $xpath->query('m:pageMargins', $root)->item(0)->setAttribute('footer', '0.08');

        $headerFooter = $xpath->query('m:headerFooter', $root)->item(0);
        while ($headerFooter->firstChild) {
            $headerFooter->removeChild($headerFooter->firstChild);
        }
        $printedBy = $form['printed_by'] !== '' ? '&R&"Arial,Regular"&7Printed by: '.str_replace('&', '&&', $form['printed_by']) : '';
        $headerFooter->appendChild($sheet->createElementNS(self::NS_MAIN, 'oddFooter'))
            ->appendChild($sheet->createTextNode('&C&"Arial,Regular"&7This is a system generated document.'.$printedBy));
    }

    /**
     * A signatory: name in bold, position under it; an officer-in-charge
     * says whom they acted for.
     *
     * @param  ?array{name: string, position: string, acting_for: string, acted_on: string}  $signer
     */
    private function signature(DOMDocument $drawing, string $box, ?array $signer): void
    {
        $anchor = $this->anchor($drawing, $box);
        if (! $signer) {
            $this->fill($drawing, $anchor, '');

            return;
        }

        $this->seeThrough($anchor);
        $position = $signer['position'].($signer['acting_for'] !== '' ? " (OIC, for {$signer['acting_for']})" : '');
        $this->paragraphs($drawing, $anchor, [
            [$signer['name'], ['b' => true, 'sz' => 1000]],
            [$position, ['b' => false, 'sz' => 800]],
        ], 'ctr');
    }

    /**
     * Free text over several ruled lines, word-wrapped to each line's width.
     *
     * @param  array<int, string>  $boxes
     */
    private function lines(DOMDocument $drawing, array $boxes, string $text): void
    {
        $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($boxes as $index => $box) {
            $anchor = $this->anchor($drawing, $box);
            $capacity = $this->capacity($anchor, 900);
            $line = '';
            while ($words && mb_strlen(trim($line.' '.$words[0])) <= $capacity) {
                $line = trim($line.' '.array_shift($words));
            }
            if ($index === array_key_last($boxes) && $words) {
                $line = trim($line.' '.implode(' ', $words));
            }
            $this->fill($drawing, $anchor, $line, ['sz' => 900, 'b' => true]);
        }
    }

    /**
     * Fills a box; text too long for it shrinks, and failing that, goes on
     * to an overflow line.
     *
     * @param  array<string, mixed>  $options
     */
    private function fillNamed(DOMDocument $drawing, string $box, string $text, array $options = [], ?string $overflow = null): void
    {
        $anchor = $this->anchor($drawing, $box);
        if ($overflow && $text !== '' && mb_strlen($text) > $this->capacity($anchor, 800)) {
            $this->lines($drawing, [$box, $overflow], $text);

            return;
        }

        $this->fill($drawing, $anchor, $text, $options);
    }

    /**
     * @param  array<string, mixed>  $options  b, sz (hundredths of a point), algn, lines
     */
    private function fill(DOMDocument $drawing, DOMElement $anchor, string $text, array $options = []): void
    {
        $size = $options['sz'] ?? 1100;
        $lines = $options['lines'] ?? 1;
        while ($size > 700 && mb_strlen($text) > $this->capacity($anchor, $size) * $lines) {
            $size -= 50;
        }

        $this->paragraphs($drawing, $anchor, [[$text, ['b' => $options['b'] ?? true, 'sz' => $size]]], $options['algn'] ?? null);
    }

    /**
     * Replaces a box's text with these paragraphs.
     *
     * @param  array<int, array{0: string, 1: array{b: bool, sz: int, u?: bool}}>  $paragraphs
     */
    private function paragraphs(DOMDocument $drawing, DOMElement $anchor, array $paragraphs, ?string $align = null): void
    {
        $body = $anchor->getElementsByTagNameNS(self::NS_XDR, 'txBody')->item(0);
        foreach (iterator_to_array($body->getElementsByTagNameNS(self::NS_A, 'p')) as $old) {
            $body->removeChild($old);
        }

        foreach ($paragraphs as [$text, $style]) {
            $p = $body->appendChild($drawing->createElementNS(self::NS_A, 'a:p'));
            if ($align) {
                $p->appendChild($drawing->createElementNS(self::NS_A, 'a:pPr'))->setAttribute('algn', $align);
            }
            $props = $drawing->createElementNS(self::NS_A, 'a:rPr');
            $props->setAttribute('lang', 'en-US');
            $props->setAttribute('sz', (string) $style['sz']);
            $props->setAttribute('b', $style['b'] ? '1' : '0');
            if ($style['u'] ?? false) {
                $props->setAttribute('u', 'sng');
            }
            if ($text !== '') {
                $run = $p->appendChild($drawing->createElementNS(self::NS_A, 'a:r'));
                $run->appendChild($props);
                $run->appendChild($drawing->createElementNS(self::NS_A, 'a:t'))->appendChild($drawing->createTextNode($text));
            }
            $end = $p->appendChild($drawing->createElementNS(self::NS_A, 'a:endParaRPr'));
            $end->setAttribute('lang', 'en-US');
            $end->setAttribute('sz', (string) $style['sz']);
        }
    }

    /** No white fill, text on the bottom: the value sits on its line without hiding anything. */
    private function seeThrough(DOMElement $anchor): void
    {
        foreach (iterator_to_array($anchor->getElementsByTagNameNS(self::NS_A, 'solidFill')) as $fill) {
            if ($fill->parentNode->localName === 'spPr') {
                $fill->parentNode->replaceChild($anchor->ownerDocument->createElementNS(self::NS_A, 'a:noFill'), $fill);
            }
        }
        $anchor->getElementsByTagNameNS(self::NS_A, 'bodyPr')->item(0)->setAttribute('anchor', 'b');
    }

    /** About how many characters fit across a box at this size. */
    private function capacity(DOMElement $anchor, int $size): int
    {
        $from = $this->point($anchor, 'from');
        $to = $this->point($anchor, 'to');
        $pixels = -$from['px'] + $to['px'];
        for ($column = $from['col']; $column < $to['col']; $column++) {
            $pixels += $this->columns[$column] ?? 64;
        }

        // Bold text at size pt averages about 0.5 em a character (96 dpi).
        return max(1, (int) floor($pixels / ($size / 100 * 0.5 * 96 / 72)));
    }

    /**
     * @return array{col: int, px: int}
     */
    private function point(DOMElement $anchor, string $edge): array
    {
        $node = $anchor->getElementsByTagNameNS(self::NS_XDR, $edge)->item(0);

        return [
            'col' => (int) $node->getElementsByTagNameNS(self::NS_XDR, 'col')->item(0)->textContent,
            'px' => intdiv((int) $node->getElementsByTagNameNS(self::NS_XDR, 'colOff')->item(0)->textContent, self::EMU_PER_PX),
        ];
    }

    /**
     * Moves a box: [column, pixels into it] and, optionally, [.., .., row, EMUs into it].
     *
     * @param  array<int, int>  $from
     * @param  array<int, int>  $to
     */
    private function place(DOMElement $anchor, array $from, array $to): void
    {
        foreach (['from' => $from, 'to' => $to] as $edge => $point) {
            $node = $anchor->getElementsByTagNameNS(self::NS_XDR, $edge)->item(0);
            $node->getElementsByTagNameNS(self::NS_XDR, 'col')->item(0)->textContent = (string) $point[0];
            $node->getElementsByTagNameNS(self::NS_XDR, 'colOff')->item(0)->textContent = (string) ($point[1] * self::EMU_PER_PX);
            if (isset($point[2])) {
                $node->getElementsByTagNameNS(self::NS_XDR, 'row')->item(0)->textContent = (string) $point[2];
                $node->getElementsByTagNameNS(self::NS_XDR, 'rowOff')->item(0)->textContent = (string) $point[3];
            }
        }
    }

    private function anchor(DOMDocument $drawing, string $name): DOMElement
    {
        $xpath = new DOMXPath($drawing);
        $xpath->registerNamespace('xdr', self::NS_XDR);
        $anchor = $xpath->query("//xdr:cNvPr[@name='{$name}']/ancestor::xdr:twoCellAnchor")->item(0);
        if (! $anchor instanceof DOMElement) {
            throw new RuntimeException("{$name} is missing from the CS Form No. 6 template.");
        }

        return $anchor;
    }

    private function cloneAnchor(DOMDocument $drawing, DOMElement $anchor, string $name): DOMElement
    {
        $copy = $anchor->cloneNode(true);
        $ids = array_map(fn ($node) => (int) $node->getAttribute('id'), iterator_to_array($drawing->getElementsByTagNameNS(self::NS_XDR, 'cNvPr')));
        $props = $copy->getElementsByTagNameNS(self::NS_XDR, 'cNvPr')->item(0);
        $props->setAttribute('id', (string) (max($ids) + 1));
        $props->setAttribute('name', $name);

        return $anchor->parentNode->appendChild($copy);
    }

    // ── Header logo ──────────────────────────────────────────────────

    /** The agency logo (Admin → Branding) in place of the template's "AGENCY LOGO" oval. */
    private function logo(ZipArchive $zip, DOMDocument $drawing): void
    {
        $oval = (new DOMXPath($drawing));
        $oval->registerNamespace('xdr', self::NS_XDR);
        foreach ($oval->query("//xdr:cNvPr[@name='Oval 3' or @name='TextBox 1']/ancestor::xdr:twoCellAnchor") as $placeholder) {
            $placeholder->parentNode->removeChild($placeholder);
        }

        $file = $this->logoFile();
        $size = $file ? @getimagesize($file) : false;
        if (! $file || ! $size || $size[2] !== IMAGETYPE_PNG) {
            $file = public_path(Branding::ASSETS['logo']['default']);
            $size = getimagesize($file);
        }
        $zip->addFromString('xl/media/image1.png', (string) file_get_contents($file));

        // 0.6 in tall before the rows are stretched (stretchOffsets), aspect kept.
        $height = 548640;
        $width = (int) round($height * $this->stretch * $size[0] / max(1, $size[1]));
        $startPx = 120;
        $endPx = $startPx + intdiv($width, self::EMU_PER_PX);
        $picture = $this->anchor($drawing, 'Picture 4');
        $columnC = $this->columns[2] ?? 126;
        $this->place($picture, [2, $startPx, 1, 38100], $endPx <= $columnC ? [2, $endPx, 1, 38100 + $height] : [3, $endPx - $columnC, 1, 38100 + $height]);
        foreach ($picture->getElementsByTagNameNS(self::NS_A, 'ext') as $extent) {
            if ($extent->hasAttribute('cx')) {
                $extent->setAttribute('cx', (string) $width);
                $extent->setAttribute('cy', (string) round($height * $this->stretch));
            }
        }
    }

    private function logoFile(): ?string
    {
        $saved = BrandAsset::query()->where('key', 'logo')->value('path');

        return $saved && Storage::disk('public')->exists($saved) ? Storage::disk('public')->path($saved) : null;
    }

    // ── Package ──────────────────────────────────────────────────────

    /**
     * The template's author names and its author's folder path go; the
     * agency and today's date come in.
     *
     * @param  array<string, mixed>  $form
     */
    private function scrubProperties(ZipArchive $zip, array $form): void
    {
        $agency = htmlspecialchars($form['agency']['name'], ENT_XML1);
        $now = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .'<dc:title>CS Form No. 6, Revised 2020 - '.htmlspecialchars($form['reference_number'], ENT_XML1).'</dc:title>'
            ."<dc:creator>{$agency}</dc:creator><cp:lastModifiedBy>{$agency}</cp:lastModifiedBy>"
            ."<dcterms:created xsi:type=\"dcterms:W3CDTF\">{$now}</dcterms:created><dcterms:modified xsi:type=\"dcterms:W3CDTF\">{$now}</dcterms:modified>"
            .'</cp:coreProperties>');

        $workbook = (string) $zip->getFromName('xl/workbook.xml');
        $zip->addFromString('xl/workbook.xml', preg_replace('/<mc:AlternateContent[^>]*><mc:Choice Requires="x15"><x15ac:absPath.*?<\/mc:AlternateContent>/s', '', $workbook) ?? $workbook);
    }

    private function load(ZipArchive $zip, string $part): DOMDocument
    {
        $xml = $zip->getFromName($part);
        if ($xml === false) {
            throw new RuntimeException("{$part} is missing from the CS Form No. 6 template.");
        }

        $document = new DOMDocument;
        $document->loadXML($xml, LIBXML_NONET);

        return $document;
    }

    private function xpath(DOMDocument $document): DOMXPath
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('m', self::NS_MAIN);

        return $xpath;
    }
}
