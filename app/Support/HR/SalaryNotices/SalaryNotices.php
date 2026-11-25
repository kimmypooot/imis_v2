<?php

namespace App\Support\HR\SalaryNotices;

use App\Enums\AppointmentNature;
use App\Enums\EmployeeDocumentType;
use App\Enums\SalaryNoticeType;
use App\Enums\Sex;
use App\Models\Employee;
use App\Models\PlantillaAppointment;
use App\Models\SalaryNotice;
use App\Models\SalarySchedule;
use App\Models\User;
use App\Support\HR\EmployeeDocuments;
use App\Support\HR\Plantilla\Plantilla;
use App\Support\HR\Plantilla\SalarySchedules;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of salary notices: Notices of Salary Adjustment (NOSA)
 * when a new salary schedule takes effect, and Notices of Step Increment
 * (NOSI) due to length of service.
 *
 * HRD generates them here, prints them on the office's templates with a
 * blank signature space, has them signed (by hand or with a PNPKI digital
 * signature, outside IMIS), and files the signed copy in the employee's
 * 201 File. Each notice keeps what it printed, so later changes to the
 * plantilla or a schedule never alter a notice. Nobody prepares their own.
 *
 * NOSA amounts follow NBC No. 601 §5.1: the same grade and step at the
 * new schedule's rate. A salary set by hand (between steps, or above
 * step 8) isn't adjusted automatically. Directors' notices come from the
 * Central Office and are left out.
 */
class SalaryNotices
{
    /** The basis of a NOSI, as the office's template words it. */
    public const NOSI_BASIS = 'the Civil Service Commission (CSC) and Department of Budget and Management (DBM) Joint Circular No. 1, dated September 3, 2012 implementing item (4)(d) of the Senate and House of Representatives Joint Resolution No. 4, s. 2009, as approved on June 17, 2009';

    public const NOSA_OFFICE = 'Regional Office VIII';

    public const NOSI_OFFICE = 'CSC Regional Office VIII (CSC- RO VIII)';

    public function __construct(
        private readonly SalarySchedules $schedules,
        private readonly Plantilla $plantilla,
        private readonly NoticeSignatory $signatories,
        private readonly EmployeeDocuments $documents,
    ) {}

    /** Directors' notices are issued by the Central Office. */
    public static function issuedByCentralOffice(?string $positionTitle): bool
    {
        return (bool) preg_match('/^\s*director\b/i', (string) $positionTitle);
    }

    // ── NOSA ─────────────────────────────────────────────────────────

    /**
     * Who a schedule's NOSA batch covers: everyone holding a plantilla
     * item on the day before it takes effect and still holding it then.
     * Each is ready (with the amounts) or skipped with the reason.
     *
     * @return array{ready: Collection<int, array<string, mixed>>, skipped: Collection<int, array<string, mixed>>}
     */
    public function nosaCandidates(SalarySchedule $schedule, User $preparer): array
    {
        $effective = $schedule->effective_date->copy();
        $dayBefore = $effective->copy()->subDay();
        $generated = SalaryNotice::query()->where('type', SalaryNoticeType::Nosa)->where('salary_schedule_id', $schedule->id)->pluck('employee_id')->flip();

        $periods = PlantillaAppointment::query()
            ->whereDate('date_from', '<=', $dayBefore)
            ->where(fn ($query) => $query->whereNull('date_to')->orWhereDate('date_to', '>=', $effective))
            ->with(['item', 'employee.user'])->get()
            ->filter(fn (PlantillaAppointment $period) => $period->employee?->user !== null)
            ->sortBy(fn (PlantillaAppointment $period) => Str::lower($period->employee->user->last_name ?? $period->employee->user->name));

        $ready = collect();
        $skipped = collect();

        foreach ($periods as $period) {
            $row = [
                'appointment_id' => $period->id,
                'employee_id' => $period->employee_id,
                'name' => $period->employee->user->name,
                'photo_url' => $period->employee->user->profile_photo_url,
                'position' => $period->item->position_title,
                'item_number' => $period->item->item_number,
                'salary_grade' => $period->item->salary_grade,
                'step' => $period->step,
            ];
            $reason = $this->nosaSkipReason($period, $schedule, $preparer, $generated->has($period->employee_id));

            if ($reason !== null) {
                $skipped->push([...$row, 'reason' => $reason]);

                continue;
            }

            $ready->push([
                ...$row,
                'from_salary' => $this->schedules->salaryOf($period, $dayBefore),
                'to_salary' => $this->schedules->monthlySalary($period->item->salary_grade, $period->step, $effective),
            ]);
        }

        return ['ready' => $ready->values(), 'skipped' => $skipped->values()];
    }

