<?php

namespace App\Support\Activities;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Calendar of Activities as an Excel workbook: one worksheet per month
 * (tab named after the month), each in the reference sheet's layout — the
 * title across the seven columns, the header row (repeated on every
 * printed page), then date rows with a light fill followed by that day's
 * activities, and "Dates to be announced" last. Poppins throughout,
 * landscape, one page wide.
 */
class ActivityWorkbook
{
    /** Column headings, in the reference sheet's order. */
    public const HEADINGS = ['Date/Time', 'Mode', 'Venue', 'Participants', 'Activity', 'Responsible Office/Personnel/ Committee', 'Remarks'];

    /** Column widths in characters, in the print page's proportions (13/9/17/14/25/12/10 %). */
    private const WIDTHS = ['A' => 18, 'B' => 12.5, 'C' => 23.5, 'D' => 19.5, 'E' => 34.5, 'F' => 16.5, 'G' => 14];

    /** A light fill for date rows (the info background token's light value). */
    private const DATE_FILL = 'EAECFA';

    private const BORDER = '8A8D9E';

    /** The row field each column shows. */
    private const KEYS = ['A' => 'time', 'B' => 'mode', 'C' => 'venue', 'D' => 'participants', 'E' => 'activity', 'F' => 'responsible', 'G' => 'remarks'];

    /**
     * @param  array<int, array{title: string, days: array<int, array{label: string, holiday: ?string, rows: array<int, array<string, mixed>>}>, tba: array<int, array<string, mixed>>}>  $sheets  MonthSheet::build() results
     * @return string the XLSX bytes
     */
    public function build(array $sheets): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getDefaultStyle()->getFont()->setName('Poppins')->setSize(9);
        $spreadsheet->removeSheetByIndex(0);

        foreach ($sheets as $sheet) {
            $this->worksheet($spreadsheet->createSheet(), $sheet);
        }

        $spreadsheet->setActiveSheetIndex(0);

        $stream = fopen('php://memory', 'r+');
        (new Xlsx($spreadsheet))->save($stream);
        rewind($stream);
        $bytes = stream_get_contents($stream);
        fclose($stream);
        $spreadsheet->disconnectWorksheets();

        return $bytes;
    }

    /**
     * @param  array{title: string, days: array<int, array{label: string, holiday: ?string, rows: array<int, array<string, mixed>>}>, tba: array<int, array<string, mixed>>}  $sheet
     */
    private function worksheet(Worksheet $worksheet, array $sheet): void
    {
        // "CSC RO VIII CALENDAR OF ACTIVITIES for DECEMBER 2025" → "December".
        preg_match('/for (\S+) \d{4}$/', $sheet['title'], $match);
        $worksheet->setTitle(ucfirst(strtolower($match[1] ?? 'Sheet')));

        foreach (self::WIDTHS as $column => $width) {
            $worksheet->getColumnDimension($column)->setWidth($width);
        }

        $worksheet->setCellValue('A1', $sheet['title']);
        $worksheet->mergeCells('A1:G1');
        $worksheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
        $worksheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $worksheet->fromArray(self::HEADINGS, null, 'A2');
        $worksheet->getStyle('A2:G2')->getFont()->setBold(true);
        $worksheet->getStyle('A2:G2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $row = 3;

        foreach ($sheet['days'] as $day) {
            $row = $this->dateRow($worksheet, $row, $day['label']);
            $row = $this->rows($worksheet, $row, $day['rows']);
        }

        if ($sheet['tba'] !== []) {
            $row = $this->dateRow($worksheet, $row, 'Dates to be announced');
            $row = $this->rows($worksheet, $row, $sheet['tba']);
        }

        $lastRow = $row - 1;
        $table = $worksheet->getStyle("A2:G{$lastRow}");
        $table->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $table->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB(self::BORDER);

        $setup = $worksheet->getPageSetup();
        $setup->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
        $setup->setPaperSize(PageSetup::PAPERSIZE_A4);
        $setup->setFitToWidth(1);
        $setup->setFitToHeight(0);
        $setup->setRowsToRepeatAtTopByStartAndEnd(2, 2);
        $worksheet->getPageMargins()->setTop(0.5)->setBottom(0.5)->setLeft(0.4)->setRight(0.4);
        $worksheet->freezePane('A3');
    }

    private function dateRow(Worksheet $worksheet, int $row, string $label): int
    {
        $worksheet->setCellValue("A{$row}", $label);
        $worksheet->mergeCells("A{$row}:G{$row}");
        $style = $worksheet->getStyle("A{$row}:G{$row}");
        $style->getFont()->setBold(true);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::DATE_FILL);

        return $row + 1;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function rows(Worksheet $worksheet, int $row, array $rows): int
    {
        foreach ($rows as $entry) {
            // Written as text, so "8:00 AM - 10:00 AM" and the like are never read as numbers or dates.
            foreach (self::KEYS as $column => $key) {
                $worksheet->setCellValueExplicit("{$column}{$row}", (string) $entry[$key], DataType::TYPE_STRING);
            }

            if ($entry['time'] === 'HOLIDAY') {
                $worksheet->getStyle("A{$row}:G{$row}")->getFont()->setBold(true);
            }

            if ($entry['cancelled']) {
                $worksheet->getStyle("A{$row}:F{$row}")->getFont()->setStrikethrough(true);
            }

            $row++;
        }

        return $row;
    }
}
