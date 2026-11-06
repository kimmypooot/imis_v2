<?php

namespace App\Support\HR\Pds;

use App\Models\Employee;
use App\Models\Pds\PdsLearningDevelopment;
use App\Models\User;
use App\Support\HR\LearningDevelopment\LdRecords;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Importing a PDS from the official Excel form: reads the workbook
 * (PdsWorkbookReader), checks every section with the same rules as the
 * editor (PdsWriter::rules), and holds the result — encrypted, for half
 * an hour — so HR can review it and then confirm. Nothing is saved until
 * `apply()`, and only the sections HR confirms, and only those without
 * errors, are written (a list section replaces the employee's rows).
 */
class PdsImport
{
    private const TTL_MINUTES = 30;

    private const LEARNING = 'learning_development';

    public function __construct(
        private readonly PdsWorkbookReader $reader,
        private readonly PdsWriter $writer,
        private readonly LdRecords $trainings,
    ) {}

    /**
     * @return array{token: string, file: string, warnings: array<int, string>, sections: array<int, array<string, mixed>>}
     */
    public function preview(Employee $employee, string $path, string $fileName, int $userId): array
    {
        $read = $this->reader->read($path);
        $warnings = $read['warnings'];

        if ($mismatch = $this->identityMismatch($employee, $read['identity'])) {
            $warnings[] = $mismatch;
        }

        $sections = [];
        $payloads = [];
        foreach (PdsSchema::editableSectionKeys() as $key) {
            $payload = $read['sections'][$key] ?? null;
            if ($payload === null) {
                continue;
            }

            $errors = $this->errors($employee, $key, $payload);
            $definition = PdsSchema::section($key);
            $payloads[$key] = $payload;

            $sections[] = [
                'key' => $key,
                'label' => $definition['label'],
                'kind' => $definition['kind'],
                'count' => $definition['kind'] === 'list' ? count($payload['rows']) : $this->filled($payload),
                'current' => $this->currentCount($employee, $key),
                'sample' => $this->sample($key, $payload),
                'errors' => $errors,
            ];
        }

        // Trainings are added to the L&D log, never replaced: anything already
        // logged (same title and start date) is skipped.
        $plan = $this->trainingPlan($employee, $read['sections'][self::LEARNING]['rows'] ?? []);
        $payloads[self::LEARNING] = ['rows' => $plan['new']];
        $sections[] = [
            'key' => self::LEARNING,
            'label' => PdsSchema::section(self::LEARNING)['label'],
            'kind' => 'list',
            'additive' => true,
            'count' => count($plan['new']),
            'skipped' => count($plan['skipped']),
            'skipped_items' => array_slice($plan['skipped'], 0, 25),
            'current' => $this->currentCount($employee, self::LEARNING),
            'sample' => array_slice(array_column($plan['new'], 'title'), 0, 3),
            'errors' => $plan['errors'],
        ];

        $token = Str::random(40);
        Cache::put($this->cacheKey($userId, $employee, $token), Crypt::encryptString(json_encode($payloads)), now()->addMinutes(self::TTL_MINUTES));

        return [
            'token' => $token,
            'file' => $fileName,
            'warnings' => $warnings,
            'sections' => $sections,
        ];
    }

    /**
     * Splits the workbook's trainings into those to add and those the
     * person already has, and checks the ones to add with the L&D rules.
     *
     * A training counts as already logged when it starts the same day and
     * its title is the same once case and punctuation are ignored, or when
     * it starts and ends the same days with the same hours and a mostly
     * shared title (the same seminar typed a little differently). Those are
     * listed in the preview so HR can see what was left out.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{new: array<int, array<string, mixed>>, skipped: array<int, array{title: string, matches: string}>, errors: array<int, string>}
     */
    private function trainingPlan(Employee $employee, array $rows): array
    {
        $rules = collect($this->trainings->bulkRules())->except(['employee_ids', 'employee_ids.*'])->all();
        $existing = PdsLearningDevelopment::query()->where('employee_id', $employee->id)->get(['title', 'date_from', 'date_to', 'hours']);
        $new = [];
        $seen = [];
        $skipped = [];
        $errors = [];

        foreach ($rows as $row) {
            $key = $this->normalized((string) ($row['title'] ?? '')).'|'.($row['date_from'] ?? '');

            if (isset($seen[$key])) {
                $skipped[] = ['title' => (string) $row['title'], 'matches' => 'listed twice in the file'];

                continue;
            }

            if ($match = $this->alreadyLogged($existing, $row)) {
                $skipped[] = ['title' => (string) $row['title'], 'matches' => $match->title];

                continue;
            }

            $seen[$key] = true;
            $new[] = $row;

            $validator = Validator::make($row, $rules, [], LdRecords::attributes());
            foreach ($validator->errors()->all() as $message) {
                $errors[] = '“'.Str::limit((string) ($row['title'] ?? 'Untitled'), 40).'”: '.$message;
            }
        }

        return ['new' => $new, 'skipped' => $skipped, 'errors' => array_values(array_unique($errors))];
    }

    /**
     * The logged training this row repeats, if any.
     *
     * @param  Collection<int, PdsLearningDevelopment>  $existing
     * @param  array<string, mixed>  $row
     */
    private function alreadyLogged($existing, array $row): ?PdsLearningDevelopment
    {
        if (! ($row['title'] ?? null) || ! ($row['date_from'] ?? null)) {
            return null;
        }

        $title = $this->normalized($row['title']);

        return $existing->first(function (PdsLearningDevelopment $logged) use ($row, $title) {
            if ($logged->date_from?->toDateString() !== $row['date_from']) {
                return false;
            }

            if ($this->normalized($logged->title) === $title) {
                return true;
            }

            return $logged->date_to?->toDateString() === ($row['date_to'] ?? null)
                && abs((float) $logged->hours - (float) ($row['hours'] ?? -1)) < 0.01
                && $this->sharedWords($logged->title, $row['title']) >= 0.5;
        });
    }

    /**
     * Lower case, punctuation gone, single spaces: "Well-Being" = "WELL BEING".
     */
    private function normalized(string $title): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($title)));
    }

    /**
     * Share of the shorter title's meaningful words that the other one has.
     */
    private function sharedWords(string $a, string $b): float
    {
        $filler = ['a', 'an', 'and', 'for', 'in', 'of', 'on', 'the', 'to', 'with'];
        $words = fn (string $title) => array_values(array_diff(array_unique(explode(' ', $this->normalized($title))), $filler, ['']));
        [$first, $second] = [$words($a), $words($b)];
        $smaller = min(count($first), count($second));

        return $smaller === 0 ? 0.0 : count(array_intersect($first, $second)) / $smaller;
    }

    /**
     * Saves the confirmed sections of a previewed import.
     *
     * @param  array<int, string>  $wanted  section keys
     * @return array<string, array<string, mixed>>|null section => what changed; null if the preview expired
     */
    public function apply(Employee $employee, string $token, array $wanted, User $actor): ?array
    {
        $userId = $actor->id;
        $key = $this->cacheKey($userId, $employee, $token);
        $stored = Cache::get($key);

        if ($stored === null) {
            return null;
        }

        $payloads = json_decode(Crypt::decryptString($stored), true);
        $saved = [];

        foreach (array_intersect(PdsSchema::editableSectionKeys(), $wanted) as $section) {
            $payload = $payloads[$section] ?? null;

            // A section with errors is never written, whatever the request says.
            if ($payload === null || $this->errors($employee, $section, $payload) !== []) {
                continue;
            }

            $saved[$section] = $this->writer->save($employee, $section, $this->validated($employee, $section, $payload));
        }

        if (in_array(self::LEARNING, $wanted, true)) {
            $plan = $this->trainingPlan($employee, $payloads[self::LEARNING]['rows'] ?? []);

            if ($plan['errors'] === [] && $plan['new'] !== []) {
                // Pending: the L&D reviewers still check them, as for any new entry.
                DB::transaction(fn () => collect($plan['new'])->each(fn (array $row) => $this->trainings->create($employee, $row, $actor, approved: false)));
                $saved[self::LEARNING] = ['added' => count($plan['new'])];
            }
        }

        Cache::forget($key);

        return $saved;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private function errors(Employee $employee, string $section, array $payload): array
    {
        $validator = $this->validator($employee, $section, $payload);
        $messages = [];

        foreach ($validator->errors()->messages() as $field => $list) {
            $row = preg_match('/^rows\.(\d+)\./', $field, $m) ? 'Row '.((int) $m[1] + 1).': ' : '';
            foreach ($list as $message) {
                $messages[] = $row.$message;
            }
        }

        return array_values(array_unique($messages));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validated(Employee $employee, string $section, array $payload): array
    {
        return $this->validator($employee, $section, $payload)->validate();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validator(Employee $employee, string $section, array $payload): \Illuminate\Validation\Validator
    {
        $definition = PdsSchema::section($section);
        $prefix = $definition['kind'] === 'list' ? 'rows.*.' : '';
        $names = collect($definition['fields'])
            ->reject(fn (array $field) => $field['type'] === 'heading')
            ->mapWithKeys(fn (array $field) => [$prefix.$field['key'] => strtolower(preg_replace('/^[\d.]+\s*|\s*\(.*\)$/', '', $field['label']))])
            ->all();

        return Validator::make($payload, $this->writer->rules($employee, $section), [], $names);
    }

    /**
     * How many answers a single-record section has filled in.
     *
     * @param  array<string, mixed>  $payload
     */
    private function filled(array $payload): int
    {
        return collect($payload)->filter(fn ($value) => $value !== null && $value !== '' && $value !== false)->count();
    }

    private function currentCount(Employee $employee, string $section): int
    {
        $definition = PdsSchema::section($section);

        if ($definition['kind'] !== 'list') {
            return 0;
        }

        return $definition['model']::query()
            ->where('employee_id', $employee->id)
            ->where($definition['scope'] ?? [])
            ->count();
    }

    /**
     * A few lines showing what was read, for the preview.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private function sample(string $section, array $payload): array
    {
        if (PdsSchema::section($section)['kind'] !== 'list') {
            return [];
        }

        $main = collect(PdsSchema::section($section)['fields'])->first(fn (array $field) => in_array($field['key'], ['full_name', 'school', 'eligibility', 'position_title', 'organization', 'detail', 'name'], true))['key'] ?? null;

        return collect($payload['rows'])->take(3)->map(fn (array $row) => (string) ($row[$main] ?? ''))->filter()->values()->all();
    }

    /**
     * Warns when the workbook seems to belong to someone else.
     *
     * @param  array{surname: ?string, first_name: ?string}  $identity
     */
    private function identityMismatch(Employee $employee, array $identity): ?string
    {
        $account = strtoupper($employee->user->name);
        $surname = strtoupper((string) $identity['surname']);
        $first = strtoupper((string) $identity['first_name']);

        if ($surname === '' && $first === '') {
            return null;
        }

        $firstWord = explode(' ', $first)[0] ?? '';

        if (($surname !== '' && ! str_contains($account, $surname)) || ($firstWord !== '' && ! str_contains($account, $firstWord))) {
            return "The workbook is for {$identity['first_name']} {$identity['surname']}, but this record belongs to {$employee->user->name}. Check that it is the right file before importing.";
        }

        return null;
    }

    private function cacheKey(int $userId, Employee $employee, string $token): string
    {
        return "pds-import:{$userId}:{$employee->id}:{$token}";
    }
}
