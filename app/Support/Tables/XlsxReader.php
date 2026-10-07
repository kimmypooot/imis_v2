<?php

namespace App\Support\Tables;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Reads the cell values of an Excel workbook (.xlsx) without a spreadsheet
 * package: an .xlsx is a zip of XML parts, and only values are needed —
 * no styles, formulas or merged-cell geometry. Text comes back trimmed
 * with inner whitespace collapsed; numbers (Excel dates included) come
 * back as the stored string, e.g. "46023" for 1 January 2026 (see
 * excelDate()).
 */
class XlsxReader
{
    private const RELATIONSHIPS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * @return array<string, array<int, array<string, string>>> sheet name => row number => column letter => value
     */
    public function read(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('The file is not an Excel workbook (.xlsx).');
        }

        try {
            $strings = $this->sharedStrings($zip);
            $workbook = $this->xml($zip, 'xl/workbook.xml');
            $targets = [];
            foreach ($this->xml($zip, 'xl/_rels/workbook.xml.rels')->Relationship as $relationship) {
                $targets[(string) $relationship['Id']] = (string) $relationship['Target'];
            }

            $sheets = [];
            foreach ($workbook->sheets->sheet as $sheet) {
                $id = (string) $sheet->attributes(self::RELATIONSHIPS)['id'];
                $target = $targets[$id] ?? null;
                if ($target === null) {
                    continue;
                }
                // Targets are relative to xl/ ("worksheets/sheet1.xml") or absolute ("/xl/worksheets/…").
                $part = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
                $sheets[(string) $sheet['name']] = $this->rows($this->xml($zip, $part), $strings);
            }

            return $sheets;
        } finally {
            $zip->close();
        }
    }

    /**
     * Excel stores dates as days since 30 December 1899 (the 1900 date
     * system). Returns Y-m-d, or null if the value isn't such a number.
     */
    public static function excelDate(?string $value): ?string
    {
        if ($value === null || ! preg_match('/^\d+(\.\d+)?$/', $value) || (float) $value < 1) {
            return null;
        }

        return (new \DateTimeImmutable('1899-12-30'))->modify('+'.(int) $value.' days')->format('Y-m-d');
    }

    /**
     * @return array<int, string>
     */
    private function sharedStrings(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) {
            return [];
        }

        $strings = [];
        foreach ($this->xml($zip, 'xl/sharedStrings.xml')->si as $item) {
            $strings[] = $this->text($item);
        }

        return $strings;
    }

    /**
     * @param  array<int, string>  $strings
     * @return array<int, array<string, string>>
     */
    private function rows(SimpleXMLElement $sheet, array $strings): array
    {
        $rows = [];
        foreach ($sheet->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $cell) {
                $value = match ((string) $cell['t']) {
                    's' => $strings[(int) $cell->v] ?? '',
                    'inlineStr' => $this->text($cell->is),
                    default => (string) $cell->v,
                };
                $value = trim(preg_replace('/\s+/u', ' ', $value));

                if ($value !== '' && preg_match('/^[A-Z]+/', (string) $cell['r'], $column)) {
                    $cells[$column[0]] = $value;
                }
            }

            if ($cells !== []) {
                $rows[(int) $row['r']] = $cells;
            }
        }

        return $rows;
    }

    /** A string item: plain (<t>) or rich text runs (<r><t>). */
    private function text(SimpleXMLElement $item): string
    {
        if (isset($item->t)) {
            return (string) $item->t;
        }

        $text = '';
        foreach ($item->r as $run) {
            $text .= (string) $run->t;
        }

        return $text;
    }

    private function xml(ZipArchive $zip, string $part): SimpleXMLElement
    {
        $contents = $zip->getFromName($part);
        $xml = $contents === false ? false : simplexml_load_string($contents);

        if ($xml === false) {
            throw new RuntimeException("The workbook is missing or has a damaged part ({$part}).");
        }

        return $xml;
    }
}
