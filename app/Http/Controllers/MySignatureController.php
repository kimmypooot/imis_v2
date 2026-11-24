<?php

namespace App\Http\Controllers;

use App\Support\HR\Signatures;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Your saved e-signature image: drawn on screen or uploaded, kept on the
 * private disk and served only to you. It is applied when you choose to
 * sign a leave application or approval step.
 */
class MySignatureController extends Controller
{
    public function show(Request $request): StreamedResponse
    {
        $path = $request->user()->signature_path;
        abort_unless(Signatures::exists($path), 404);

        return Storage::disk(Signatures::DISK)->response($path, null, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(Request $request, Signatures $signatures): RedirectResponse
    {
        $request->validate([
            'signature' => ['nullable', 'string', 'max:3000000', 'required_without:file'],
            'file' => ['nullable', 'required_without:signature', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ], ['signature.required_without' => 'Draw your signature or choose an image.']);

        $user = $request->user();
        $user->forceFill(['signature_path' => $signatures->store($request->file('file'), $request->input('signature'), $user->signature_path)])->save();

        return back()->with('status', 'Your signature was saved.');
    }

    public function destroy(Request $request, Signatures $signatures): RedirectResponse
    {
        $user = $request->user();

        if ($user->signature_path) {
            $signatures->delete($user->signature_path);
            $user->forceFill(['signature_path' => null])->save();
        }

        return back()->with('status', 'Your signature was removed.');
    }
}
