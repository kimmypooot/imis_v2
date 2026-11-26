<?php

namespace App\Support\HR\Pds;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * CS Form No. 212 (Revised 2026) as an Excel workbook: CSC's own workbook
 * (resources/templates/hr) with PdsForm's entries written into its cells,
 * its check boxes ticked, the dual-citizenship country chosen in its
 * drop-down, the photo in C4's frame, and every sheet set to print on
 * Folio (8.5 × 13 in), 0.25 in margins, one page wide, centred across.
 *
 * Lists longer than a continuation sheet (C5–C11) get more rows on that
 * sheet, the way people extend the form by hand (and PdsWorkbookReader
 * reads it back). Only the template's own XML is edited (no spreadsheet
 * package): the form's borders, fonts and wording stay as CSC issued them.
 */
class PdsFormWorkbook
{
    public const TEMPLATE = 'templates/hr/cs-form-212-revised-2026.xlsx';

    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const NS_PKG = 'http://schemas.openxmlformats.org/package/2006/relationships';

    private const NS_XDR = 'http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing';

    private const NS_A = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    /** Paper size 14 is Folio, 8.5 × 13 in. */
    private const FOLIO = '14';

    private const MARGIN = '0.25';

    /** The ID picture's true size, 3.5 × 4.5 cm, in EMU (360,000 per cm). */
    private const PHOTO_EMU = [1260000, 1620000];

    /** Sheet name → its part (the template's order). */
    private const PARTS = [
        'C1' => 1, 'C2' => 2, 'C3' => 3, 'C4' => 4, 'C5' => 5, 'C6' => 6,
        'C7' => 7, 'C8' => 8, 'C9' => 9, 'C10' => 10, 'C11' => 11,
    ];

    /** Sheets with check boxes, and their legacy (VML) drawing. */
    private const VML = ['C1' => 'xl/drawings/vmlDrawing1.vml', 'C4' => 'xl/drawings/vmlDrawing2.vml'];

    /** The country drop-down's list on C1 (column Q). */
    private const COUNTRY_LIST = [11, 217];

    /** @var array<int, array{name: string, sz: string}> cell style → its font */
    private array $fonts = [];

