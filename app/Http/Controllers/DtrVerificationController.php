<?php

namespace App\Http\Controllers;

use App\Support\HR\Dtr\DtrSheets;
use App\Support\HR\Dtr\DtrSignatures;
use Inertia\Inertia;
use Inertia\Response;

/**
 * DTR: the page a printed DTR's QR code opens, without signing in. It says
 * whether the sheet is still exactly what the employee signed, and shows
 * only the name, the period and when it was signed: never the hours or
 * punches (data minimization, RA 10173). Any code that doesn't resolve
 * looks the same.
 */
class DtrVerificationController extends Controller
{
    public function __invoke(string $code, DtrSignatures $signatures): Response
    {
        $signature = $signatures->find($code);

        if (! $signature) {
            return Inertia::render('DtrVerify', ['state' => 'not_found']);
        }

        return Inertia::render('DtrVerify', [
            'state' => $signatures->status($signature),
            'employee' => $signature->employee->user->name,
            'period' => DtrSheets::periodLabel($signature->period_from, $signature->period_to).', '.$signature->period_to->format('Y'),
            'signed_at' => $signature->signed_at->toIso8601String(),
        ]);
    }
}
