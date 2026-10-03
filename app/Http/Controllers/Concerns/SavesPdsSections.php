<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\AuditEvent;
use App\Models\Employee;
use App\Support\HR\Pds\PdsSchema;
use App\Support\HR\Pds\PdsWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Saving one PDS section, shared by the employee's own editor and HR's.
 * The caller has already authorized `updatePds`.
 */
trait SavesPdsSections
{
    protected function savePdsSection(Request $request, Employee $employee, string $section, PdsWriter $writer): RedirectResponse
    {
        $validated = $request->validate($writer->rules($employee, $section), [], $this->pdsAttributeNames($section));

        $summary = $writer->save($employee, $section, $validated);

        $label = PdsSchema::section($section)['label'];
        $owner = $employee->user_id === $request->user()->id ? 'their own PDS' : "the PDS of {$employee->user->name}";

        // Field names and row counts only — never values.
        if (array_filter($summary)) {
            $this->audit()->record(AuditEvent::PdsUpdated, "Updated {$label} in {$owner}", $employee, [
                'section' => $section,
                ...$summary,
            ]);
        }

        return back()->with('status', "{$label} was saved.");
    }

    /**
     * Readable names in validation messages ("The From field…" rather
     * than "The rows.0.date_from field…").
     *
     * @return array<string, string>
     */
    private function pdsAttributeNames(string $section): array
    {
        $definition = PdsSchema::section($section);
        $prefix = $definition['kind'] === 'list' ? 'rows.*.' : '';

        return collect($definition['fields'])
            ->mapWithKeys(fn (array $field) => [$prefix.$field['key'] => strtolower(preg_replace('/^[\d.]+\s*|\s*\(.*\)$/', '', $field['label']))])
            ->all();
    }
}
