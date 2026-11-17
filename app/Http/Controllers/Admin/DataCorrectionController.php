<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\DataCorrectionRequest;
use App\Support\HR\DataCorrections;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR's queue of staff requests to correct their employee record. Approving
 * applies the change; rejecting needs a reason. Never one's own request.
 */
class DataCorrectionController extends Controller
{
    public function index(Request $request, DataCorrections $corrections): Response
    {
        return Inertia::render('Admin/HR/DataCorrections/Index', [
            'requests' => $corrections->all()->map(fn (DataCorrectionRequest $item) => $corrections->present($item, $request->user())),
        ]);
    }

    public function decide(Request $request, DataCorrectionRequest $correction, DataCorrections $corrections): RedirectResponse
    {
        abort_unless($corrections->canDecide($request->user(), $correction), 403);
        $data = $request->validate([
            'decision' => ['required', 'in:approved,rejected'],
            'remarks' => ['nullable', 'required_if:decision,rejected', 'string', 'max:1000'],
        ]);

        $corrections->decide($correction, $request->user(), $data['decision'] === 'approved', $data['remarks'] ?? null);
        $this->audit()->record(AuditEvent::DataCorrectionDecided, ucfirst($data['decision'])." {$correction->label}", $correction, ['decision' => $data['decision'], 'field' => $correction->field]);

        return back()->with('status', ucfirst($data['decision'])." {$correction->reference_number}.");
    }
}
