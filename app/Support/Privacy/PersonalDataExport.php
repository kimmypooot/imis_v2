<?php

namespace App\Support\Privacy;

use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\PayrollEntry;
use App\Models\User;
use App\Support\HR\Pds\PdsReader;
use RuntimeException;
use ZipArchive;

/**
 * RA 10173, the right to access and to data portability: everything IMIS
 * holds about one person's own account and HR record, as a ZIP with
 * `data.json` (machine-readable) and `data.html` (to read). Built only
 * for the signed-in person, from their own rows; other people's
 * comments, approvers' remarks and stored attachments are left out.
 */
class PersonalDataExport
{
    public function __construct(private readonly PdsReader $pds) {}

    /**
     * @return array<string, mixed>
     */
    public function build(User $user): array
    {
        $user->loadMissing('organizationalUnit', 'activeRoles', 'employee');

        $data = [
            'generated_at' => now()->toIso8601String(),
            'account' => [
                ...$user->only(['name', 'username', 'email', 'position', 'status']),
                'office' => $user->organizationalUnit?->name,
                'roles' => $user->activeRoles->pluck('name')->all(),
                'system_access' => $user->applicationAccess()->with(['application:id,name', 'role:id,name'])->get()
                    ->map(fn ($access) => ['system' => $access->application?->name, 'role' => $access->role?->name])->all(),
            ],
            'employee_record' => null,
        ];

        $employee = $user->employee;

        if ($employee !== null) {
            $data['employee_record'] = $this->employeeRecord($employee);
        }

        // Enums and dates become plain values, so JSON and HTML agree.
        return json_decode(json_encode($data, JSON_PARTIAL_OUTPUT_ON_ERROR), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function employeeRecord(Employee $employee): array
    {
        return [
            'employment' => $employee->attributesToArray(),
            'personal_data_sheet' => $this->pds->read($employee),
            'service_record' => $employee->serviceRecordEntries()->get()->map->attributesToArray()->all(),
            'learning_and_development' => $employee->trainings()->get()->map->attributesToArray()->all(),
            'movements_between_offices' => $employee->assignments()->get()->map->attributesToArray()->all(),
            'leave_credit_ledger' => $employee->leaveLedger()->get()->map->attributesToArray()->all(),
            'leave_applications' => LeaveApplication::query()->where('employee_id', $employee->id)->get()
                ->map(fn (LeaveApplication $application) => collect($application->attributesToArray())
                    ->except(['attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size', 'legacy_id'])->all())->all(),
            'pay_items' => $employee->payItems()->get()->map->attributesToArray()->all(),
            'payroll' => PayrollEntry::query()->where('employee_id', $employee->id)->whereNull('cancelled_at')->get()
                ->map(fn (PayrollEntry $entry) => collect($entry->attributesToArray())->except(['cancelled_at', 'cancelled_by', 'cancel_reason'])->all())->all(),
        ];
    }

    /**
     * Writes the ZIP to a temporary file and returns its path; the caller
     * deletes it once sent.
     */
    public function makeZip(User $user): string
    {
        $data = $this->build($user);
        $path = tempnam(sys_get_temp_dir(), 'imis-data-');
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The export file could not be created.');
        }

        $zip->addFromString('data.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
        $zip->addFromString('data.html', $this->toHtml($data));
        $zip->close();

        return $path;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function toHtml(array $data): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>My IMIS data</title>'
            .'<style>body{font:14px/1.5 system-ui,sans-serif;margin:2rem auto;max-width:60rem;padding:0 1rem;color:#1f2937}'
            .'h1,h2,h3{color:#0b3a75}table{border-collapse:collapse;margin:.5rem 0 1rem}td,th{border:1px solid #d1d5db;padding:.25rem .5rem;text-align:left;vertical-align:top}'
            .'th{background:#f3f4f6;font-weight:600}</style></head><body><h1>My IMIS data</h1>'
            .'<p>Everything IMIS holds about you, as of '.e($data['generated_at']).'. Keep this file safe: it contains personal data.</p>'
            .$this->render($data, 2).'</body></html>';
    }

    private function render(mixed $value, int $level): string
    {
        if (! is_array($value)) {
            return e($this->scalar($value));
        }

        if ($value === []) {
            return '<em>None</em>';
        }

        if (array_is_list($value)) {
            if (collect($value)->every(fn ($row) => is_array($row) && ! array_is_list($row))) {
                $columns = collect($value)->flatMap(fn ($row) => array_keys($row))->unique()->values();
                $html = '<table><tr>'.$columns->map(fn ($column) => '<th>'.e($this->label((string) $column)).'</th>')->implode('').'</tr>';

                foreach ($value as $row) {
                    $html .= '<tr>'.$columns->map(fn ($column) => '<td>'.$this->render($row[$column] ?? null, $level + 1).'</td>')->implode('').'</tr>';
                }

                return $html.'</table>';
            }

            return '<ul>'.collect($value)->map(fn ($item) => '<li>'.$this->render($item, $level + 1).'</li>')->implode('').'</ul>';
        }

        $html = '';
        $scalars = '';

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $tag = 'h'.min($level, 4);
                $html .= "<{$tag}>".e($this->label((string) $key))."</{$tag}>".$this->render($item, $level + 1);
            } else {
                $scalars .= '<tr><th>'.e($this->label((string) $key)).'</th><td>'.e($this->scalar($item)).'</td></tr>';
            }
        }

        return ($scalars !== '' ? '<table>'.$scalars.'</table>' : '').$html;
    }

    private function scalar(mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' => '—',
            is_bool($value) => $value ? 'Yes' : 'No',
            default => (string) $value,
        };
    }

    private function label(string $key): string
    {
        return ucfirst(str_replace('_', ' ', $key));
    }
}
