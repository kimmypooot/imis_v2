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
     * What uploading this file would do, without saving anything: the same
     * reading and rules as import() (unreadable lines, the DTR start date,
     * IDs on no profile when $knownOnly), then which punches are new and
     * which are already on file. Per Biometric ID: whose it is, how many
     * punches, how many new, and the date range; plus the first lines.
     *
     * @return array{counts: array{read: int, unreadable: int, before_start: int, left_out: int, already: int, new: int}, new_by_month: array<string, int>, months: list<array{month: string, punches: int, already: int, new: int, skipped: int}>, first_at: ?string, last_at: ?string, people: list<array<string, mixed>>, sample: list<array<string, mixed>>, sample_limit: int}
     */
    public function preview(string $path, bool $knownOnly = false): array
    {
        $names = Employee::query()->whereNotNull('biometric_id')->with('user:id,name')->get(['id', 'user_id', 'biometric_id'])
            ->mapWithKeys(fn (Employee $employee) => [(string) $employee->biometric_id => ['employee_id' => $employee->id, 'name' => $employee->user?->name]]);
        $start = Settings::get('dtr_punches_from');
        $counts = ['read' => 0, 'unreadable' => 0, 'before_start' => 0, 'left_out' => 0, 'already' => 0, 'new' => 0];
        $byMonth = [];
        $people = [];
        $sample = [];
        $first = null;
        $last = null;
        $buffer = [];
        $sampleLimit = 200;

        // Which buffered punches are already stored, then tally each one.
        // Per month: punches in the file, already on file, new, and skipped (before the start date or left out).
        $months = [];
        $flush = function () use (&$buffer, &$counts, &$byMonth, &$months, &$people, &$sample) {
            if ($buffer === []) {
                return;
            }
            $stored = BiometricPunch::query()
                ->whereIn('biometric_id', array_unique(array_column($buffer, 'biometric_id')))
                ->whereBetween('punched_at', [min(array_column($buffer, 'punched_at')), max(array_column($buffer, 'punched_at'))])
                ->get(['biometric_id', 'punched_at'])
                ->mapWithKeys(fn (BiometricPunch $punch) => [$punch->biometric_id.'|'.$punch->punched_at->format('Y-m-d H:i:s') => true]);

            foreach ($buffer as $punch) {
                $isNew = ! $stored->has($punch['biometric_id'].'|'.$punch['punched_at']);
                $counts[$isNew ? 'new' : 'already']++;
                $months[substr($punch['punched_at'], 0, 7)][$isNew ? 'new' : 'already']++;
                $person = &$people[$punch['biometric_id']];
                $person['new'] = ($person['new'] ?? 0) + ($isNew ? 1 : 0);
                if ($isNew) {
                    $month = substr($punch['punched_at'], 0, 7);
                    $byMonth[$month] = ($byMonth[$month] ?? 0) + 1;
                }
                unset($person);
                if ($punch['sample'] !== null) {
                    $sample[$punch['sample']]['status'] = $isNew ? 'new' : 'already';
                }
            }
            $buffer = [];
        };

        $skipped = 0;
        foreach ($this->reader->stream($path, $skipped) as $punch) {
            $counts['read']++;
            $id = (string) $punch['biometric_id'];
            $person = &$people[$id];
            $person['biometric_id'] = $id;
            $person['punches'] = ($person['punches'] ?? 0) + 1;
            $person['first_at'] = min($person['first_at'] ?? $punch['punched_at'], $punch['punched_at']);
            $person['last_at'] = max($person['last_at'] ?? $punch['punched_at'], $punch['punched_at']);
            unset($person);
            $monthKey = substr($punch['punched_at'], 0, 7);
            $months[$monthKey] ??= ['month' => $monthKey, 'punches' => 0, 'already' => 0, 'new' => 0, 'skipped' => 0];
            $months[$monthKey]['punches']++;

            if ($start && substr($punch['punched_at'], 0, 10) < $start) {
                $counts['before_start']++;
                $months[$monthKey]['skipped']++;
                if (count($sample) < $sampleLimit) {
                    $sample[] = [...$punch, 'status' => 'before_start'];
                }

                continue;
            }
            if ($knownOnly && ! $names->has($id)) {
                $counts['left_out']++;
                $months[$monthKey]['skipped']++;
                if (count($sample) < $sampleLimit) {
                    $sample[] = [...$punch, 'status' => 'left_out'];
                }

                continue;
            }

            $first = $first === null || $punch['punched_at'] < $first ? $punch['punched_at'] : $first;
            $last = $last === null || $punch['punched_at'] > $last ? $punch['punched_at'] : $last;
            // Kept in the file's order: its status is filled in once it has been checked.
            $sampleIndex = null;
            if (count($sample) < $sampleLimit) {
                $sampleIndex = count($sample);
                $sample[] = [...$punch, 'status' => null];
            }
            $buffer[] = [...$punch, 'sample' => $sampleIndex];
            if (count($buffer) >= self::CHUNK) {
                $flush();
            }
        }
        $flush();
        $counts['unreadable'] = $skipped;
        krsort($byMonth);
        krsort($months);

        $people = collect($people)->map(fn (array $person) => [
            ...$person,
            'new' => $person['new'] ?? 0,
            'employee_id' => $names[$person['biometric_id']]['employee_id'] ?? null,
            'name' => $names[$person['biometric_id']]['name'] ?? null,
        ])->sortBy([['new', 'desc'], ['biometric_id', 'asc']])->values()->all();

        $sample = array_map(fn (array $row) => [...$row, 'name' => $names[(string) $row['biometric_id']]['name'] ?? null], $sample);

        return ['counts' => $counts, 'new_by_month' => $byMonth, 'months' => array_values($months), 'first_at' => $first, 'last_at' => $last, 'people' => $people, 'sample' => $sample, 'sample_limit' => $sampleLimit];
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
