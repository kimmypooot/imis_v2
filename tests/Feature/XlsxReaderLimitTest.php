<?php

namespace Tests\Feature;

use App\Support\Tables\XlsxReader;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class XlsxReaderLimitTest extends TestCase
{
    public function test_a_workbook_part_that_inflates_past_the_limit_is_refused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/sharedStrings.xml', '<sst>'.str_repeat(' ', 34 * 1024 * 1024).'</sst>');
        $zip->close();

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('too large');
            (new XlsxReader)->read($path);
        } finally {
            @unlink($path);
        }
    }
}
