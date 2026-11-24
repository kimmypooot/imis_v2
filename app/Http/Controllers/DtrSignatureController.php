<?php

namespace App\Http\Controllers;

use App\Models\DtrSignature;
use App\Support\HR\Signatures;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * DTR: the signature image kept with a signed sheet, shown on the printed
 * form. Served only to the employee who signed and to HR (`viewDtr`).
 */
class DtrSignatureController extends Controller
{
    public function image(DtrSignature $signature): StreamedResponse
    {
        $this->authorize('viewDtr', $signature->employee);
        abort_unless(Signatures::exists($signature->signature_path), 404);

        return Storage::disk(Signatures::DISK)->response($signature->signature_path, null, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
