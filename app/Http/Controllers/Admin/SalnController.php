<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Enums\SalnStatus;
use App\Http\Controllers\Concerns\EditsSalns;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Saln;
use App\Models\Setting;
use App\Support\HR\Saln\SalnFilings;
use App\Support\HR\Saln\SalnObligations;
use App\Support\HR\Saln\SalnPresenter;
use App\Support\Settings\SettingRegistry;
use App\Support\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * SALN for HRD (`saln.manage`) and the SALN Committee (`saln.review`):
 * who has filed (compliance and exemptions), receiving hard copies,
 * clearing or returning them, filing the signed copies, and encoding for
 * someone who can't sign in (separated staff, staff without an account).
 * Nobody decides their own SALN.
 */
class SalnController extends Controller
{
    use EditsSalns;

    public function index(Request $request, SalnObligations $obligations): Response
    {
        $this->authorize('manage', Saln::class);
        $year = (int) $request->integer('year', CarbonImmutable::today()->year - 1);
        $rows = $obligations->annual($year)
            ->merge($obligations->entryAndExit(CarbonImmutable::create($year, 1, 1), CarbonImmutable::create($year, 12, 31)))
            ->map(fn (array $row) => [
                'employee_id' => $row['employee']->id,
                'name' => $row['employee']->user?->name ?? 'Employee #'.$row['employee']->id,
                'photo_url' => $row['employee']->user?->profile_photo_url,
                'position' => $row['employee']->user?->position,
                'unit' => $row['employee']->user?->organizationalUnit?->short_name ?: $row['employee']->user?->organizationalUnit?->name,
                'type' => $row['type']->value,
                'type_label' => $row['type']->label(),
                'as_of' => $row['as_of']->toDateString(),
                'due' => $row['due']->toDateString(),
                'state' => $row['state'],
                'status_label' => $row['saln']?->status->label(),
                'saln_id' => $row['saln']?->id,
                'exempt_reason' => $row['exempt']?->reason,
            ])
            ->sortBy('name')->values();

        $annual = $rows->where('type', 'annual');

        return Inertia::render('Admin/HR/Saln/Index', [
            'year' => $year,
            'rows' => $rows,
            'counts' => [
                'required' => $annual->count(),
                'filed' => $annual->where('state', 'filed')->count(),
                'in_progress' => $annual->where('state', 'in_progress')->count(),
                'missing' => $annual->where('state', 'missing')->count(),
                'late' => $annual->where('state', 'late')->count(),
                'exempt' => $annual->where('state', 'exempt')->count(),
            ],
            'canManage' => $request->user()->hasPermission('saln.manage'),
            'multipleMarriages' => (bool) Settings::get('saln_multiple_marriages'),
        ]);
    }

    public function queue(Request $request, SalnPresenter $presenter): Response
    {
        $this->authorize('viewAny', Saln::class);
        $own = $request->user()->employee?->id;
        $list = fn (SalnStatus $status, string $order = 'updated_at') => Saln::query()
            ->where('status', $status)
            ->when($own, fn ($query) => $query->where('employee_id', '!=', $own))
            ->with('employee.user.organizationalUnit')
            ->oldest($order)->oldest('id')->get()
            ->map(fn (Saln $saln) => $presenter->summary($saln))->all();

        $manages = $request->user()->hasPermission('saln.manage');

        return Inertia::render('Admin/HR/Saln/Queue', [
            'received' => $list(SalnStatus::Received),
            'awaitingHardCopy' => $manages ? $list(SalnStatus::Generated) : [],
            'cleared' => $manages ? $list(SalnStatus::Cleared) : [],
            'canManage' => $manages,
        ]);
    }

    public function show(Request $request, Saln $saln, SalnPresenter $presenter): Response
    {
        $this->authorize('view', $saln);
        $this->auditAccess($request, $saln, AuditEvent::SalnViewed, 'Viewed');

        return Inertia::render('Admin/HR/Saln/Show', [
            'saln' => $presenter->detail($saln),
            'options' => SalnPresenter::options(),
            'can' => [
                'receive' => $request->user()->can('receive', $saln) && $saln->status === SalnStatus::Generated,
                'decide' => $request->user()->can('decide', $saln) && $saln->status === SalnStatus::Received,
                'file' => $request->user()->can('file', $saln) && $saln->status === SalnStatus::Cleared,
                'edit' => $request->user()->can('update', $saln) && $saln->status->isEditable(),
            ],
        ]);
    }

    public function print(Request $request, Saln $saln, int $version, SalnPresenter $presenter): Response
    {
        $this->authorize('view', $saln);
        $snapshot = $saln->versions()->where('version', $version)->firstOrFail();
        $this->auditAccess($request, $saln, AuditEvent::SalnPrinted, "Printed v{$version} of");

        return Inertia::render('HR/SalnPrint', $presenter->print($saln, $snapshot, route('admin.hr.saln.show', $saln, false)));
    }

