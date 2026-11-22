<?php

namespace App\Support\HR\Leave;

use App\Enums\LeaveDetail;
use App\Enums\LeaveStepAction;
use App\Models\LeaveApplication;
use App\Models\LeaveApplicationStep;
use App\Models\LeaveType;
use App\Models\NonPlantillaContract;
use App\Models\PlantillaAppointment;
use App\Models\User;
use App\Support\HR\Plantilla\SalarySchedules;
use App\Support\Settings\Settings;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Every entry of CS Form No. 6, Revised 2020 (Application for Leave) for
 * one application, worded and formatted the way CSC's own leave system
 * prints it: "Oct. 28, 2026", "1.000 day", "11/3/26" (chosen dates
 * "11/3/26 - 11/5/26, 11/10/26"), credits to three decimals, the leave
 * on the "Others" line by name ("Wellness Leave"). The printable page and
 * the Excel export both read this, so they always say the same thing.
 *
 * Signatories are whoever acted on the current round of the approval
 * chain: the supervisor recommends (7.B), HR certifies the credits (7.A)
 * and the head or an officer-in-charge decides (7.C/7.D).
 */
class LeaveForm
{
    /**
     * 6.A, in the form's order, with the legal basis printed beside each
     * box. Leave types not on the form (e.g. Wellness Leave, LWOP) go on
     * the "Others" line.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const TYPES = [
        'VL' => ['Vacation Leave', 'Sec. 51, Rule XVI, Omnibus Rules Implementing E.O. No. 292'],
        'FL' => ['Mandatory/Forced Leave', 'Sec. 25, Rule XVI, Omnibus Rules Implementing E.O. No. 292'],
        'SL' => ['Sick Leave', 'Sec. 43, Rule XVI, Omnibus Rules Implementing E.O. No. 292'],
        'ML' => ['Maternity Leave', 'R.A. No. 11210 / IRR issued by CSC, DOLE and SSS'],
        'PL' => ['Paternity Leave', 'R.A. No. 8187 / CSC MC No. 71, s. 1998, as amended'],
        'SPL' => ['Special Privilege Leave', 'Sec. 21, Rule XVI, Omnibus Rules Implementing E.O. No. 292'],
        'SOLO' => ['Solo Parent Leave', 'RA No. 8972 / CSC MC No. 8, s. 2004'],
        'STUDY' => ['Study Leave', 'Sec. 68, Rule XVI, Omnibus Rules Implementing E.O. No. 292'],
        'VAWC' => ['10-Day VAWC Leave', 'RA No. 9262 / CSC MC No. 15, s. 2005'],
        'RL' => ['Rehabilitation Privilege', 'Sec. 55, Rule XVI, Omnibus Rules Implementing E.O. No. 292'],
        'SLBW' => ['Special Leave Benefits for Women', 'RA No. 9710 / CSC MC No. 25, s. 2010'],
        'SEL' => ['Special Emergency (Calamity) Leave', 'CSC MC No. 2, s. 2012, as amended'],
        'AL' => ['Adoption Leave', 'R.A. No. 8552'],
    ];

    public function __construct(private SalarySchedules $schedules) {}

    /**
     * @param  ?User  $viewer  who prints it ("Printed by:" at the foot)
     * @return array<string, mixed>
     */
    public function present(LeaveApplication $application, ?User $viewer = null): array
    {
        $application->loadMissing(['leaveType.chargedTo', 'employee.user.organizationalUnit']);
        $user = $application->employee->user;
        $type = $application->leaveType;
        $steps = $application->currentSteps()->with(['actor:id,name,position', 'actingFor:id,name'])->get()->keyBy('role');
        $supervisor = $this->acted($steps->get('supervisor'));
        $hr = $this->acted($steps->get('hr'));
        $head = $this->acted($steps->get('head'));
        $approved = $head?->action === LeaveStepAction::Approved;
        $agency = (string) Settings::get('organization_name');

        return [
            'reference_number' => $application->reference_number,
            'status_label' => $application->status->label(),
            'agency' => [
                'name' => $agency,
                'address' => (string) Settings::get('office_address'),
            ],
            // As CSC's system fills it: the agency (regional office), not the division.
            'office' => $agency,
            'name' => [
                'last' => Str::upper((string) ($user->last_name ?? $user->name)),
                'first' => Str::upper(trim($user->first_name.' '.$user->name_extension)),
                'middle' => Str::upper((string) $user->middle_name),
            ],
            'filed_on' => $this->filedOn($application->submitted_at),
            'position' => Str::upper((string) $user->position),
            'salary' => $this->salary($application),
            'type' => array_key_exists($type->code, self::TYPES) ? $type->code : 'others',
            'others' => array_key_exists($type->code, self::TYPES) ? '' : $this->othersLine($application),
            'details' => $this->details($application),
            'days' => $this->days($application),
            'inclusive_dates' => LeaveDates::numeric($application),
            'is_emergency' => $application->is_emergency,
            'commutation_requested' => $application->commutation_requested,
            'applicant' => Str::upper((string) $user->name),
            'credits' => $hr && $hr->action === LeaveStepAction::Certified ? $this->credits($application, $hr) : null,
            'certifier' => $this->signer($hr),
            'recommendation_required' => $steps->has('supervisor'),
            'recommendation' => match ($supervisor?->action) {
                LeaveStepAction::Recommended => 'approval',
                LeaveStepAction::NotRecommended => 'disapproval',
                default => null,
            },
            'recommendation_reason' => $supervisor?->action === LeaveStepAction::NotRecommended ? (string) $supervisor->remarks : '',
            'recommender' => $this->signer($supervisor),
            'approved_for' => [
                'with_pay' => $approved ? number_format((float) $application->days_with_pay, 3) : '',
                'without_pay' => $approved && (float) $application->days_without_pay > 0 ? number_format((float) $application->days_without_pay, 3) : '',
                'others' => '',
            ],
            'disapproval_reason' => match (true) {
                $head?->action === LeaveStepAction::Disapproved => (string) $head->remarks,
                $hr?->action === LeaveStepAction::InsufficientCredits => (string) $hr->remarks,
                default => '',
            },
            'approver' => $this->signer($head),
            // When the head (or OIC) decided, printed at the foot of 7.C/7.D.
            'decided_at' => $head?->acted_at ? $this->local($head->acted_at)->format('m/d/Y h:i A') : '',
            'printed_by' => (string) ($viewer?->email ?? ''),
        ];
    }

