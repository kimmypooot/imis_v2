<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\LeaveType;
use App\Support\HR\Leave\LeaveCardSync;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-6: syncing IMIS with the leave card workbook HR keeps as its master
 * file (see LeaveCardSync). HR uploads the workbook, reviews each person's
 * card against the system, picks who to sync, and applies. The upload is
 * kept on the private disk and in the session only between checking and
 * applying; it's read again when applied, so the preview always reflects
 * the records, and it's deleted afterwards. Leave managers only.
 */
class LeaveCardSyncController extends Controller
{
    private const SESSION_KEY = 'leave_card_sync';

    public function show(Request $request): Response
    {
        $this->authorize('manage', LeaveType::class);

        $upload = $request->session()->get(self::SESSION_KEY);
        if ($upload && ! Storage::exists($upload['path'])) {
            $request->session()->forget(self::SESSION_KEY);
            $upload = null;
        }

        $rows = collect($upload['rows'] ?? []);

        return Inertia::render('Admin/HR/Leave/Sync', [
            'upload' => $upload ? ['name' => $upload['name']] : null,
            'rows' => $upload ? $rows : null,
            'summary' => $upload ? [
                'sheets' => $rows->count(),
                'in_sync' => $rows->where('sync.status', 'in_sync')->count(),
                'adjust' => $rows->whereIn('sync.status', ['adjust', 'large'])->count(),
                'check' => $rows->where('sync.status', 'check')->count(),
                'errors' => $rows->filter(fn (array $row) => $row['errors'] !== [])->count(),
            ] : null,
            'large' => LeaveCardSync::LARGE,
        ]);
    }

    public function upload(Request $request, LeaveCardSync $sync): RedirectResponse
    {
        $this->authorize('manage', LeaveType::class);

        $request->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:30720']], [], ['file' => 'leave card workbook']);

        // A large workbook: reading it takes more time and memory than a page needs.
        set_time_limit(300);
        ini_set('memory_limit', '1024M');

        $this->forget($request);
        $path = $request->file('file')->store('leave-sync');

        try {
            $plan = $sync->plan(Storage::path($path));
        } catch (\Throwable $exception) {
            Storage::delete($path);
            report($exception);

            return back()->withErrors(['file' => 'This workbook could not be read. Save it again from Excel as .xlsx and retry.']);
        }

        if ($plan->isEmpty()) {
            Storage::delete($path);

            return back()->withErrors(['file' => 'No leave card sheets were found in this workbook.']);
        }

        $request->session()->put(self::SESSION_KEY, [
            'name' => $request->file('file')->getClientOriginalName(),
            'path' => $path,
            'rows' => $plan->map(fn (array $row) => [
                'sheet' => $row['sheet'],
                'name' => $row['holder'] ?? $row['name'],
                'employee_id' => $row['employee']?->id,
                'months' => count($row['months']),
                'warnings' => $row['warnings'],
                'errors' => $row['errors'],
                'ready' => $row['ready'],
                'sync' => $row['sync'],
            ])->values()->all(),
        ]);

        return to_route('admin.hr.leave.sync');
    }

    public function apply(Request $request, LeaveCardSync $sync): RedirectResponse
    {
        $this->authorize('manage', LeaveType::class);

        $data = $request->validate(['employee_ids' => ['required', 'array', 'min:1'], 'employee_ids.*' => ['integer']], [
            'employee_ids.required' => 'Choose at least one person to sync.',
        ]);

        $upload = $request->session()->get(self::SESSION_KEY);
        if (! $upload || ! Storage::exists($upload['path'])) {
            $request->session()->forget(self::SESSION_KEY);

            return to_route('admin.hr.leave.sync')->with('error', 'Upload the workbook again; the checked copy has expired.');
        }

        set_time_limit(300);
        ini_set('memory_limit', '1024M');

        $result = $sync->apply($sync->plan(Storage::path($upload['path'])), array_map('intval', $data['employee_ids']), $request->user(), $upload['name']);
        $this->forget($request);

        $this->audit()->record(AuditEvent::LeaveCardsSynced, "Synced {$result['people']} leave cards with the leave card workbook", null, [
            'file' => $upload['name'],
            ...$result,
        ]);

        return to_route('admin.hr.leave.index')->with('status', sprintf(
            'Synced %d %s: %d card %s refreshed, %d balance %s posted.',
            $result['people'], str('person')->plural($result['people']) === 'persons' ? 'people' : 'person',
            $result['months'], str('month')->plural($result['months']),
            $result['adjustments'] + $result['openings'], str('correction')->plural($result['adjustments'] + $result['openings']),
        ));
    }

    public function discard(Request $request): RedirectResponse
    {
        $this->authorize('manage', LeaveType::class);

        $this->forget($request);

        return to_route('admin.hr.leave.sync');
    }

    private function forget(Request $request): void
    {
        $upload = $request->session()->pull(self::SESSION_KEY);
        if ($upload) {
            Storage::delete($upload['path']);
        }
    }
}
