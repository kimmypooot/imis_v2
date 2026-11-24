<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Enums\EmploymentStatus;
use App\Http\Controllers\Controller;
use App\Models\BiometricImport;
use App\Models\BiometricPunch;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\HrUpdate;
use App\Support\HR\Dtr\BiometricImporter;
use App\Support\HR\Dtr\DtrSheets;
use App\Support\HR\Dtr\UploadMonitor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * DTR: HRD uploads the biometric devices' .dat logs. Each punch is kept
 * once, so uploading a device's full history again only adds what is new.
 * A punch belongs to the employee whose record carries its biometric ID;
 * IDs no one has yet are listed so HRD can fill them in on the profiles.
 */
class BiometricImportController extends Controller
{
    public function show(Request $request, BiometricImporter $importer, UploadMonitor $monitor): Response
    {
        $this->authorize('viewAny', BiometricPunch::class);

        // HRD uploads on the first working day, for the month that just ended.
        $month = $request->filled('month') ? DtrSheets::parseMonth($request->query('month')) : now()->subMonthNoOverflow()->startOfMonth();

        return Inertia::render('Admin/HR/Dtr/Import', [
            'imports' => BiometricImport::query()
                ->with('user:id,name')
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (BiometricImport $import) => [
                    'id' => $import->id,
                    'file_name' => $import->file_name,
                    'uploader' => $import->user->name,
                    'rows_read' => $import->rows_read,
                    'rows_new' => $import->rows_new,
                    'rows_duplicate' => $import->rows_duplicate,
                    'rows_skipped' => $import->rows_skipped,
                    'rows_ignored' => $import->rows_ignored,
                    'first_punch_at' => $import->first_punch_at?->toDateTimeString(),
                    'last_punch_at' => $import->last_punch_at?->toDateTimeString(),
                    'created_at' => $import->created_at->toDateTimeString(),
                ])->values(),
            'staffCount' => Employee::query()->whereNull('separation_date')->where('employment_status', EmploymentStatus::Permanent)->count(),
            'result' => session('import_result'),
            'month' => $month->format('Y-m'),
            'monthLabel' => $month->format('F Y'),
            'monitor' => $monitor->forMonth($month),
            'unmatched' => $importer->unmatchedIds(),
            'punches' => BiometricPunch::query()->count(),
            'canManage' => $request->user()->can('manage', BiometricPunch::class),
        ]);
    }

    public function store(Request $request, BiometricImporter $importer): RedirectResponse
    {
        $this->authorize('manage', BiometricPunch::class);

        $request->validate(['file' => ['required', 'file', 'extensions:dat,txt', 'max:5120'], 'known_only' => ['sometimes', 'boolean']], [], ['file' => 'attendance log']);

        $file = $request->file('file');
        $import = $importer->import($file->getRealPath(), $file->getClientOriginalName(), $request->user(), $request->boolean('known_only'));
        $ignored = $importer->ignored;
        $unmatched = $importer->unmatchedIds()->count();

        $this->audit()->record(AuditEvent::BiometricLogImported, "Uploaded the attendance log {$import->file_name}", $import, [
            'file' => $import->file_name,
            'hash' => $import->file_hash,
            'rows_read' => $import->rows_read,
            'rows_new' => $import->rows_new,
            'rows_duplicate' => $import->rows_duplicate,
            'rows_skipped' => $import->rows_skipped,
            'unmatched_ids' => $unmatched,
        ]);

        $notified = $this->tellEmployees($import, $request->user());

        return to_route('admin.hr.dtr.import')->with('import_result', [
            'file_name' => $import->file_name,
            'rows_read' => $import->rows_read,
            'rows_new' => $import->rows_new,
            'rows_duplicate' => $import->rows_duplicate,
            'rows_skipped' => $import->rows_skipped,
            'unmatched' => $unmatched,
            'ignored' => $ignored,
            'notified' => $notified,
            'first_punch_at' => $import->first_punch_at?->toDateString(),
            'last_punch_at' => $import->last_punch_at?->toDateString(),
        ])->with('status', sprintf(
            '%d punches read, %d new, %d already on file%s.%s%s',
            $import->rows_read, $import->rows_new, $import->rows_duplicate,
            ($import->rows_skipped ? ", {$import->rows_skipped} rows skipped as unreadable" : '').($ignored ? ", {$ignored} left out (ID not on any profile)" : ''),
            $unmatched ? " {$unmatched} ".str('ID')->plural($unmatched).' in the logs '.($unmatched === 1 ? 'is' : 'are').' not on any employee\'s profile yet (see below).' : '',
            $notified ? " {$notified} ".str('employee')->plural($notified).' notified.' : '',
        ));
    }

    /**
     * Removes the stored punches of IDs that no employee's profile carries.
     * IDs that have since been given to an employee are skipped.
     */
    public function clearIds(Request $request, BiometricImporter $importer): RedirectResponse
    {
        $this->authorize('manage', BiometricPunch::class);

        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:1000'], 'ids.*' => ['string', 'max:32']]);

        $cleared = $importer->purgeUnmatched($data['ids']);

        $this->audit()->record(AuditEvent::BiometricPunchesCleared, 'Cleared punches of biometric IDs that belong to no employee', null, [
            'ids' => $cleared['ids'],
            'punches' => $cleared['punches'],
        ]);

        $count = count($cleared['ids']);

        return to_route('admin.hr.dtr.import')->with('status', $count
            ? sprintf('Cleared %s %s from %d %s.', number_format($cleared['punches']), str('punch')->plural($cleared['punches']), $count, str('ID')->plural($count))
            : 'Nothing cleared: those IDs now belong to an employee.');
    }

    /**
     * Tells every employee in service, under the bell, that the logs are in
     * and who uploaded them. An upload that added nothing new stays quiet.
     */
    private function tellEmployees(BiometricImport $import, User $uploader): int
    {
        if ($import->rows_new === 0 || $import->first_punch_at === null) {
            return 0;
        }

        $from = $import->first_punch_at->format('M j, Y');
        $to = $import->last_punch_at->format('M j, Y');
        $period = $from === $to ? $from : "{$from} to {$to}";

        $recipients = User::query()->whereIn('id', Employee::query()->whereNull('separation_date')->where('employment_status', EmploymentStatus::Permanent)->select('user_id'))->get();
        foreach ($recipients as $user) {
            $user->notify(new HrUpdate(
                'Biometric logs uploaded',
                "The Human Resource Division ({$uploader->name}) uploaded the biometric logs covering {$period}. You can now check your DTR.",
                '/my/dtr',
            ));
        }

        return $recipients->count();
    }
}
