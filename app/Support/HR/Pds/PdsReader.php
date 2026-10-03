<?php

namespace App\Support\HR\Pds;

use App\Models\Employee;
use App\Models\Pds\PdsLearningDevelopment;
use Illuminate\Database\Eloquent\Model;

/**
 * An employee's whole PDS as plain values, keyed by section — what the
 * editor and HR's view render. Decrypts the sensitive fields, so it is
 * only ever built for someone the policy allows to see them.
 */
class PdsReader
{
    /**
     * @return array<string, mixed>
     */
    public function read(Employee $employee): array
    {
        $employee->loadMissing('user', 'pdsProfile');
        $profile = $employee->pdsProfile;
        $user = $employee->user;

        $values = [];

        foreach (PdsSchema::sections() as $key => $section) {
            $values[$key] = match ($section['kind']) {
                'profile' => $this->profileValues($key, $profile, $user),
                'questions' => $this->questionValues($profile?->questions ?? []),
                'list' => $this->rows($employee, $key),
            };
        }

        return [
            // Items 1–3 and 5, read from the account (not editable here).
            'identity' => [
                'surname' => $user->last_name,
                'first_name' => $user->first_name,
                'middle_name' => $user->middle_name,
                'name_extension' => $user->name_extension,
                'date_of_birth' => $user->birthday?->toDateString(),
                'sex_at_birth' => $user->sex,
                'agency_employee_no' => $employee->employee_number,
            ],
            'sections' => $values,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function profileValues(string $section, ?Model $profile, Model $user): array
    {
        return collect(PdsSchema::section($section)['fields'])
            ->reject(fn ($field) => $field['type'] === 'heading')
            ->mapWithKeys(function ($field) use ($profile, $user) {
                $source = ($field['target'] ?? 'pds') === 'user' ? $user : $profile;
                $value = $source?->getAttribute($field['key']);

                return [$field['key'] => $value ?? ($field['type'] === 'checkbox' ? false : null)];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    private function questionValues(array $answers): array
    {
        return collect(PdsSchema::dataKeys('questions'))
            ->mapWithKeys(fn ($key) => [$key => $answers[$key] ?? null])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(Employee $employee, string $section): array
    {
        $definition = PdsSchema::section($section);
        $keys = ['id', ...PdsSchema::dataKeys($section)];

        // Section VI comes from Learning & Development: the employee's
        // approved and pending trainings, most recent first.
        if ($section === 'learning_development') {
            return PdsLearningDevelopment::query()
                ->where('employee_id', $employee->id)
                ->onPds()
                ->orderByDesc('date_from')
                ->orderByDesc('id')
                ->get()
                ->map(fn (PdsLearningDevelopment $row) => $row->toPdsRow())
                ->all();
        }

        return $definition['model']::query()
            ->where('employee_id', $employee->id)
            ->where($definition['scope'] ?? [])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Model $row) => collect($row->toArray())->only($keys)->all())
            ->all();
    }
}