    public function receive(Request $request, SalnFilings $filings): RedirectResponse
    {
        $data = $request->validate([
            'reference' => ['required_without:saln_id', 'nullable', 'string', 'max:40'],
            'saln_id' => ['nullable', 'integer'],
            'version' => ['nullable', 'integer'],
        ]);

        if (filled($data['reference'] ?? null)) {
            // As printed at the foot of each page ("IMIS SALN-2025-00042"); a
            // version, if typed, must be the latest printout's.
            if (! preg_match('/^\s*(?:IMIS\s+)?SALN-(\d{4})-(\d{1,7})(?:\s+v?(\d+)|v(\d+))?\s*$/i', $data['reference'], $parts)) {
                return back()->withErrors(['reference' => 'Type the reference printed at the foot of each page, like SALN-2025-00042.']);
            }
            $saln = Saln::query()->find((int) $parts[2]);
            if (! $saln || $saln->reckoning_date->year !== (int) $parts[1]) {
                return back()->withErrors(['reference' => 'No SALN has that reference.']);
            }
            $typed = ($parts[3] ?? '') !== '' ? $parts[3] : ($parts[4] ?? '');
            $version = $typed !== '' ? (int) $typed : $saln->current_version;
        } else {
            $saln = Saln::query()->findOrFail($data['saln_id']);
            $version = (int) ($data['version'] ?? $saln->current_version);
        }

        $this->authorize('receive', $saln);

        try {
            $filings->receive($saln, $version, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['reference' => $e->getMessage()]);
        }

        return back()->with('status', "Received the hard copy of {$saln->reference()} v{$version}.");
    }

    public function decide(Request $request, Saln $saln, SalnFilings $filings): RedirectResponse
    {
        $this->authorize('decide', $saln);
        $data = $request->validate([
            'decision' => ['required', 'in:clear,return'],
            'remarks' => ['required_if:decision,return', 'nullable', 'string', 'max:2000'],
        ]);

        try {
            $filings->decide($saln, $data['decision'] === 'clear', $data['remarks'] ?? null, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['remarks' => $e->getMessage()]);
        }

        return to_route('admin.hr.saln.queue')->with('status', $data['decision'] === 'clear'
            ? "{$saln->reference()} is cleared for the oath."
            : "{$saln->reference()} was returned with your remarks.");
    }

    public function file(Request $request, Saln $saln, SalnFilings $filings): RedirectResponse
    {
        $this->authorize('file', $saln);

        return $this->fileScan($request, $saln, $filings);
    }

    public function exempt(Request $request, SalnFilings $filings): RedirectResponse
    {
        $this->authorize('manage', Saln::class);
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            $filings->exempt(Employee::query()->findOrFail($data['employee_id']), (int) $data['year'], $data['reason'], $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('status', 'The exemption is recorded.');
    }

    /**
     * HRD's SALN switches (System Settings → Human Resources holds them too).
     */
    public function settings(Request $request): RedirectResponse
    {
        $this->authorize('manage', Saln::class);
        $data = $request->validate(['multiple_marriages' => ['required', 'boolean']]);

        $setting = Setting::query()->where('key', 'saln_multiple_marriages')->first();
        if (! $setting) {
            SettingRegistry::sync();
            $setting = Setting::query()->where('key', 'saln_multiple_marriages')->firstOrFail();
        }

        $setting->value = $setting->type->serialize((bool) $data['multiple_marriages']);
        $setting->save();
        $this->audit()->recordChanges(AuditEvent::SettingUpdated, "Updated setting {$setting->label}", $setting, ['key' => $setting->key, 'group' => $setting->group]);

        return back()->with('status', $data['multiple_marriages']
            ? 'Employees are now asked about multiple marriages on their SALN.'
            : 'The multiple-marriages question is hidden; the form prints "Not Applicable".');
    }

    public function open(Request $request, SalnFilings $filings): RedirectResponse
    {
        $this->authorize('manage', Saln::class);
        $employee = Employee::query()->findOrFail($request->integer('employee_id'));
        abort_if($employee->user_id === $request->user()->id, 403, 'File your own SALN from My SALN.');

        return $this->openFiling($request, $employee, $filings, fn (Saln $saln) => to_route('admin.hr.saln.edit', $saln));
    }

    public function edit(Request $request, Saln $saln, SalnPresenter $presenter): Response
    {
        $this->authorize('update', $saln);
        abort_if($saln->employee->user_id === $request->user()->id, 403);
        $this->auditAccess($request, $saln, AuditEvent::SalnViewed, 'Opened for encoding');

        return Inertia::render('Admin/HR/Saln/Edit', [
            'saln' => $presenter->detail($saln),
            'options' => SalnPresenter::options(),
        ]);
    }

    public function update(Request $request, Saln $saln, SalnFilings $filings): RedirectResponse
    {
        $this->authorize('update', $saln);
        abort_if($saln->employee->user_id === $request->user()->id, 403);

        return $this->saveStatement($request, $saln, $filings);
    }

    public function generate(Request $request, Saln $saln, SalnFilings $filings): RedirectResponse
    {
        $this->authorize('update', $saln);
        abort_if($saln->employee->user_id === $request->user()->id, 403);

        return $this->generateVersion($request, $saln, $filings, route('admin.hr.saln.edit', $saln, false));
    }

    /**
     * Someone other than the employee looking at their SALN (RA 10173).
     */
    private function auditAccess(Request $request, Saln $saln, AuditEvent $event, string $verb): void
    {
        if ($saln->employee->user_id !== $request->user()->id) {
            $this->audit()->record($event, "{$verb} the SALN {$saln->reference()} of ".($saln->employee->user?->name ?? 'an employee'), $saln);
        }
    }
}
