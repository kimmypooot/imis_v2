<?php

namespace App\Support\HR\Payroll;

use App\Models\OrganizationalUnit;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\PlantillaItem;
use App\Models\User;
use App\Support\HR\CertifyingOfficer;
use App\Support\HR\OfficeHeads;
use App\Support\Settings\Settings;
use Illuminate\Support\Collection;

/**
 * The General Payroll laid out as the accounting office's "Final Payroll"
 * sheet: each person takes three rows (salary; PERA with the first line of
 * deductions; the second line of loans), every deduction sits in its fixed
 * column, and each printed page carries its own total and the four
 * certifications. A last page recaps the totals by office.
 *
 * The component codes below are what the workbook's columns stand for (see
 * PayrollWorkbook). A deduction with no column lands in "Other deductions"
 * so the net pay always reconciles.
 */
class GeneralPayrollSheet
{
    /** People on one printed page. */
    public const PER_PAGE = 6;

    /** @var array<string, array<int, string>> workbook column => component codes, first line (the PERA row) */
    public const FIRST_LINE = [
        'I' => ['GSIS-CONSO'], 'J' => ['GSIS-POL'], 'K' => ['GSIS-SOLAR'], 'L' => ['GSIS-MPL-LITE'], 'M' => ['GSIS-MPL'],
        'P' => ['GSIS-HELP'], 'S' => ['HDMF-HOUSING', 'HDMF-HOUSE'], 'T' => ['HDMF-MP2'], 'U' => ['WTAX'], 'V' => ['GSIS-EAL'],
        'W' => ['WF', 'WELFARE'], 'X' => ['CSC-PAL'], 'Y' => ['PINTIG'],
    ];

    /** @var array<string, array<int, string>> workbook column => component codes, second line (the loans row) */
    public const SECOND_LINE = [
        'I' => ['GSIS-REL'], 'J' => ['GSIS-EML'], 'K' => ['GSIS-CPL'], 'L' => ['GSIS-OPT-LIFE'], 'M' => ['WB-GSIS-M2'],
        'P' => ['CEAP'], 'S' => ['HDMF-MPL'], 'T' => ['SAFE'], 'V' => ['ECA'], 'W' => ['WF-MPL', 'WELFARE-MPL'], 'X' => ['GSIS-GPL', 'WELFARE-GPL'],
    ];

    private const MANDATORY = ['GSIS-PS' => 'gsis_ps', 'PHIC' => 'phic_ps', 'HDMF' => 'hdmf_ps'];

    private const SUMMED = ['basic', 'pera', 'gross', 'net', 'gsis_ps', 'gsis_gov', 'ecc', 'phic_ps', 'phic_gov', 'hdmf_ps', 'hdmf_er', 'other'];

    public function __construct(private readonly Remittances $remittances, private readonly CertifyingOfficer $officers, private readonly OfficeHeads $heads) {}

    /**
     * @return array<string, mixed>
     */
    public function build(PayrollRun $run): array
    {
        $entries = $run->entries()->whereNull('cancelled_at')->with(['lines.component', 'employee.user'])->orderBy('office')->orderBy('name')->get();
        $rows = $entries->map(fn (PayrollEntry $entry) => $this->row($entry))->values();

        $number = 0;
        $pages = [];
        $recap = [];
        foreach ($rows->groupBy(fn (array $row) => $row['office']) as $office => $members) {
            $recap[] = ['office' => (string) $office, 'totals' => $this->total($members)];
            foreach ($members->chunk(self::PER_PAGE) as $chunk) {
                $pages[] = [
                    'office' => (string) $office,
                    'rows' => $chunk->map(fn (array $row) => [...$row, 'no' => ++$number])->values()->all(),
                    'totals' => $this->total($chunk),
                ];
            }
        }

        $month = $run->month();

        return [
            'pages' => $pages,
            'recap' => $recap,
            'totals' => $this->total($rows),
            'has_other' => $rows->contains(fn (array $row) => abs($row['other']) > 0.004),
            'period' => $month->format('F').' 1-'.$month->daysInMonth.', '.$month->year,
            'payees' => $rows->count(),
            'signatories' => [
                'paying_officer' => $this->headOf('MSD'),
                'certifier' => $this->headOf('HRD'),
                'approver' => $this->officers->holderOf('Director IV'),
                'accountant' => Settings::get('payroll_accountant') ?: $this->accountant(),
            ],
        ];
    }

