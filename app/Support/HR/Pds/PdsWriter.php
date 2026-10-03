<?php

namespace App\Support\HR\Pds;

use App\Models\Employee;
use App\Models\Pds\PdsEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The only writer of PDS data. Saves one section at a time and reports
 * what changed — field names and row counts, never values, because PDS
 * values are sensitive and the summary goes into the audit log.
 */
class PdsWriter
{
    /**
     * Validation rules for saving `$section` for `$employee`.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(Employee $employee, string $section): array
    {
        $definition = PdsSchema::section($section);

        if ($definition['kind'] !== 'list') {
            return PdsSchema::rules($section);
        }

        $rowRules = PdsSchema::rules($section, 'rows.*.');

        // An end date can't precede its (required) start date.
        $required = collect($definition['fields'])->filter(fn ($field) => $field['required'] ?? false)->pluck('key');
        if (isset($rowRules['rows.*.date_to']) && $required->contains('date_from')) {
            $rowRules['rows.*.date_to'][] = 'after_or_equal:rows.*.date_from';
        }

        return [
            'rows' => array_filter(['present', 'array', isset($definition['max_rows']) ? 'max:'.$definition['max_rows'] : null]),
            // A row id must be one of this employee's own rows in this
            // section — never someone else's.
            'rows.*.id' => ['nullable', 'integer', Rule::in($this->entries($employee, $section)->pluck('id')->all())],
            ...$rowRules,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed> what changed (for the audit log)
     */
    public function save(Employee $employee, string $section, array $validated): array
    {
        return DB::transaction(fn () => match (PdsSchema::section($section)['kind']) {
            'profile' => $this->saveProfile($employee, $section, $validated),
            'questions' => $this->saveQuestions($employee, $validated),
            'list' => $this->saveList($employee, $section, $validated['rows']),
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{changed: array<int, string>}
     */
    private function saveProfile(Employee $employee, string $section, array $validated): array
    {
        $values = PdsSchema::dropInapplicable($section, $this->onlyData($section, $validated));
        $changed = [];

        $profile = $employee->pdsProfile()->firstOrNew();
        $profile->fill(collect($values)->only(PdsSchema::dataKeys($section, 'pds'))->all());
        $changed = array_keys($profile->getDirty());
        $profile->employee()->associate($employee);
        $profile->save();

        // The name extension lives on the account (one display name).
        if ($userKeys = PdsSchema::dataKeys($section, 'user')) {
            $user = $employee->user;
            $user->fill(collect($values)->only($userKeys)->all());
            $changed = [...$changed, ...array_keys($user->getDirty())];
            $user->save();
        }

        return ['changed' => array_values(array_diff($changed, ['employee_id', 'name']))];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{changed: array<int, string>}
     */
    private function saveQuestions(Employee $employee, array $validated): array
    {
        $answers = PdsSchema::dropInapplicable('questions', $this->onlyData('questions', $validated));

        $profile = $employee->pdsProfile()->firstOrNew();
        $before = $profile->questions ?? [];
        $profile->questions = $answers;
        $profile->employee()->associate($employee);
        $profile->save();

        $changed = collect($answers)->filter(fn ($value, $key) => ($before[$key] ?? null) !== $value)->keys()->values()->all();

        return ['changed' => $changed];
    }

    /**
     * Syncs a repeating section to the submitted rows, in their order:
     * rows with an id are updated, rows without one are added, and this
     * employee's rows missing from the submission are removed.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{added: int, updated: int, removed: int}
     */
    private function saveList(Employee $employee, string $section, array $rows): array
    {
        $definition = PdsSchema::section($section);
        $existing = $this->entries($employee, $section)->get()->keyBy('id');
        $keys = PdsSchema::dataKeys($section);
        $kept = [];
        $summary = ['added' => 0, 'updated' => 0, 'removed' => 0];

        foreach (array_values($rows) as $index => $row) {
            $values = PdsSchema::dropInapplicable($section, collect($row)->only($keys)->all());
            $values = $this->defaultCheckboxes($section, $values);

            /** @var PdsEntry $entry */
            $entry = isset($row['id']) ? $existing[$row['id']] : new $definition['model'];
            $entry->fill([...$values, ...($definition['scope'] ?? []), 'sort_order' => $index]);

            if (! $entry->exists) {
                $entry->employee()->associate($employee);
                $summary['added']++;
            } elseif (collect($entry->getDirty())->keys()->diff(['sort_order'])->isNotEmpty()) {
                $summary['updated']++;
            }

            $entry->save();
            $kept[] = $entry->id;
        }

        $summary['removed'] = $this->entries($employee, $section)->whereKeyNot($kept)->delete();

        return $summary;
    }

    /**
     * This employee's rows of one repeating section.
     *
     * @return Builder<PdsEntry>
     */
    private function entries(Employee $employee, string $section): Builder
    {
        $definition = PdsSchema::section($section);

        return $definition['model']::query()
            ->where('employee_id', $employee->id)
            ->where($definition['scope'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function onlyData(string $section, array $validated): array
    {
        $values = collect($validated)->only(PdsSchema::dataKeys($section))->all();

        return $this->defaultCheckboxes($section, $values);
    }

    /**
     * An unticked checkbox is `false`, never missing.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function defaultCheckboxes(string $section, array $values): array
    {
        foreach (PdsSchema::section($section)['fields'] as $field) {
            if ($field['type'] === 'checkbox') {
                $values[$field['key']] = (bool) ($values[$field['key']] ?? false);
            }
        }

        return $values;
    }
}
