<?php

namespace App\Support\Tables;

use Closure;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a query as CSV (the legacy DataTables "Excel/CSV" export) without
 * loading every row into memory. A UTF-8 BOM makes Excel read accented
 * names correctly. Cells starting with = + - @ are prefixed with ' so a
 * value can never run as a spreadsheet formula (CSV injection).
 */
class CsvExport
{
    /**
     * @param  array<int, string>  $headings
     * @param  Closure(mixed): array<int, mixed>  $row
     */
    public static function stream(string $name, array $headings, Builder $query, Closure $row): StreamedResponse
    {
        $filename = $name.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($headings, $query, $row) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            // No escape character: plain RFC 4180 quoting, as Excel reads it (PHP 8.4 wants it explicit).
            fputcsv($out, $headings, escape: '');

            foreach ($query->lazy(500) as $record) {
                fputcsv($out, array_map([self::class, 'cell'], $row($record)), escape: '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public static function cell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