    /** Whoever holds the Accountant III item on the Plantilla now, when exactly one person does. */
    private function accountant(): ?string
    {
        $holders = PlantillaItem::query()->active()
            ->whereRaw('LOWER(TRIM(position_title)) = ?', ['accountant iii'])
            ->whereHas('incumbency')
            ->with('incumbency.employee.user')
            ->get();

        return $holders->count() === 1 ? $holders->first()->incumbency->employee?->user?->name : null;
    }

    /** Who heads the office with this code now (the Chief of the division), by name. */
    private function headOf(string $code): ?string
    {
        $id = OrganizationalUnit::query()->where('code', $code)->value('id');

        return $id ? $this->heads->of($id)?->name : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(PayrollEntry $entry): array
    {
        $first = [];
        $second = [];
        $row = ['gsis_ps' => 0.0, 'phic_ps' => 0.0, 'hdmf_ps' => 0.0, 'other' => 0.0];
        foreach ($entry->lines->where('kind', 'deduction') as $line) {
            $amount = (float) $line->amount;
            $code = (string) $line->component?->code;
            if (isset(self::MANDATORY[$code])) {
                $row[self::MANDATORY[$code]] = round($row[self::MANDATORY[$code]] + $amount, 2);

                continue;
            }
            $column = $this->column(self::FIRST_LINE, $code);
            if ($column !== null) {
                $first[$column] = round(($first[$column] ?? 0.0) + $amount, 2);

                continue;
            }
            $column = $this->column(self::SECOND_LINE, $code);
            if ($column !== null) {
                $second[$column] = round(($second[$column] ?? 0.0) + $amount, 2);

                continue;
            }
            $row['other'] = round($row['other'] + $amount, 2);
        }

        $employer = $this->remittances->employerShares($entry, $row['gsis_ps'], $row['phic_ps'], $row['hdmf_ps']);
        $basic = (float) $entry->basic_salary;
        $gross = (float) $entry->gross_pay;
        $user = $entry->employee?->user;

        return [
            ...$row,
            'office' => $entry->office ?: 'No office',
            'surname' => $this->surname($entry, $user),
            'given' => $this->given($entry, $user),
            'position' => $entry->position,
            'basic' => $basic,
            'pera' => round($gross - $basic, 2),
            'gross' => $gross,
            'gsis_gov' => $employer['gsis'],
            'ecc' => $employer['ecc'],
            'phic_gov' => $employer['phic'],
            'hdmf_er' => $employer['hdmf'],
            'first' => $first,
            'second' => $second,
            'net' => (float) $entry->net_pay,
        ];
    }

    /**
     * @param  array<string, array<int, string>>  $map
     */
    private function column(array $map, string $code): ?string
    {
        foreach ($map as $column => $codes) {
            if (in_array($code, $codes, true)) {
                return $column;
            }
        }

        return null;
    }

    /** "TALDO," — the surname in capitals, as the workbook has it. */
    private function surname(PayrollEntry $entry, ?User $user): string
    {
        if ($user?->last_name) {
            return mb_strtoupper(trim($user->last_name.' '.($user->name_extension ?? ''))).',';
        }
        $parts = preg_split('/\s+/', trim($entry->name));

        return mb_strtoupper(array_pop($parts) ?? '').',';
    }

    /** "MARILYN E." — the given name and middle initial. */
    private function given(PayrollEntry $entry, ?User $user): string
    {
        if ($user?->last_name) {
            $initial = $user->middle_name ? ' '.mb_strtoupper(mb_substr(trim($user->middle_name), 0, 1)).'.' : '';

            return mb_strtoupper(trim($user->first_name.$initial));
        }
        $parts = preg_split('/\s+/', trim($entry->name));
        array_pop($parts);

        return mb_strtoupper(implode(' ', $parts));
    }

    /**
     * Totals for a set of rows; the loan columns add both lines together.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function total(Collection $rows): array
    {
        $total = [];
        foreach (self::SUMMED as $field) {
            $total[$field] = round($rows->sum($field), 2);
        }
        $total['columns'] = [];
        foreach ($rows as $row) {
            foreach ([$row['first'], $row['second']] as $line) {
                foreach ($line as $column => $amount) {
                    $total['columns'][$column] = round(($total['columns'][$column] ?? 0.0) + $amount, 2);
                }
            }
        }

        return $total;
    }
}