    /**
     * 5. SALARY: the monthly salary IMIS pays on the date of filing — the
     * plantilla period held that day, priced as payroll and the service
     * record price it (HR's override, else the salary schedule in force),
     * or, for contract staff, the contract's rate ("/day" when daily).
     * Blank when IMIS has no salary for them.
     */
    public function salary(LeaveApplication $application): string
    {
        $on = $application->submitted_at ? $this->local($application->submitted_at)->startOfDay() : today();
        $employee = $application->employee;

        $period = $employee->plantillaAppointments()->complete()->with('item')
            ->whereDate('date_from', '<=', $on)
            ->where(fn ($query) => $query->whereNull('date_to')->orWhereDate('date_to', '>=', $on))
            ->latest('date_from')->latest('id')->first()
            // Filed before the first period (e.g. a record set up later): the latest one.
            ?? $employee->plantillaAppointments()->complete()->with('item')->latest('date_from')->latest('id')->first();
        $monthly = $period instanceof PlantillaAppointment ? $this->schedules->salaryOf($period, $on) : null;
        if ($monthly !== null) {
            return 'Php '.number_format($monthly, 2);
        }

        $contract = $employee->nonPlantillaContracts()->whereNotNull('rate')
            ->where(fn ($query) => $query->whereNull('date_from')->orWhereDate('date_from', '<=', $on))
            ->where(fn ($query) => $query->whereNull('date_to')->orWhereDate('date_to', '>=', $on))
            ->latest('date_from')->first();

        return $contract instanceof NonPlantillaContract
            ? 'Php '.number_format((float) $contract->rate, 2).($contract->rate_basis === 'daily' ? '/day' : '')
            : '';
    }

    /** "1.000 day", "5.000 days", "0.500 day (AM)". */
    public function days(LeaveApplication $application): string
    {
        $days = (float) $application->days;
        $text = number_format($days, 3).' '.($days > 1 ? 'days' : 'day');

        return $application->is_half_day && $application->half_day_period ? "{$text} ({$application->half_day_period})" : $text;
    }

