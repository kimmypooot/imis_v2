<?php

namespace App\Support\HR\Dtr;

use Generator;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * DTR: reads a biometric device's .dat attendance log. Tab-separated, no
 * header: ID, date-time, verify mode, in/out flag, workcode, reserved.
 * Only the ID and the time are used: the in/out flag is unreliable, so the
 * DTR works out in and out from the punches themselves. A device's whole
 * history can be large, so `stream()` yields one punch at a time; `read()`
 * collects them for small files and tests.
 */
class AttendanceLogReader
{
    /**
     * @return array{punches: list<array{biometric_id: string, punched_at: Carbon}>, skipped: int}
     */
    public function read(string $path): array
    {
        $skipped = 0;
        $punches = [];

        foreach ($this->stream($path, $skipped) as $punch) {
            $punches[] = ['biometric_id' => $punch['biometric_id'], 'punched_at' => Carbon::parse($punch['punched_at'], config('app.timezone'))];
        }

        return ['punches' => $punches, 'skipped' => $skipped];
    }

    /**
     * Yields each readable punch as an ID and a `Y-m-d H:i:s` time (local
     * time, kept as a string). Unreadable rows are counted in `$skipped`,
     * which is final once the generator has finished.
     *
     * @param-out int $skipped
     *
     * @return Generator<int, array{biometric_id: string, punched_at: string}>
     */
    public function stream(string $path, int &$skipped): Generator
    {
        $skipped = 0;
        $handle = fopen($path, 'rb');
        $first = true;

        try {
            while (($line = fgets($handle)) !== false) {
                if ($first) {
                    $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
                    $first = false;
                }

                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $punch = $this->parse($line);
                if ($punch === null) {
                    $skipped++;

                    continue;
                }

                yield $punch;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array{biometric_id: string, punched_at: string}|null
     */
    private function parse(string $line): ?array
    {
        $fields = explode("\t", $line);
        if (count($fields) < 2) {
            return null;
        }

        $id = trim($fields[0]);
        if (! preg_match('/^\d{1,6}$/', $id)) {
            return null;
        }

        $time = trim($fields[1]);
        if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $time)) {
            return null;
        }

        try {
            $punchedAt = Carbon::createFromFormat('Y-m-d H:i:s', $time, config('app.timezone'));
        } catch (Throwable) {
            return null;
        }

        if ($punchedAt === false || $punchedAt->format('Y-m-d H:i:s') !== $time) {
            return null;
        }

        return ['biometric_id' => $id, 'punched_at' => $time];
    }
}
