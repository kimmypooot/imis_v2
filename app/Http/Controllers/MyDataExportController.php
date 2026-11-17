<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Support\Privacy\PersonalDataExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Download everything IMIS holds about you (RA 10173). Needs your password
 * again; the download itself is a short-lived signed link, usable only by
 * the signed-in person it was made for. Nothing is stored: the file is
 * built when it is fetched and deleted after it is sent.
 */
class MyDataExportController extends Controller
{
    public function show(Request $request): Response
    {
        return Inertia::render('Portal/MyDataExport', [
            // Set only on the redirect right after the password was confirmed.
            'downloadUrl' => $request->session()->get('data_export_url'),
        ]);
    }

    public function prepare(Request $request): RedirectResponse
    {
        $request->validate(
            ['password' => ['required', 'current_password']],
            ['password.current_password' => 'That password is incorrect.'],
        );

        return back()->with('data_export_url', URL::temporarySignedRoute('my.data-export.download', now()->addMinutes(2), ['user' => $request->user()->id]));
    }

    public function download(Request $request, PersonalDataExport $export): BinaryFileResponse
    {
        abort_unless((int) $request->query('user') === $request->user()->id, 403);

        $path = $export->makeZip($request->user());

        $this->audit()->record(AuditEvent::PersonalDataExported, 'Downloaded a copy of their own data', $request->user());

        return response()->download($path, 'my-imis-data-'.now()->format('Y-m-d').'.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }
}