    /**
     * 6.B: each box, ticked or not, with the words written on its line.
     *
     * @return array<string, array{checked: bool, text: string}>
     */
    private function details(LeaveApplication $application): array
    {
        $boxes = [];
        foreach ([LeaveDetail::WithinPhilippines, LeaveDetail::Abroad, LeaveDetail::InHospital, LeaveDetail::OutPatient,
            LeaveDetail::SpecialIllness, LeaveDetail::MastersDegree, LeaveDetail::BarBoardReview] as $detail) {
            $checked = $application->detail === $detail;
            $boxes[$detail->value] = ['checked' => $checked, 'text' => $checked ? (string) $application->detail_text : ''];
        }

        // Monetization and terminal leave are filed separately (HR-6c).
        $boxes['monetization'] = ['checked' => false, 'text' => ''];
        $boxes['terminal'] = ['checked' => false, 'text' => ''];

        return $boxes;
    }

    /** The "Others" line: the leave's name (e.g. "Wellness Leave"), and any details given. */
    private function othersLine(LeaveApplication $application): string
    {
        $name = $application->leaveType->name;

        return $application->detail === LeaveDetail::Other && $application->detail_text ? "{$name}: {$application->detail_text}" : $name;
    }

    /**
     * 7.A, as CSC's system prints it: for VL and SL, what was earned and
     * enjoyed (leave taken, tardiness and undertime) up to the day HR
     * certified, the balance HR certified, less this application where it
     * draws on them (Forced Leave is charged to VL), and the balance after.
     *
     * @return array{as_of: string, rows: array<string, array{earned: string, enjoyed: string, balance: string, less: string, after: string}>}
     */
    private function credits(LeaveApplication $application, LeaveApplicationStep $hr): array
    {
        $charged = $application->leaveType->chargedTo?->code ?? $application->leaveType->code;
        $certified = $hr->certified_credits ?? [];
        $asOf = $this->local($hr->acted_at);
        $types = LeaveType::query()->whereIn('code', ['VL', 'SL'])->pluck('id', 'code');
        $ledger = $application->employee->leaveLedger()->whereDate('entry_date', '<=', $asOf->toDateString())
            ->whereIn('leave_type_id', $types->values())
            ->selectRaw('leave_type_id, SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as earned')
            ->groupBy('leave_type_id')->pluck('earned', 'leave_type_id');
        $rows = [];

        foreach (['VL', 'SL'] as $code) {
            $balance = (float) ($certified[$code] ?? 0);
            // Enjoyed is what the earned credits went to, so the rows always add up.
            $earned = max($balance, (float) ($ledger[$types[$code] ?? 0] ?? $balance));
            // As approved (SL beyond its credits is charged to VL), else what it would draw.
            $less = $application->charges !== null
                ? (float) ($application->charges[$code] ?? 0)
                : ($charged === $code ? (float) ($application->days_with_pay ?? $application->days) : 0.0);
            $rows[$code] = [
                'earned' => number_format($earned, 3),
                'enjoyed' => number_format($earned - $balance, 3),
                'balance' => number_format($balance, 3),
                'less' => number_format($less, 3),
                'after' => number_format($balance - $less, 3),
            ];
        }

        return ['as_of' => $asOf->format('m/d/Y'), 'rows' => $rows];
    }

    /**
     * @return ?array{name: string, position: string, acting_for: string, acted_on: string}
     */
    private function signer(?LeaveApplicationStep $step): ?array
    {
        if (! $step) {
            return null;
        }

        return [
            'name' => Str::upper((string) $step->actor?->name),
            'position' => Str::upper((string) $step->actor?->position),
            'acting_for' => Str::upper((string) $step->actingFor?->name),
            'acted_on' => $step->acted_at ? $this->local($step->acted_at)->format('m/d/Y') : '',
        ];
    }

    private function acted(?LeaveApplicationStep $step): ?LeaveApplicationStep
    {
        return $step && $step->action !== LeaveStepAction::Pending ? $step : null;
    }

    /** "Oct. 28, 2026" ("May 4, 2026": a month of three letters takes no period). */
    private function filedOn(?CarbonInterface $date): string
    {
        if (! $date) {
            return '';
        }
        $local = $this->local($date);

        return $local->format('M').(mb_strlen($local->format('F')) > 3 ? '.' : '').$local->format(' j, Y');
    }

    private function local(CarbonInterface $date): Carbon
    {
        return Carbon::instance($date)->timezone(config('app.timezone'));
    }
}
