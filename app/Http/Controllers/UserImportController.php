<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Models\User;
use App\Support\Users\UserImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin-3, Manage Users → Import (`/users/import`, `users.create`): upload
 * a CSV, check the preview, then create the valid rows (UserImport). Each
 * account is audited like one made with Add User, plus one entry for the
 * import as a whole.
 */
class UserImportController extends Controller
{
    public function create(Request $request): Response
    {
        $this->authorize('create', User::class);

        return Inertia::render('Admin/Users/Import', [
            'columns' => UserImport::COLUMNS,
            'maxRows' => UserImport::MAX_ROWS,
            'preview' => $request->session()->get('import_preview'),
            'result' => $request->session()->get('import_result'),
        ]);
    }

    public function template(): StreamedResponse
    {
        $this->authorize('create', User::class);

        return response()->streamDownload(fn () => print (UserImport::template()), 'imis-user-import-template.csv', ['Content-Type' => 'text/csv']);
    }

    public function preview(Request $request, UserImport $import): RedirectResponse
    {
        $this->authorize('create', User::class);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:1024']]);

        try {
            $preview = $import->preview($request->file('file'), $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        // Only what the page shows; the full rows stay in the cache for the import.
        $preview['rows'] = array_map(fn (array $row) => array_intersect_key($row, array_flip(['line', 'name', 'email', 'office', 'roles', 'username', 'username_generated', 'errors'])), $preview['rows']);

        return back()->with('import_preview', $preview);
    }

    public function store(Request $request, UserImport $import): RedirectResponse
    {
        $this->authorize('create', User::class);
        $data = $request->validate(['token' => ['required', 'string', 'size:32'], 'send_links' => ['boolean']]);

        $result = $import->import($data['token'], $request->user(), (bool) ($data['send_links'] ?? true));

        if ($result === null) {
            return back()->with('error', 'That preview has expired. Upload the file again.');
        }

        $created = count($result['created']);
        $this->audit()->record(AuditEvent::UsersImported, "Imported {$created} ".str('user')->plural($created).' from a file', null, [
            'created' => array_column($result['created'], 'username'),
            'skipped_lines' => array_column($result['skipped'], 'line'),
        ]);

        return back()->with('import_result', $result)->with('status', "{$created} ".str('account')->plural($created).' created.');
    }
}
