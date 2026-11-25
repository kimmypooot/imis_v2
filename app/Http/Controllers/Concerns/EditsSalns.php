<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\SalnOathMode;
use App\Enums\SalnType;
use App\Models\Employee;
use App\Models\Saln;
use App\Support\HR\EmployeeDocuments;
use App\Support\HR\Saln\SalnFilings;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Opening, saving, printing and filing a SALN — shared by My SALN and HRD
 * working on someone's behalf. The rules live in SalnFilings; this only
 * reads the request and turns its refusals into form errors.
 */
trait EditsSalns
{
    protected function openFiling(Request $request, Employee $employee, SalnFilings $filings, callable $redirect): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::enum(SalnType::class)],
            'year' => ['required_if:type,annual', 'nullable', 'integer', 'min:2000', 'max:2100'],
            'as_of' => ['required_unless:type,annual', 'nullable', 'date_format:Y-m-d'],
            'from_previous' => ['boolean'],
        ]);

        $type = SalnType::from($data['type']);
        $asOf = $type === SalnType::Annual ? CarbonImmutable::create((int) $data['year'], 12, 31) : CarbonImmutable::parse($data['as_of']);

        $existing = Saln::query()->where('employee_id', $employee->id)->where('type', $type)->whereDate('reckoning_date', $asOf->toDateString())->first();
        if ($existing) {
            return $redirect($existing);
        }

        try {
            $saln = $filings->open($employee, $type, $asOf, $request->user(), (bool) ($data['from_previous'] ?? false));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['type' => $e->getMessage()]);
        }

        return $redirect($saln);
    }

    protected function saveStatement(Request $request, Saln $saln, SalnFilings $filings): RedirectResponse
    {
        $data = $request->validate([
            'statement' => ['required', 'array'],
            'oath_mode' => ['required', Rule::enum(SalnOathMode::class)],
            'officer_name' => ['nullable', 'string', 'max:150'],
            'officer_title' => ['nullable', 'string', 'max:150'],
        ]);

        try {
            $filings->save($saln, $data['statement'], SalnOathMode::from($data['oath_mode']), $data['officer_name'] ?? null, $data['officer_title'] ?? null, $request->user());
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $key) => ["statement.{$key}" => $messages])->all());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['saln' => $e->getMessage()]);
        }

        return back()->with('status', 'Your SALN is saved.');
    }

    protected function generateVersion(Request $request, Saln $saln, SalnFilings $filings, string $redirectTo): RedirectResponse
    {
        try {
            $version = $filings->generate($saln, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['saln' => $e->getMessage()]);
        }

        return redirect($redirectTo)->with('status', "Printout {$version->printedReference()} is ready. Print it, sign it, and hand the hard copy to HRD.");
    }

    protected function fileScan(Request $request, Saln $saln, SalnFilings $filings): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:'.implode(',', EmployeeDocuments::EXTENSIONS), 'max:'.EmployeeDocuments::MAX_KILOBYTES],
        ]);

        try {
            $filings->file($saln, $request->file('file'), $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return back()->with('status', "The signed SALN {$saln->reference()} is filed in the 201 File.");
    }
}