    /**
     * @param  array<int, array{sheet: string, cells: array<int, array<string, string>>, checks: array<int, string>}>  $pages  PdsForm::pages()
     * @param  string|null  $photo  the ID picture's file (JPEG or PNG)
     * @return string the .xlsx file's bytes
     */
    public function build(array $pages, ?string $photo = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pds');
        copy(resource_path(self::TEMPLATE), $path);

        try {
            $zip = new ZipArchive;
            if ($zip->open($path) !== true) {
                throw new RuntimeException('The CS Form No. 212 template could not be opened.');
            }

            $this->fonts = $this->cellFonts($this->load($zip, 'xl/styles.xml'));
            $workbook = (string) $zip->getFromName('xl/workbook.xml');

            foreach ($this->bySheet($pages) as $name => $sheetPages) {
                $part = 'xl/worksheets/sheet'.self::PARTS[$name].'.xml';
                $sheet = $this->load($zip, $part);
                $offsets = $this->makeRoom($sheet, $name, count($sheetPages), $workbook);

                $checks = [];
                foreach ($sheetPages as $i => $page) {
                    foreach ($page['cells'] as $cell) {
                        $this->write($sheet, $name, $this->shift($cell['at'], $offsets[$i]), $cell);
                    }
                    array_push($checks, ...$page['checks']);
                }

                if (isset(self::VML[$name])) {
                    $this->tick($zip, $sheet, $name, $checks, $sheetPages[0]);
                }
                if ($name === 'C4' && $photo !== null) {
                    $this->photo($zip, $photo);
                }

                $this->folio($sheet, count($sheetPages) > 1);
                $zip->addFromString($part, $sheet->saveXML());
            }

            $this->scrubProperties($zip, $workbook);
            $zip->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Every sheet (C1–C11), each with its pages in order; a sheet with no
     * page of its own still gets the Folio setup.
     *
     * @param  array<int, array<string, mixed>>  $pages
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function bySheet(array $pages): array
    {
        $sheets = array_fill_keys(array_keys(self::PARTS), []);
        foreach ($pages as $page) {
            $sheets[$page['sheet']][] = $page;
        }

        return array_map(fn (array $list) => $list ?: [['cells' => [], 'checks' => []]], $sheets);
    }

    // ── Cells ────────────────────────────────────────────────────────

    /**
     * Writes an entry into the top-left cell of its range, as an inline
     * string in the cell's own font. The dual-citizenship country is the
     * drop-down's choice instead (see tick()).
     *
     * @param  array<string, string>  $entry
     */
    private function write(DOMDocument $sheet, string $name, string $at, array $entry): void
    {
        if ($name === 'C1' && $at === 'J16:M16') {
            return;
        }

        $cell = $this->cell($sheet, explode(':', $at)[0]);
        while ($cell->firstChild) {
            $cell->removeChild($cell->firstChild);
        }
        $cell->setAttribute('t', 'inlineStr');
        $is = $cell->appendChild($sheet->createElementNS(self::NS_MAIN, 'is'));

        if (($entry['kind'] ?? null) !== 'signature') {
            $this->text($sheet, $is, $entry['text']);

            return;
        }

        // The e-signature note in black italic, not the box's red hint colour.
        $font = $this->fonts[(int) $cell->getAttribute('s')] ?? ['name' => 'Arial', 'sz' => '8'];
        $run = $is->appendChild($sheet->createElementNS(self::NS_MAIN, 'r'));
        $props = $run->appendChild($sheet->createElementNS(self::NS_MAIN, 'rPr'));
        // CT_RPrElt order: rFont, i, color, sz.
        $props->appendChild($sheet->createElementNS(self::NS_MAIN, 'rFont'))->setAttribute('val', $font['name']);
        $props->appendChild($sheet->createElementNS(self::NS_MAIN, 'i'));
        $props->appendChild($sheet->createElementNS(self::NS_MAIN, 'color'))->setAttribute('rgb', 'FF000000');
        $props->appendChild($sheet->createElementNS(self::NS_MAIN, 'sz'))->setAttribute('val', $font['sz']);
        $this->text($sheet, $run, $entry['text']);
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
        $existing = $xpath->query("//m:sheetData/m:row/m:c[@r='{$ref}']")->item(0);
        if ($existing instanceof DOMElement) {
            return $existing;
        }

        [$column, $number] = $this->split($ref);
        $row = $xpath->query("//m:sheetData/m:row[@r='{$number}']")->item(0);
        if (! $row instanceof DOMElement) {
            $row = $this->row($sheet, $number);
        }

        $cell = $sheet->createElementNS(self::NS_MAIN, 'c');
        $cell->setAttribute('r', $ref);
        foreach ($xpath->query('m:c', $row) as $sibling) {
            if ($this->columnIndex($this->split($sibling->getAttribute('r'))[0]) > $this->columnIndex($column)) {
                return $row->insertBefore($cell, $sibling);
            }
        }

        return $row->appendChild($cell);
    }

    /** A row the template leaves out, created in order. */
    private function row(DOMDocument $sheet, int $number): DOMElement
    {
        $data = $this->xpath($sheet)->query('//m:sheetData')->item(0);
        $row = $sheet->createElementNS(self::NS_MAIN, 'row');
        $row->setAttribute('r', (string) $number);
        foreach ($data->childNodes as $sibling) {
            if ($sibling instanceof DOMElement && (int) $sibling->getAttribute('r') > $number) {
                return $data->insertBefore($row, $sibling);
            }
        }

        return $data->appendChild($row);
    }

    /**
     * @return array<int, array{name: string, sz: string}>
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
                'sz' => $xpath->query('m:sz/@val', $font)->item(0)?->nodeValue ?? '8',
            ];
        }

        return $list;
    }

    // ── Longer lists: more rows on a continuation sheet ──────────────

    /**
     * Each page of a continuation sheet after the first continues below
     * the one before: the sheet's last list row is repeated as many times
     * as needed, and everything under it (signature, footer) moves down.
     *
     * @return array<int, int> rows to add to each page's entries
     */
    private function makeRoom(DOMDocument $sheet, string $name, int $pageCount, string &$workbook): array
    {
        if ($pageCount <= 1) {
            return [0];
        }

        $list = collect(PdsForm::LISTS)->first(fn (array $list) => $list['more'][0] === $name);
        [, $first, $perPage] = $list['more'];
        $last = $first + $perPage - 1;
        $added = ($pageCount - 1) * $perPage;
        $this->insertRows($sheet, $last, $added);

        // The print area grows with it; the sheet now prints on several pages.
        $sheetName = preg_quote($this->sheetName($workbook, self::PARTS[$name]), '/');
        $workbook = preg_replace_callback(
            '/(<definedName name="_xlnm.Print_Area" localSheetId="'.(self::PARTS[$name] - 1)."\">'?{$sheetName}'?!\\\$[A-Z]+\\\$\d+:\\\$[A-Z]+\\\$)(\d+)/",
            fn (array $m) => $m[1].((int) $m[2] + $added),
            $workbook,
        ) ?? $workbook;

        return array_map(fn (int $i) => $i * $perPage, range(0, $pageCount - 1));
    }

    private function sheetName(string $workbook, int $index): string
    {
        preg_match_all('/<sheet name="([^"]+)"/', $workbook, $names);

        return $names[1][$index - 1] ?? '';
    }

    private function insertRows(DOMDocument $sheet, int $after, int $count): void
    {
        $xpath = $this->xpath($sheet);

        foreach ($xpath->query('//m:sheetData/m:row') as $row) {
            $number = (int) $row->getAttribute('r');
            if ($number > $after) {
                $row->setAttribute('r', (string) ($number + $count));
                foreach ($xpath->query('m:c', $row) as $cell) {
                    [$column, $cellRow] = $this->split($cell->getAttribute('r'));
                    $cell->setAttribute('r', $column.($cellRow + $count));
                }
            }
        }

        $template = $xpath->query("//m:sheetData/m:row[@r='{$after}']")->item(0);
        if ($template instanceof DOMElement) {
            $previous = $template;
            for ($i = 1; $i <= $count; $i++) {
                $copy = $template->cloneNode(true);
                $copy->setAttribute('r', (string) ($after + $i));
                foreach ($xpath->query('m:c', $copy) as $cell) {
                    $cell->setAttribute('r', $this->split($cell->getAttribute('r'))[0].($after + $i));
                    $cell->removeAttribute('t');
                    while ($cell->firstChild) {
                        $cell->removeChild($cell->firstChild);
                    }
                }
                $previous = $previous->parentNode->insertBefore($copy, $previous->nextSibling);
            }
        }

        $merges = $xpath->query('//m:mergeCells')->item(0);
        if ($merges instanceof DOMElement) {
            foreach (iterator_to_array($xpath->query('m:mergeCell', $merges)) as $merge) {
                [$from, $to] = explode(':', $merge->getAttribute('ref'));
                [$fromColumn, $fromRow] = $this->split($from);
                [$toColumn, $toRow] = $this->split($to);
                if ($fromRow > $after) {
                    $merge->setAttribute('ref', $fromColumn.($fromRow + $count).':'.$toColumn.($toRow + $count));
                } elseif ($fromRow === $after && $toRow === $after) {
                    for ($i = 1; $i <= $count; $i++) {
                        $merges->appendChild($sheet->createElementNS(self::NS_MAIN, 'mergeCell'))->setAttribute('ref', $fromColumn.($after + $i).':'.$toColumn.($after + $i));
                    }
                }
            }
            $merges->setAttribute('count', (string) $xpath->query('m:mergeCell', $merges)->length);
        }

        $dimension = $xpath->query('//m:dimension')->item(0);
        $dimension?->parentNode->removeChild($dimension);
    }

    /** A cell reference or range moved down by $rows. */
    private function shift(string $at, int $rows): string
    {
        if ($rows === 0) {
            return $at;
        }

        return implode(':', array_map(function (string $ref) use ($rows) {
            [$column, $row] = $this->split($ref);

            return $column.($row + $rows);
        }, explode(':', $at)));
    }

    // ── Check boxes and the country drop-down ────────────────────────

    /**
     * Sets every check box on the sheet by its linked cell: ticked or
     * cleared, in the control properties, the legacy VML (older Excel and
     * LibreOffice) and the linked cell itself (TRUE/FALSE, what the
     * formulas and PdsWorkbookReader read).
     *
     * @param  array<int, string>  $checks  linked cells to tick
     * @param  array<string, mixed>  $page  the sheet's page (for the country)
     */
    private function tick(ZipArchive $zip, DOMDocument $sheet, string $name, array $checks, array $page): void
    {
        $rels = $this->load($zip, 'xl/worksheets/_rels/sheet'.self::PARTS[$name].'.xml.rels');
        $targets = [];
        foreach ($rels->documentElement->childNodes as $rel) {
            if ($rel instanceof DOMElement) {
                $targets[$rel->getAttribute('Id')] = 'xl/'.ltrim(str_replace('../', '', $rel->getAttribute('Target')), '/');
            }
        }

        $vml = (string) $zip->getFromName(self::VML[$name]);
        foreach ($sheet->getElementsByTagNameNS(self::NS_MAIN, 'control') as $control) {
            $part = $targets[$control->getAttributeNS(self::NS_REL, 'id')] ?? null;
            if (! $part) {
                continue;
            }
            $props = $this->load($zip, $part);
            $root = $props->documentElement;
            $link = str_replace('$', '', $root->getAttribute('fmlaLink'));
            $shape = '_x0000_s'.$control->getAttribute('shapeId');

            if ($root->getAttribute('objectType') === 'Drop') {
                $this->country($zip, $sheet, $props, $part, $link, $page);

                continue;
            }
            if ($root->getAttribute('objectType') !== 'CheckBox' || $link === '') {
                continue;
            }

            $checked = in_array($link, $checks, true);
            $checked ? $root->setAttribute('checked', 'Checked') : $root->removeAttribute('checked');
            $zip->addFromString($part, $props->saveXML());

            $cell = $this->cell($sheet, $link);
            while ($cell->firstChild) {
                $cell->removeChild($cell->firstChild);
            }
            $cell->setAttribute('t', 'b');
            $cell->appendChild($sheet->createElementNS(self::NS_MAIN, 'v', $checked ? '1' : '0'));

            $vml = preg_replace_callback(
                '/(<v:shape id="'.preg_quote($shape, '/').'".*?<x:ClientData ObjectType="Checkbox">)(.*?)(<\/x:ClientData>)/s',
                function (array $match) use ($checked) {
                    $body = preg_replace('/\s*<x:Checked>\d<\/x:Checked>/', '', $match[2]);

                    return $match[1].$body.($checked ? '<x:Checked>1</x:Checked>' : '').$match[3];
                },
                $vml,
            ) ?? $vml;
        }

        $zip->addFromString(self::VML[$name], $vml);
    }

    /**
     * The dual-citizenship country: the drop-down's choice (its linked
     * cell holds the item's position in the list in column Q). A country
     * not on CSC's list is left unchosen.
     *
     * @param  array<string, mixed>  $page
     */
    private function country(ZipArchive $zip, DOMDocument $sheet, DOMDocument $props, string $part, string $link, array $page): void
    {
        $country = collect($page['cells'])->firstWhere('at', 'J16:M16')['text'] ?? null;
        $position = 1;

        if ($country !== null) {
            $xpath = $this->xpath($sheet);
            $strings = $this->sharedStrings($zip);
            for ($row = self::COUNTRY_LIST[0]; $row <= self::COUNTRY_LIST[1]; $row++) {
                $cell = $xpath->query("//m:sheetData/m:row/m:c[@r='Q{$row}']")->item(0);
                $value = $cell instanceof DOMElement ? $this->cellText($cell, $strings) : '';
                if ($value !== '' && strcasecmp(trim($value), trim($country)) === 0) {
                    $position = $row - self::COUNTRY_LIST[0] + 1;
                    break;
                }
            }
        }

        $props->documentElement->setAttribute('sel', (string) $position);
        $zip->addFromString($part, $props->saveXML());

        $cell = $this->cell($sheet, $link);
        while ($cell->firstChild) {
            $cell->removeChild($cell->firstChild);
        }
        $cell->removeAttribute('t');
        $cell->appendChild($sheet->createElementNS(self::NS_MAIN, 'v', (string) $position));
    }

    /**
     * @return array<int, string>
     */
    private function sharedStrings(ZipArchive $zip): array
    {
        $doc = $this->load($zip, 'xl/sharedStrings.xml');
        $list = [];
        foreach ($this->xpath($doc)->query('//m:si') as $si) {
            $list[] = implode('', array_map(fn ($t) => $t->textContent, iterator_to_array($this->xpath($doc)->query('.//m:t', $si))));
        }

        return $list;
    }

    /**
     * @param  array<int, string>  $strings
     */
    private function cellText(DOMElement $cell, array $strings): string
    {
        $value = $cell->getElementsByTagNameNS(self::NS_MAIN, 'v')->item(0)?->textContent ?? '';

        return $cell->getAttribute('t') === 's' ? ($strings[(int) $value] ?? '') : $value;
    }

    // ── The photo ────────────────────────────────────────────────────

    /**
     * The ID picture at its true size over item 42's photo frame (the
     * template's "Passport-sized…" text box) on C4.
     */
    private function photo(ZipArchive $zip, string $file): void
    {
        $size = @getimagesize($file);
        if (! $size || ! in_array($size[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            return;
        }
        $extension = $size[2] === IMAGETYPE_PNG ? 'png' : 'jpeg';
        $zip->addFromString("xl/media/pds-photo.{$extension}", (string) file_get_contents($file));

        $types = (string) $zip->getFromName('[Content_Types].xml');
        if (! str_contains($types, "Extension=\"{$extension}\"")) {
            $types = str_replace('<Default Extension="xml"', "<Default Extension=\"{$extension}\" ContentType=\"image/{$extension}\"/><Default Extension=\"xml\"", $types);
            $zip->addFromString('[Content_Types].xml', $types);
        }

        $relsPart = 'xl/drawings/_rels/drawing2.xml.rels';
        $rels = $zip->getFromName($relsPart) ?: '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="'.self::NS_PKG.'"></Relationships>';
        $rels = str_replace('</Relationships>', "<Relationship Id=\"rIdPdsPhoto\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/image\" Target=\"../media/pds-photo.{$extension}\"/></Relationships>", $rels);
        $zip->addFromString($relsPart, $rels);

        $drawing = $this->load($zip, 'xl/drawings/drawing2.xml');
        $xpath = new DOMXPath($drawing);
        $xpath->registerNamespace('xdr', self::NS_XDR);
        $frame = $xpath->query("//xdr:cNvPr[@name='Text Box 100']/ancestor::xdr:twoCellAnchor")->item(0);
        if (! $frame instanceof DOMElement) {
            return;
        }

        // Exactly 3.5 × 4.5 cm, laid over the frame (which is a little smaller) as on
        // the print page: centred across it, 6.4 pt below the frame's bottom. The offsets
        // into column L and row 50 were measured on Excel's own Folio printout.
        $anchor = $drawing->createElementNS(self::NS_XDR, 'xdr:oneCellAnchor');
        $from = $anchor->appendChild($drawing->createElementNS(self::NS_XDR, 'xdr:from'));
        foreach (['col' => 11, 'colOff' => 212146, 'row' => 49, 'rowOff' => 193040] as $name => $value) {
            $from->appendChild($drawing->createElementNS(self::NS_XDR, "xdr:{$name}", (string) $value));
        }
        $extent = $anchor->appendChild($drawing->createElementNS(self::NS_XDR, 'xdr:ext'));
        $extent->setAttribute('cx', (string) self::PHOTO_EMU[0]);
        $extent->setAttribute('cy', (string) self::PHOTO_EMU[1]);
        $pic = $anchor->appendChild($drawing->createElementNS(self::NS_XDR, 'xdr:pic'));
        $nv = $pic->appendChild($drawing->createElementNS(self::NS_XDR, 'xdr:nvPicPr'));
        $c = $nv->appendChild($drawing->createElementNS(self::NS_XDR, 'xdr:cNvPr'));
        $c->setAttribute('id', '9001');
        $c->setAttribute('name', 'PDS Photo');
        $nv->appendChild($drawing->createElementNS(self::NS_XDR, 'xdr:cNvPicPr'))
            ->appendChild($drawing->createElementNS(self::NS_A, 'a:picLocks'))->setAttribute('noChangeAspect', '1');
        $fill = $pic->appendChild($drawing->createElementNS(self::NS_XDR, 'xdr:blipFill'));
        $fill->appendChild($drawing->createElementNS(self::NS_A, 'a:blip'))->setAttributeNS(self::NS_REL, 'r:embed', 'rIdPdsPhoto');
        $fill->appendChild($drawing->createElementNS(self::NS_A, 'a:stretch'))->appendChild($drawing->createElementNS(self::NS_A, 'a:fillRect'));
        $shape = $pic->appendChild($drawing->createElementNS(self::NS_XDR, 'xdr:spPr'));
        $shape->appendChild($drawing->createElementNS(self::NS_A, 'a:prstGeom'))->setAttribute('prst', 'rect');
        // A 1 pt black stroke, like the frame's (12,700 EMU per point).
        $line = $shape->appendChild($drawing->createElementNS(self::NS_A, 'a:ln'));
        $line->setAttribute('w', '12700');
        $line->appendChild($drawing->createElementNS(self::NS_A, 'a:solidFill'))
            ->appendChild($drawing->createElementNS(self::NS_A, 'a:srgbClr'))->setAttribute('val', '000000');
        $anchor->appendChild($drawing->createElementNS(self::NS_XDR, 'xdr:clientData'));

        $frame->parentNode->insertBefore($anchor, $frame->nextSibling);
        $zip->addFromString('xl/drawings/drawing2.xml', $drawing->saveXML());
    }

    // ── Page setup and package ───────────────────────────────────────

    /**
     * Folio, portrait, quarter-inch margins, no header or footer, centred
     * across; one page (one page wide when a continuation sheet has grown),
     * with the template's A4 printer settings dropped so they can't
     * override the paper.
     */
    private function folio(DOMDocument $sheet, bool $tall): void
    {
        $xpath = $this->xpath($sheet);
        $root = $sheet->documentElement;

        $sheetPr = $xpath->query('m:sheetPr', $root)->item(0) ?? $root->insertBefore($sheet->createElementNS(self::NS_MAIN, 'sheetPr'), $root->firstChild);
        $fit = $xpath->query('m:pageSetUpPr', $sheetPr)->item(0) ?? $sheetPr->appendChild($sheet->createElementNS(self::NS_MAIN, 'pageSetUpPr'));
        $fit->setAttribute('fitToPage', '1');

        $margins = $xpath->query('m:pageMargins', $root)->item(0);
        foreach (['left', 'right', 'top', 'bottom'] as $side) {
            $margins->setAttribute($side, self::MARGIN);
        }
        $margins->setAttribute('header', '0');
        $margins->setAttribute('footer', '0');

        $options = $xpath->query('m:printOptions', $root)->item(0) ?? $root->insertBefore($sheet->createElementNS(self::NS_MAIN, 'printOptions'), $margins);
        $options->setAttribute('horizontalCentered', '1');
        $options->removeAttribute('verticalCentered');

        $setup = $xpath->query('m:pageSetup', $root)->item(0);
        if (! $setup instanceof DOMElement) {
            $setup = $root->insertBefore($sheet->createElementNS(self::NS_MAIN, 'pageSetup'), $margins->nextSibling);
        }
        $setup->removeAttributeNS(self::NS_REL, 'id');
        $setup->removeAttribute('scale');
        $setup->setAttribute('paperSize', self::FOLIO);
        $setup->setAttribute('orientation', 'portrait');
        $setup->setAttribute('fitToWidth', '1');
        $setup->setAttribute('fitToHeight', $tall ? '0' : '1');
    }

    /** The template author's names and folder path go. */
    private function scrubProperties(ZipArchive $zip, string $workbook): void
    {
        $now = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .'<dc:title>CS Form No. 212 (Revised 2026) Personal Data Sheet</dc:title>'
            ."<dcterms:created xsi:type=\"dcterms:W3CDTF\">{$now}</dcterms:created><dcterms:modified xsi:type=\"dcterms:W3CDTF\">{$now}</dcterms:modified>"
            .'</cp:coreProperties>');

        $zip->addFromString('xl/workbook.xml', preg_replace('/<mc:AlternateContent[^>]*><mc:Choice Requires="x15"><x15ac:absPath.*?<\/mc:AlternateContent>/s', '', $workbook) ?? $workbook);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * @return array{0: string, 1: int}
     */
    private function split(string $ref): array
    {
        preg_match('/^([A-Z]+)(\d+)$/', $ref, $parts);

        return [$parts[1], (int) $parts[2]];
    }

    private function columnIndex(string $column): int
    {
        $index = 0;
        foreach (str_split($column) as $letter) {
            $index = $index * 26 + ord($letter) - 64;
        }

        return $index;
    }

    private function load(ZipArchive $zip, string $part): DOMDocument
    {
        $xml = $zip->getFromName($part);
        if ($xml === false) {
            throw new RuntimeException("{$part} is missing from the CS Form No. 212 template.");
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
