<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Support\HR\Pds\PdsImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * HR-2: filling an employee's PDS from the CSC's Excel form (CS Form 212
 * Revised 2026). Two steps — upload for a preview, then confirm which
 * sections to save — so nothing is written without HR seeing it first.
 * Needs `pds.update`, like editing the PDS by hand.
 */
class EmployeePdsImportController extends Controller
{
    public function preview(Request $request, Employee $employee, PdsImport $import): RedirectResponse
    {
        $this->authorize('updatePds', $employee);

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx', 'max:5120'],
        ], ['file.mimes' => 'Upload the Excel file (.xlsx) of the CS Form No. 212.']);

        $employee->load('user');

        try {
            $preview = $import->preview($employee, $request->file('file')->getRealPath(), $request->file('file')->getClientOriginalName(), $request->user()->id);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['file' => $exception->getMessage()]);
        }

        return back()->with('pds_import', $preview);
    }

    public function apply(Request $request, Employee $employee, PdsImport $import): RedirectResponse
    {
        $this->authorize('updatePds', $employee);

        $data = $request->validate([
            'token' => ['required', 'string', 'size:40'],
            'sections' => ['required', 'array', 'min:1'],
            'sections.*' => ['string'],
        ]);

        $employee->load('user');
        $saved = $import->apply($employee, $data['token'], $data['sections'], $request->user());

        if ($saved === null) {
            return back()->with('error', 'The import expired. Upload the file again.');
        }

        $owner = $employee->user_id === $request->user()->id ? 'their own PDS' : "the PDS of {$employee->user->name}";

        // Section names and row counts only — never values.
        $this->audit()->record(AuditEvent::PdsUpdated, "Imported sections of {$owner} from the Excel form", $employee, [
            'source' => 'excel',
            'sections' => array_keys($saved),
        ]);

        return back()->with('status', $saved === []
            ? 'Nothing was imported: the chosen sections had errors.'
            : 'Imported '.count($saved).' '.(count($saved) === 1 ? 'section' : 'sections').' from the Excel form.');
    }
}
