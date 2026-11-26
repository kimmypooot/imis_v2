<?php

namespace App\Support\HR\Dtr;

use App\Models\BiometricImport;
use App\Models\BiometricPunch;
use App\Models\Employee;
use App\Models\User;
use App\Support\Settings\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * DTR: stores a biometric device's attendance log. Every punch is kept once
 * per ID and time, so uploading the same file again (or a larger one that
 * includes it) adds only what is new. The file is streamed and stored in
 * chunks, so a device's whole history does not have to fit in memory.
 *
 * A punch belongs to whoever's record carries its biometric ID
 * (`employees.biometric_id`). That is looked up when a DTR is built, so an
 * ID can be given to an employee after its punches were uploaded.
 */
class BiometricImporter
{
    private const CHUNK = 1000;

    public function __construct(private readonly AttendanceLogReader $reader = new AttendanceLogReader) {}

    /** Punches left out of the last import because their ID is on no profile. */
    public int $ignored = 0;

    /**
     * @param  bool  $knownOnly  Keep only punches whose ID is on an employee's
     *                           profile, to keep the table small. Left-out
     *                           punches return when the file is uploaded again
     *                           after the ID is added.
     */
    public function import(string $path, string $originalName, User $uploader, bool $knownOnly = false): BiometricImport
    {
        $this->ignored = 0;
        $known = $knownOnly
            ? Employee::query()->whereNotNull('biometric_id')->pluck('biometric_id')->map(fn ($id) => (string) $id)->flip()
            : null;
        // A device's whole history may come along; nothing before the DTR start date is ever used.
        $start = Settings::get('dtr_punches_from');

        return DB::transaction(function () use ($path, $originalName, $uploader, $known, $start) {
            $import = BiometricImport::query()->create([
                'user_id' => $uploader->id,
                'file_name' => $originalName,
                'file_hash' => hash_file('sha256', $path),
            ]);

            $skipped = 0;
            $before = 0;
            $read = 0;
            $new = 0;
            $first = null;
            $last = null;
            $buffer = [];
            $now = now();

            foreach ($this->reader->stream($path, $skipped) as $punch) {
                $read++;

                if ($start && substr((string) $punch['punched_at'], 0, 10) < $start) {
                    $before++;

                    continue;
                }
                if ($known !== null && ! $known->has((string) $punch['biometric_id'])) {
                    $this->ignored++;

                    continue;
                }

                $first = $first === null || $punch['punched_at'] < $first ? $punch['punched_at'] : $first;
                $last = $last === null || $punch['punched_at'] > $last ? $punch['punched_at'] : $last;
                $buffer[] = [
                    'biometric_id' => $punch['biometric_id'],
                    'punched_at' => $punch['punched_at'],
                    'biometric_import_id' => $import->id,
                    'created_at' => $now,
                ];

                if (count($buffer) >= self::CHUNK) {
                    $new += BiometricPunch::query()->insertOrIgnore($buffer);
                    $buffer = [];
                }
            }

            if ($buffer !== []) {
                $new += BiometricPunch::query()->insertOrIgnore($buffer);
            }

            // The punches this upload added carry its id (one already on file keeps its first upload's).
            $byMonth = [];
            foreach (BiometricPunch::query()->where('biometric_import_id', $import->id)->select(['id', 'punched_at'])->lazyById(2000) as $added) {
                $key = $added->punched_at->format('Y-m');
                $byMonth[$key] = ($byMonth[$key] ?? 0) + 1;
            }
            krsort($byMonth);

            $import->update([
                'rows_read' => $read,
                'rows_new' => $new,
                'rows_duplicate' => $read - $before - $this->ignored - $new,
                'rows_skipped' => $skipped,
                'rows_ignored' => $this->ignored,
                'rows_before_start' => $before,
                'new_by_month' => $byMonth,
                'first_punch_at' => $first,
                'last_punch_at' => $last,
            ]);

            return $import;
        });
    }

    /**
     * Deletes the stored punches of the given IDs, but only those no
     * employee's record carries: a punch that counts toward someone's DTR is
     * never removed. Used to clear separated, resigned or retired staff.
     *
     * @param  array<int, string>  $ids
     * @return array{ids: array<int, string>, punches: int} the IDs actually cleared
     */
    public function purgeUnmatched(array $ids): array
    {
        $allowed = $this->unmatchedIds()->pluck('biometric_id')->intersect(array_map('strval', $ids))->values();
        $punches = 0;

        foreach ($allowed->chunk(500) as $chunk) {
            $punches += BiometricPunch::query()->whereIn('biometric_id', $chunk->all())->delete();
        }

        return ['ids' => $allowed->all(), 'punches' => $punches];
    }

    /**
     * IDs with punches on file that no employee's record carries, for HRD
     * to fill in on the right profiles (or leave, for a test or visitor
     * enrolment).
     *
     * @return Collection<int, array{biometric_id: string, punches: int, first_at: string, last_at: string}>
     */
    public function unmatchedIds(): Collection
    {
        return BiometricPunch::query()
            ->whereNotIn('biometric_id', Employee::query()->whereNotNull('biometric_id')->select('biometric_id'))
            ->groupBy('biometric_id')
            ->selectRaw('biometric_id, count(*) as punches, min(punched_at) as first_at, max(punched_at) as last_at')
            ->get()
            ->sortBy(fn ($row) => (int) $row->biometric_id)
            ->map(fn ($row) => [
                'biometric_id' => (string) $row->biometric_id,
                'punches' => (int) $row->punches,
                'first_at' => (string) $row->first_at,
                'last_at' => (string) $row->last_at,
            ])
            ->values();
    }
}
