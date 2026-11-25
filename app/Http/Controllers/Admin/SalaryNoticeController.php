<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Enums\SalaryNoticeType;
use App\Http\Controllers\Controller;
use App\Models\PlantillaAppointment;
use App\Models\PlantillaItem;
use App\Models\SalaryNotice;
use App\Models\SalarySchedule;
use App\Support\HR\EmployeeDocuments;
use App\Support\HR\SalaryNotices\NoticeSignatory;
use App\Support\HR\SalaryNotices\SalaryNotices;
use App\Support\HR\SalaryNotices\StepIncrementsDue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Notices of Salary Adjustment (NOSA) and of Step Increment (NOSI):
 * generate, print with a blank signature space, and file the signed copy
 * in the 201 File. `?tab=` is notices (the register), nosa or nosi.
 */
class SalaryNoticeController extends Controller
{
    public function index(Request $request, SalaryNotices $notices, StepIncrementsDue $due, NoticeSignatory $signatories): Response
    {
        $this->authorize('manage', SalaryNotice::class);

        $user = $request->user();
        $tab = in_array($request->query('tab'), ['notices', 'nosa', 'nosi'], true) ? $request->query('tab') : 'notices';
        $schedules = SalarySchedule::query()->orderByDesc('effective_date')->get();
        $years = SalaryNotice::query()->distinct()->pluck('effective_date')
            ->map(fn ($date) => (int) substr((string) $date, 0, 4))
            ->push((int) today()->year)->unique()->sortDesc()->values();
        $year = $request->integer('year') ?: (int) today()->year;
        $type = in_array($request->query('type'), ['nosa', 'nosi'], true) ? $request->query('type') : null;
        $schedule = $tab === 'nosa' ? ($schedules->firstWhere('id', $request->integer('schedule')) ?? $schedules->first()) : null;

        return Inertia::render('Admin/HR/SalaryNotices/Index', [
            'tab' => $tab,
            'filters' => ['year' => $year, 'type' => $type],
            'years' => $years,
            'notices' => $tab === 'notices' ? SalaryNotice::query()
                ->whereYear('effective_date', $year)
                ->when($type, fn ($query, $type) => $query->where('type', $type))
                ->with('employee.user')
                ->orderByDesc('effective_date')->orderBy('addressee')->get()
                ->map(fn (SalaryNotice $notice) => $notices->present($notice, $user)) : [],
            'signatories' => $signatories->options(),
            'schedules' => $schedules->map(fn (SalarySchedule $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'effective_date' => $item->effective_date->toDateString(),
                'reference' => $item->reference,
                'issuance_date' => $item->issuance_date?->toDateString(),
                'implements' => $item->implements,
            ]),
            'nosa' => $schedule ? ['schedule_id' => $schedule->id, ...$notices->nosaCandidates($schedule, $user)] : null,
            'due' => $tab === 'nosi' ? $due->due() : [],
            'recorded' => $tab === 'nosi' ? $due->recordedWithoutNotice() : [],
            'today' => today()->toDateString(),
        ]);
    }

    public function generateNosa(Request $request, SalaryNotices $notices): RedirectResponse
    {
        $this->authorize('manage', SalaryNotice::class);

        $data = $request->validate([
            'schedule_id' => ['required', 'integer', 'exists:salary_schedules,id'],
            'notice_date' => ['required', 'date', 'after:2000-01-01'],
            'signatory' => ['required', Rule::in(array_keys(NoticeSignatory::POSITIONS))],
        ], [], ['notice_date' => 'date of the notices']);

        $schedule = SalarySchedule::query()->findOrFail($data['schedule_id']);
        $created = $notices->generateNosa($schedule, $data['notice_date'], $data['signatory'], $request->user());

        $this->audit()->record(AuditEvent::SalaryNoticeGenerated, "Generated {$created->count()} NOSA under {$schedule->label}", $schedule, [
            'type' => 'nosa',
            'count' => $created->count(),
            'notice_ids' => $created->pluck('id')->all(),
        ]);

        return to_route('admin.hr.salary-notices.index', ['tab' => 'notices', 'type' => 'nosa', 'year' => $schedule->effective_date->year])
            ->with('status', "{$created->count()} Notices of Salary Adjustment were generated. Print them for signing.");
    }

    public function generateNosi(Request $request, SalaryNotices $notices): RedirectResponse
    {
        $this->authorize('manage', SalaryNotice::class);

        $data = $request->validate([
            'appointment_id' => ['required', 'integer', 'exists:plantilla_appointments,id'],
            // Record the step increment now (from the due list), or only write the notice for one already recorded.
            'record' => ['required', 'boolean'],
            'effective_date' => ['required_if:record,true', 'nullable', 'date'],
            'notice_date' => ['required', 'date', 'after:2000-01-01'],
            'signatory' => ['required', Rule::in(array_keys(NoticeSignatory::POSITIONS))],
        ], [], ['notice_date' => 'date of the notice', 'effective_date' => 'effective date']);

        $period = PlantillaAppointment::query()->with(['item', 'employee.user'])->findOrFail($data['appointment_id']);

        if ($data['record']) {
            $this->authorize('appoint', [PlantillaItem::class, $period->employee]);
            $notice = $notices->recordStepIncrement($period, $data['effective_date'], $data['notice_date'], $data['signatory'], $request->user());
            $this->audit()->record(AuditEvent::PlantillaStepIncrement, "Step increment for {$notice->appointment->label}", $notice->appointment, ['from_step' => $period->step, 'to_step' => $notice->to_step, 'with_notice' => true]);
        } else {
            $notice = $notices->generateNosi($period, $data['notice_date'], $data['signatory'], $request->user());
        }

        $this->audit()->record(AuditEvent::SalaryNoticeGenerated, "Generated {$notice->label}", $notice, ['type' => 'nosi']);

        return to_route('admin.hr.salary-notices.index', ['tab' => 'notices', 'type' => 'nosi', 'year' => $notice->effective_date->year])
            ->with('status', "The Notice of Step Increment for {$notice->employee->user->name} was generated. Print it for signing.");
    }

