<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\PlantillaItem;
use App\Support\HR\Plantilla\PlantillaSheet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HR-4: completing the plantilla from a spreadsheet — download the
 * template, upload it filled in, check every row, then apply. The upload
 * is kept in the session between checking and applying, and checked again
 * when applied, so the preview always reflects the record.
 */
class PlantillaSheetController extends Controller
{
    private const SESSION_KEY = 'plantilla_sheet';

    public function show(Request $request, PlantillaSheet $sheet): Response
    {
        $this->authorize('manage', PlantillaItem::class);

        $upload = $request->session()->get(self::SESSION_KEY);
        $checked = $upload ? $sheet->check($upload['rows'], $request->user()) : null;

        return Inertia::render('Admin/HR/Plantilla/Sheet', [
            'upload' => $upload ? ['name' => $upload['name']] : null,
            'rows' => $checked?->map(fn (array $row) => collect($row)->except('apply')->all())->values(),
            'summary' => $checked ? [
                'rows' => $checked->count(),
                'ready' => $checked->where('ready', true)->count(),
                'errors' => $checked->filter(fn (array $row) => $row['errors'] !== [])->count(),
                'unchanged' => $checked->filter(fn (array $row) => $row['errors'] === [] && $row['changes'] === [])->count(),
            ] : null,
        ]);
    }

    public function template(PlantillaSheet $sheet): StreamedResponse
    {
        $this->authorize('manage', PlantillaItem::class);

        return $sheet->template();
    }

    public function upload(Request $request, PlantillaSheet $sheet): RedirectResponse
    {
        $this->authorize('manage', PlantillaItem::class);

        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']], [], ['file' => 'spreadsheet']);

        $rows = $sheet->read($request->file('file')->getRealPath());
        if ($rows === null) {
            return back()->withErrors(['file' => 'This isn’t the plantilla template. Download the template, fill it in, and save it as CSV.']);
        }
        if ($rows === []) {
            return back()->withErrors(['file' => 'The spreadsheet has no rows.']);
        }

        $request->session()->put(self::SESSION_KEY, ['name' => $request->file('file')->getClientOriginalName(), 'rows' => $rows]);

        return to_route('admin.hr.plantilla.sheet');
    }

    public function apply(Request $request, PlantillaSheet $sheet): RedirectResponse
    {
        $this->authorize('manage', PlantillaItem::class);

        $upload = $request->session()->pull(self::SESSION_KEY);
        if (! $upload) {
            return to_route('admin.hr.plantilla.sheet')->with('error', 'Upload the spreadsheet again; the checked copy has expired.');
        }

        $checked = $sheet->check($upload['rows'], $request->user());
        $changed = $sheet->apply($checked);
        $skipped = $checked->filter(fn (array $row) => $row['errors'] !== [])->count();

        if ($changed !== []) {
            $this->audit()->record(AuditEvent::PlantillaSheetApplied, 'Completed '.count($changed).' plantilla items from a spreadsheet', null, [
                'file' => $upload['name'],
                'items' => $changed,
                'rows_with_errors' => $skipped,
            ]);
        }

        return to_route('admin.hr.plantilla.index')->with('status', 'Updated '.count($changed).' '.str('item')->plural(count($changed)).' from the spreadsheet.'
            .($skipped ? " {$skipped} ".str('row')->plural($skipped).' had errors and '.($skipped === 1 ? 'was' : 'were').' skipped.' : ''));
    }

    public function discard(Request $request): RedirectResponse
    {
        $this->authorize('manage', PlantillaItem::class);

        $request->session()->forget(self::SESSION_KEY);

        return to_route('admin.hr.plantilla.sheet');
    }
}