    /**
     * Generates the NOSA of everyone ready in a schedule's batch.
     *
     * @return Collection<int, SalaryNotice>
     */
    public function generateNosa(SalarySchedule $schedule, string $noticeDate, string $signatory, User $preparer): Collection
    {
        if ($schedule->issuance_date === null || blank($schedule->reference)) {
            throw ValidationException::withMessages(['schedule' => 'Enter the schedule\'s reference and the date of the issuance first (Salary Schedules), as the NOSA quotes them.']);
        }

        $signer = $this->signatories->resolve($signatory);
        $basis = trim($schedule->reference).' dated '.$schedule->issuance_date->format('d F Y')
            .', implementing '.trim($schedule->implements ?: 'Executive Order No. 64 s. 2024 dated 02 August 2024');
        $ready = $this->nosaCandidates($schedule, $preparer)['ready'];

        if ($ready->isEmpty()) {
            throw ValidationException::withMessages(['schedule' => 'Nobody is ready for a notice under this schedule (see the reasons below).']);
        }

        $periods = PlantillaAppointment::query()->with(['item', 'employee.user'])->findMany($ready->pluck('appointment_id'))->keyBy('id');

        return DB::transaction(fn () => $ready->map(function (array $row) use ($periods, $schedule, $noticeDate, $basis, $signer, $preparer) {
            $period = $periods[$row['appointment_id']];

            return $this->create($period, [
                'type' => SalaryNoticeType::Nosa,
                'salary_schedule_id' => $schedule->id,
                'notice_date' => $noticeDate,
                'effective_date' => $schedule->effective_date->toDateString(),
                'basis' => $basis,
                'addressee' => $this->plainName($period->employee->user),
                'salutation' => null,
                'from_step' => $period->step,
                'from_salary' => $row['from_salary'],
                'to_step' => $period->step,
                'to_salary' => $row['to_salary'],
            ], $signer, $preparer);
        }));
    }

    // ── NOSI ─────────────────────────────────────────────────────────

    /**
     * Records the step increment on the plantilla (the next step, from
     * the effective date) and generates its notice, together.
     */
    public function recordStepIncrement(PlantillaAppointment $current, string $effectiveDate, string $noticeDate, string $signatory, User $preparer): SalaryNotice
    {
        $this->assertMayPrepare($current->employee, $preparer);
        $signer = $this->signatories->resolve($signatory);

        return DB::transaction(function () use ($current, $effectiveDate, $noticeDate, $signer, $preparer) {
            $next = $this->plantilla->stepIncrement($current, $current->step + 1, $effectiveDate, 'Step increment due to length of service', $preparer);

            return $this->nosiFor($next, $noticeDate, $signer, $preparer);
        });
    }

    /** A notice for a step increment already recorded on the plantilla. */
    public function generateNosi(PlantillaAppointment $increment, string $noticeDate, string $signatory, User $preparer): SalaryNotice
    {
        $this->assertMayPrepare($increment->employee, $preparer);

        if ($increment->nature !== AppointmentNature::StepIncrement) {
            throw ValidationException::withMessages(['appointment_id' => 'That period isn\'t a step increment.']);
        }
        if (SalaryNotice::query()->where('type', SalaryNoticeType::Nosi)->where('plantilla_appointment_id', $increment->id)->exists()) {
            throw ValidationException::withMessages(['appointment_id' => 'This step increment already has a notice.']);
        }

        return $this->nosiFor($increment, $noticeDate, $this->signatories->resolve($signatory), $preparer);
    }

    // ── Deleting, filing ─────────────────────────────────────────────

    /**
     * A notice not yet filed can be deleted (and generated again). The
     * step increment a NOSI recorded stays on the plantilla.
     */
    public function delete(SalaryNotice $notice): void
    {
        if ($notice->isFiled()) {
            throw ValidationException::withMessages(['notice' => 'This notice is already in the 201 File and can\'t be deleted. Archive the document there if it was wrong.']);
        }

        $notice->delete();
    }

    /** Files the signed copy in the employee's 201 File. */
    public function file(SalaryNotice $notice, UploadedFile $file, User $uploader): SalaryNotice
    {
        if ($notice->isFiled()) {
            throw ValidationException::withMessages(['file' => 'This notice is already in the 201 File.']);
        }

        return DB::transaction(function () use ($notice, $file, $uploader) {
            $document = $this->documents->store($notice->employee, $file, [
                'document_type' => EmployeeDocumentType::SalaryAdjustment->value,
                'title' => $notice->type->label().' effective '.$notice->effective_date->format('F j, Y'),
                'document_date' => $notice->notice_date->toDateString(),
            ], $uploader);

            $notice->forceFill(['employee_document_id' => $document->id, 'filed_at' => now()])->save();

            return $notice;
        });
    }