    /**
     * One notice or several (`ids`), one page each (a NOSA prints an
     * employee's copy and an OHRMD/HRD copy).
     */
    public function print(Request $request, SalaryNotices $notices): Response
    {
        $this->authorize('manage', SalaryNotice::class);

        $ids = collect((array) $request->query('ids'))->map(fn ($id) => (int) $id)->filter()->unique()->take(500);
        abort_if($ids->isEmpty(), 404);

        $selected = SalaryNotice::query()->with('employee.user')->findMany($ids)->sortBy(fn (SalaryNotice $notice) => [$notice->type->value, $notice->addressee])->values();
        abort_if($selected->isEmpty(), 404);

        foreach ($selected as $notice) {
            $this->authorize('view', $notice);

            if ($notice->employee->user_id !== $request->user()->id) {
                $this->audit()->record(AuditEvent::SalaryNoticePrinted, "Printed {$notice->label}", $notice->employee, ['salary_notice_id' => $notice->id]);
            }
        }

        return Inertia::render('HR/SalaryNoticePrint', [
            'notices' => $selected->map(fn (SalaryNotice $notice) => $notices->printable($notice)),
            'title' => $selected->count() === 1 ? $notices->fileName($selected->first()) : $selected->count().' salary notices',
        ]);
    }

    public function destroy(SalaryNotice $notice, SalaryNotices $notices): RedirectResponse
    {
        $this->authorize('delete', $notice);

        $label = $notice->label;
        $notices->delete($notice);

        $this->audit()->record(AuditEvent::SalaryNoticeDeleted, "Deleted {$label}", $notice->employee, ['salary_notice_id' => $notice->id, 'type' => $notice->type->value]);

        return back()->with('status', $notice->type === SalaryNoticeType::Nosi
            ? 'The notice was deleted. The step increment stays on the Plantilla; remove it there if it was recorded by mistake.'
            : 'The notice was deleted.');
    }

    /** The signed copy of one notice, into the 201 File. */
    public function file(Request $request, SalaryNotice $notice, SalaryNotices $notices): RedirectResponse
    {
        $this->authorize('file', $notice);

        $request->validate(['file' => $this->fileRules()], ['file.mimes' => 'Upload a PDF, JPG or PNG file.']);
        $notices->file($notice, $request->file('file'), $request->user());
        $this->recordFiled($notice);

        return back()->with('status', "The signed notice is now in {$notice->employee->user->name}'s 201 File.");
    }

    /**
     * Several signed copies at once, matched to unfiled notices by HRD's
     * file names ("NOSA_Uy, Rey Albert.pdf").
     */
    public function fileMany(Request $request, SalaryNotices $notices): RedirectResponse
    {
        $this->authorize('manage', SalaryNotice::class);

        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:100'],
            'files.*' => $this->fileRules(),
        ], ['files.*.mimes' => 'Upload PDF, JPG or PNG files.']);

        $user = $request->user();
        $candidates = SalaryNotice::query()->unfiled()->with('employee.user')->get()->filter(fn (SalaryNotice $notice) => $user->can('file', $notice))->values();
        ['matched' => $matched, 'unmatched' => $unmatched] = $notices->match($request->file('files'), $candidates);

        DB::transaction(function () use ($matched, $notices, $user) {
            foreach ($matched as ['file' => $file, 'notice' => $notice]) {
                $notices->file($notice, $file, $user);
                $this->recordFiled($notice);
            }
        });

        $message = count($matched).' signed '.str('notice')->plural(count($matched)).' filed in the 201 File.';
        if ($unmatched !== []) {
            $message .= ' Not matched (file these one by one): '.implode(', ', $unmatched).'.';
        }

        return back()->with(count($matched) > 0 ? 'status' : 'error', $message);
    }

    /**
     * @return array<int, string>
     */
    private function fileRules(): array
    {
        return ['required', 'file', 'mimes:'.implode(',', EmployeeDocuments::EXTENSIONS), 'max:'.EmployeeDocuments::MAX_KILOBYTES];
    }

    private function recordFiled(SalaryNotice $notice): void
    {
        $notice->load('document');

        $this->audit()->record(AuditEvent::SalaryNoticeFiled, "Filed the signed {$notice->label}", $notice, ['employee_document_id' => $notice->employee_document_id]);
        $this->audit()->record(AuditEvent::EmployeeDocumentUploaded, "Uploaded {$notice->document->label}", $notice->document, ['type' => $notice->document->document_type->value, 'size' => $notice->document->size]);
    }
}
