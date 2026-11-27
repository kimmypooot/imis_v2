<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Support\Privacy\PersonalDataExport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Download what IMIS holds about you (RA 10173). You tick the sections you
 * want and confirm your password; the download itself is a short-lived
 * signed link, usable only by the signed-in person it was made for, and
 * carries the chosen sections. Nothing is stored: the file is built when
 * it is fetched and deleted after it is sent.
 */
class MyDataExportController extends Controller
{
    public function show(Request $request, PersonalDataExport $export): Response
    {
        return Inertia::render('Portal/MyDataExport', [
            'sections' => $export->sections($request->user()),
            // Set only on the redirect right after the password was confirmed.
            'downloadUrl' => $request->session()->get('data_export_url'),
        ]);
    }

    public function prepare(Request $request): RedirectResponse
    {
        $validated = $request->validate(
            [
                'sections' => ['required', 'array', 'min:1'],
                'sections.*' => ['string', Rule::in(PersonalDataExport::sectionKeys())],
                'password' => ['required', 'current_password'],
            ],
            [
                'sections.required' => 'Tick at least one part of your data to include.',
                'sections.min' => 'Tick at least one part of your data to include.',
                'password.current_password' => 'That password is incorrect.',
            ],
        );

        return back()->with('data_export_url', URL::temporarySignedRoute('my.data-export.download', now()->addMinutes(2), [
            'user' => $request->user()->id,
            'sections' => implode(',', array_unique($validated['sections'])),
        ]));
    }

    public function download(Request $request, PersonalDataExport $export): BinaryFileResponse
    {
        abort_unless((int) $request->query('user') === $request->user()->id, 403);

        $sections = $request->filled('sections') ? explode(',', (string) $request->query('sections')) : null;
        $path = $export->makeZip($request->user(), $sections);

        $this->audit()->record(
            AuditEvent::PersonalDataExported,
            'Downloaded a copy of their own data'.($sections !== null ? ' ('.implode(', ', $sections).')' : ''),
            $request->user(),
        );

        return response()->download($path, 'my-imis-data-'.now()->format('Y-m-d').'.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }
}