    /**
     * Matches signed copies to unfiled notices by HRD's file names
     * ("NOSA_Uy, Rey Albert.pdf"); a name matching none, or more than one,
     * is left for HR to file by hand.
     *
     * @param  array<int, UploadedFile>  $files
     * @param  Collection<int, SalaryNotice>  $candidates  unfiled notices HR may file
     * @return array{matched: array<int, array{file: UploadedFile, notice: SalaryNotice}>, unmatched: array<int, string>}
     */
    public function match(array $files, Collection $candidates): array
    {
        $byName = $candidates->groupBy(fn (SalaryNotice $notice) => $this->normalize($this->fileName($notice)));
        $matched = [];
        $unmatched = [];

        foreach ($files as $file) {
            $key = $this->normalize(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
            $notices = $byName->get($key);

            if ($notices === null || $notices->count() !== 1) {
                $unmatched[] = $file->getClientOriginalName();

                continue;
            }

            $matched[] = ['file' => $file, 'notice' => $notices->first()];
            $byName->forget($key);
        }

        return ['matched' => $matched, 'unmatched' => $unmatched];
    }

    // ── Presenting ───────────────────────────────────────────────────

    /** HRD's file name: "NOSA_Uy, Rey Albert". */
    public function fileName(SalaryNotice $notice): string
    {
        $user = $notice->employee->user;
        $name = $user->last_name && $user->first_name ? "{$user->last_name}, {$user->first_name}" : $user->name;

        return $notice->type->code().'_'.$name;
    }

    /**
     * A row of the notices list.
     *
     * @return array<string, mixed>
     */
    public function present(SalaryNotice $notice, User $viewer): array
    {
        return [
            'id' => $notice->id,
            'type' => $notice->type->value,
            'type_code' => $notice->type->code(),
            'employee_id' => $notice->employee_id,
            'name' => $notice->employee->user->name,
            'photo_url' => $notice->employee->user->profile_photo_url,
            'position' => $notice->position_title,
            'salary_grade' => $notice->salary_grade,
            'from_step' => $notice->from_step,
            'to_step' => $notice->to_step,
            'from_salary' => (float) $notice->from_salary,
            'to_salary' => (float) $notice->to_salary,
            'difference' => $notice->difference,
            'notice_date' => $notice->notice_date->toDateString(),
            'effective_date' => $notice->effective_date->toDateString(),
            'file_name' => $this->fileName($notice),
            'filed' => $notice->isFiled(),
            'filed_at' => $notice->filed_at?->toIso8601String(),
            'document_id' => $notice->employee_document_id,
            'own' => $notice->employee->user_id === $viewer->id,
        ];
    }

    /**
     * Everything the print page writes on the template, already worded.
     *
     * @return array<string, mixed>
     */
    public function printable(SalaryNotice $notice): array
    {
        $effective = $notice->effective_date;
        $before = $effective->copy()->subDay();
        $money = fn ($amount) => number_format((float) $amount, 2);
        $nosa = $notice->type === SalaryNoticeType::Nosa;

        return [
            'id' => $notice->id,
            'type' => $notice->type->value,
            'title' => $nosa ? 'Notice of Salary Adjustment' : 'Notice of Step Increment Due to Length of Service',
            'file_name' => $this->fileName($notice),
            'notice_date' => $notice->notice_date->format($nosa ? 'd F Y' : 'j F Y'),
            'addressee' => Str::upper($notice->addressee),
            'office' => $nosa ? self::NOSA_OFFICE : self::NOSI_OFFICE,
            'salutation' => $nosa ? 'Sir/Ma’am:' : $notice->salutation,
            'basis' => $notice->basis,
            'position' => $notice->position_title,
            'position_upper' => Str::upper($notice->position_title),
            'salary_grade' => $notice->salary_grade,
            'from_step' => $notice->from_step,
            'to_step' => $notice->to_step,
            'from_salary' => $money($notice->from_salary),
            'to_salary' => $money($notice->to_salary),
            'difference' => $money($notice->difference),
            // NOSA dates are "01 January 2026"; NOSI dates "January 3, 2026".
            'effective' => $effective->format($nosa ? 'd F Y' : 'F j, Y'),
            'as_of' => $before->format($nosa ? 'd F Y' : 'F j, Y'),
            'item_number' => $notice->item_number,
            'year' => $effective->year,
            'signatory_name' => Str::upper($notice->signatory_name),
            'signatory_position' => $notice->signatory_position,
        ];
    }

    // ── Internals ────────────────────────────────────────────────────

    private function nosaSkipReason(PlantillaAppointment $period, SalarySchedule $schedule, User $preparer, bool $generated): ?string
    {
        $effective = $schedule->effective_date;
        $item = $period->item;

        if ($generated) {
            return 'Already generated for this schedule.';
        }
        if (self::issuedByCentralOffice($item->position_title)) {
            return 'Issued by the Central Office.';
        }
        if ($period->employee->user_id === $preparer->id) {
            return 'Your own notice: another HR staff member generates it.';
        }
        if (! $period->isComplete()) {
            return 'Details incomplete on the Plantilla ('.implode(', ', $period->missingDetails()).').';
        }

        $new = $this->schedules->monthlySalary($item->salary_grade, $period->step, $effective);
        if ($new === null) {
            return "This schedule has no amount for SG {$item->salary_grade}, Step {$period->step}.";
        }

        if ($period->salary_override !== null) {
            $override = (float) $period->salary_override;
            $stepEight = $this->schedules->monthlySalary($item->salary_grade, SalarySchedules::STEPS, $effective);

            return $stepEight !== null && $override >= $stepEight
                ? 'Paid ₱'.number_format($override, 2).', above the new Step 8: no adjustment (NBC §5.1.3).'
                : 'Salary set by hand (₱'.number_format($override, 2).'): prepare this notice by hand (NBC §5.1.2).';
        }

        $old = $this->schedules->salaryOf($period, $effective->copy()->subDay());
        if ($old === null) {
            return "No salary for SG {$item->salary_grade}, Step {$period->step} in the schedule before this one.";
        }
        if ($new <= $old) {
            return 'No increase under this schedule.';
        }

        return null;
    }

    /**
     * @param  array{name: string, position: string}  $signer
     */
    private function nosiFor(PlantillaAppointment $increment, string $noticeDate, array $signer, User $preparer): SalaryNotice
    {
        $increment->loadMissing(['item', 'employee.user']);
        $previous = PlantillaAppointment::query()
            ->where('employee_id', $increment->employee_id)
            ->where('plantilla_item_id', $increment->plantilla_item_id)
            ->whereDate('date_to', $increment->date_from->copy()->subDay())
            ->first();

        if ($previous === null) {
            throw ValidationException::withMessages(['appointment_id' => 'The period before this step increment isn\'t on the Plantilla, so the notice has no salary to start from.']);
        }

        $from = $this->schedules->salaryOf($previous, $increment->date_from->copy()->subDay());
        $to = $this->schedules->salaryOf($increment, $increment->date_from);

        if ($from === null || $to === null) {
            throw ValidationException::withMessages(['appointment_id' => "The salary schedule has no amount for SG {$increment->item->salary_grade}, Step ".($from === null ? $previous->step : $increment->step).'. Enter it under Salary Schedules first.']);
        }

        return $this->create($increment, [
            'type' => SalaryNoticeType::Nosi,
            'salary_schedule_id' => null,
            'notice_date' => $noticeDate,
            'effective_date' => $increment->date_from->toDateString(),
            'basis' => self::NOSI_BASIS,
            'addressee' => $increment->employee->user->name,
            'salutation' => $this->salutation($increment->employee->user),
            'from_step' => $previous->step,
            'from_salary' => $from,
            'to_step' => $increment->step,
            'to_salary' => $to,
        ], $signer, $preparer);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{name: string, position: string}  $signer
     */
    private function create(PlantillaAppointment $period, array $data, array $signer, User $preparer): SalaryNotice
    {
        $notice = new SalaryNotice;
        $notice->forceFill([
            ...$data,
            'employee_id' => $period->employee_id,
            'plantilla_appointment_id' => $period->id,
            'position_title' => $period->item->position_title,
            'item_number' => $period->item->item_number,
            'salary_grade' => $period->item->salary_grade,
            'signatory_name' => $signer['name'],
            'signatory_position' => $signer['position'],
            'prepared_by' => $preparer->id,
        ])->save();

        return $notice;
    }

    private function assertMayPrepare(?Employee $employee, User $preparer): void
    {
        if ($employee?->user_id === $preparer->id) {
            throw ValidationException::withMessages(['appointment_id' => 'Another HR staff member prepares your own notice.']);
        }
    }

    /** The name without an honorific, as the NOSA template prints it. */
    private function plainName(User $user): string
    {
        return $user->first_name
            ? User::composeName($user->first_name, $user->middle_name, $user->last_name, $user->name_extension)
            : $user->name;
    }

    /** "Dear Atty. Aguillon:", "Dear Mr. Casinillo:". */
    private function salutation(User $user): string
    {
        $title = $user->honorific ?: match ($user->sex) {
            Sex::Male, 'male' => 'Mr.',
            Sex::Female, 'female' => 'Ms.',
            default => null,
        };
        $surname = $user->last_name ?: Str::afterLast(trim($user->name), ' ');

        return $title ? "Dear {$title} {$surname}:" : 'Dear Sir/Madam:';
    }

    private function normalize(string $name): string
    {
        return Str::of($name)->lower()->replaceMatches('/\s+/', ' ')->replace(' ,', ',')->trim()->value();
    }
}
