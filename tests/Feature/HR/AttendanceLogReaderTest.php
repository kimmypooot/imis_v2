<?php

namespace Tests\Feature\HR;

use App\Support\HR\Dtr\AttendanceLogReader;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Reading a biometric device's .dat attendance log: tab-separated, no
 * header; only the ID and the time are used.
 */
class AttendanceLogReaderTest extends TestCase
{
    public function test_it_reads_tab_separated_rows_and_ignores_the_other_columns(): void
    {
        $result = $this->read("909\t2021-01-18 15:21:13\t12\t0\t0\t0\r\n906\t2026-03-04 20:41:36\t12\t1\t0\t0\r\n");

        $this->assertSame(0, $result['skipped']);
        $this->assertCount(2, $result['punches']);
        $this->assertSame('909', $result['punches'][0]['biometric_id']);
        $this->assertSame('2021-01-18 15:21:13', $result['punches'][0]['punched_at']->format('Y-m-d H:i:s'));
        $this->assertSame('906', $result['punches'][1]['biometric_id']);
    }

    public function test_blank_lines_and_a_missing_final_newline_are_fine(): void
    {
        $result = $this->read("\r\n909\t2021-01-18 15:21:13\t12\t0\t0\t0\r\n\r\n909\t2021-01-19 07:50:35\t12\t0\t0\t0");

        $this->assertSame(0, $result['skipped']);
        $this->assertCount(2, $result['punches']);
    }

    public function test_malformed_rows_are_counted_as_skipped_and_valid_rows_still_read(): void
    {
        $result = $this->read(implode("\r\n", [
            "909\t2021-01-18 15:21:13\t12\t0\t0\t0",
            '909',
            "ABC\t2021-01-18 15:21:13\t12\t0\t0\t0",
            "909\t2026-13-45 99:00:00\t12\t0\t0\t0",
            "1234567\t2021-01-18 15:21:13\t12\t0\t0\t0",
        ]));

        $this->assertCount(1, $result['punches']);
        $this->assertSame(4, $result['skipped']);
    }

    public function test_truncated_or_unparseable_times_are_skipped_instead_of_failing_the_read(): void
    {
        $result = $this->read(implode("\r\n", [
            "909\t2021-01-18 15:21:13\t12\t0\t0\t0",
            "909\t2020-02",
            "909\t",
            "909\tgarbage\t12",
            "909\t2026-01-01 10:00\t12",
            "909\t2026-01-01T10:00:00\t12",
        ]));

        $this->assertCount(1, $result['punches']);
        $this->assertSame(5, $result['skipped']);
    }

    public function test_a_utf8_bom_on_the_first_line_is_tolerated(): void
    {
        $result = $this->read("\xEF\xBB\xBF909\t2021-01-18 15:21:13\t12\t0\t0\t0\r\n");

        $this->assertSame(0, $result['skipped']);
        $this->assertSame('909', $result['punches'][0]['biometric_id']);
    }

    /**
     * @return array{punches: list<array{biometric_id: string, punched_at: Carbon}>, skipped: int}
     */
    private function read(string $contents): array
    {
        $path = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($path, $contents);

        try {
            return (new AttendanceLogReader)->read($path);
        } finally {
            unlink($path);
        }
    }
}
