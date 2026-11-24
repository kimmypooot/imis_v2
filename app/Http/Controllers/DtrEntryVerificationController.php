<?php

namespace App\Http\Controllers;

use App\Support\HR\Dtr\DtrEntryVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * DTR: a supervisor or office head verifies the days their people entered
 * themselves (CS Form 48, "Verified as to the prescribed office hours").
 * It lists only the people the signed-in person supervises or heads.
 */
class DtrEntryVerificationController extends Controller
{
    public function index(Request $request, DtrEntryVerification $verification): Response
    {
        return Inertia::render('Portal/DtrVerify', [
            'entries' => $verification->pending($request->user())->map(fn ($row) => [
                'id' => $row->id,
                'employee' => $row->employee->user->name,
                'date' => $row->date->toDateString(),
                'time_in' => $row->time_in ? substr($row->time_in, 0, 5) : null,
                'time_out' => $row->time_out ? substr($row->time_out, 0, 5) : null,
                'remark' => $row->remark,
                'entered_at' => $row->updated_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    public function store(Request $request, DtrEntryVerification $verification): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
        ]);

        $count = $verification->verify($data['ids'], $request->user());

        return back()->with('status', $count ? "Verified {$count} ".($count === 1 ? 'day' : 'days').'.' : 'Nothing to verify: those days are not yours to verify, or already verified.');
    }
}
