<?php

namespace App\Http\Controllers;

use App\Enums\SalnStatus;
use App\Http\Controllers\Concerns\EditsSalns;
use App\Models\Saln;
use App\Support\HR\Saln\SalnFilings;
use App\Support\HR\Saln\SalnObligations;
use App\Support\HR\Saln\SalnPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * My SALN: encode your Statement of Assets, Liabilities and Net Worth,
 * print it on the 2025 form, hand the signed copy to HRD, and once the
 * SALN Committee clears it and you've sworn to it, upload the signed copy.
 */
class MySalnController extends Controller
{
    use EditsSalns;

    public function index(Request $request, SalnObligations $obligations, SalnPresenter $presenter): Response
    {
        $employee = $request->user()->employee;

        return Inertia::render('Portal/MySaln', [
            'hasRecord' => $employee !== null,
            'salns' => $employee
                ? $employee->salns()->latest('reckoning_date')->latest('id')->get()->map(fn (Saln $saln) => $presenter->summary($saln))->all()
                : [],
            'due' => $employee
                ? $obligations->dueFor($employee)->map(fn (array $row) => [
                    'type' => $row['type']->value,
                    'type_label' => $row['type']->label(),
                    'as_of' => $row['as_of']->toDateString(),
                    'year' => $row['as_of']->year,
                    'due' => $row['due']->toDateString(),
                    'state' => $row['state'],
                    'saln_id' => $row['saln']?->id,
                ])->all()
                : [],
            'hasFiled' => $employee?->salns()->where('status', SalnStatus::Filed)->exists() ?? false,
            'options' => SalnPresenter::options(),
        ]);
    }

    public function open(Request $request, SalnFilings $filings): RedirectResponse
    {
        $employee = $request->user()->employee;
        abort_if($employee === null, 403);

        return $this->openFiling($request, $employee, $filings, fn (Saln $saln) => to_route('my.saln.edit', $saln));
    }

    public function edit(Request $request, Saln $saln, SalnPresenter $presenter): Response
    {
        $this->authorizeOwn($request, $saln);

        return Inertia::render('Portal/MySalnEdit', [
            'saln' => $presenter->detail($saln),
            'options' => SalnPresenter::options(),
            'canFile' => $request->user()->can('file', $saln) && $saln->status === SalnStatus::Cleared,
        ]);
    }

    public function update(Request $request, Saln $saln, SalnFilings $filings): RedirectResponse
    {
        $this->authorizeOwn($request, $saln);
        $this->authorize('update', $saln);

        return $this->saveStatement($request, $saln, $filings);
    }

    public function generate(Request $request, Saln $saln, SalnFilings $filings): RedirectResponse
    {
        $this->authorizeOwn($request, $saln);
        $this->authorize('update', $saln);

        return $this->generateVersion($request, $saln, $filings, route('my.saln.edit', $saln, false));
    }

    public function print(Request $request, Saln $saln, int $version, SalnPresenter $presenter): Response
    {
        $this->authorizeOwn($request, $saln);
        $snapshot = $saln->versions()->where('version', $version)->firstOrFail();

        return Inertia::render('HR/SalnPrint', $presenter->print($saln, $snapshot, route('my.saln.edit', $saln, false)));
    }

    public function file(Request $request, Saln $saln, SalnFilings $filings): RedirectResponse
    {
        $this->authorizeOwn($request, $saln);
        $this->authorize('file', $saln);

        return $this->fileScan($request, $saln, $filings);
    }

    /**
     * My SALN is only ever your own; HRD works on others' from Admin.
     */
    private function authorizeOwn(Request $request, Saln $saln): void
    {
        abort_unless($saln->employee()->value('user_id') === $request->user()->id, 403);
    }
}
