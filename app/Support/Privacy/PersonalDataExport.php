<?php

namespace App\Support\Privacy;

use App\Models\BiometricPunch;
use App\Models\CommitteeMember;
use App\Models\DataCorrectionRequest;
use App\Models\DtrAdjustment;
use App\Models\DtrLeaveDeduction;
use App\Models\DtrSignature;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\FwaArrangement;
use App\Models\FwaReport;
use App\Models\LeaveApplication;
use App\Models\LeaveCardMonth;
use App\Models\LeaveMonetization;
use App\Models\LoginHistory;
use App\Models\PayrollEntry;
use App\Models\PayslipRequest;
use App\Models\SalaryNotice;
use App\Models\Saln;
use App\Models\User;
use App\Support\HR\Pds\PdsReader;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

/**
 * RA 10173, the right to access and to data portability: what IMIS holds
 * about one person's own account and HR record, as a ZIP with `data.json`
 * (machine-readable) and `data.html` (to read). The person ticks which
 * sections to include (see `sections()`). Built only for the signed-in
 * person, from their own rows; stored files (attachments, scans,
 * signature images) and internal keys are left out.
 */
class PersonalDataExport
{
    /**
     * Every section, in the order it is offered and written. `employee`
     * sections need an employee record.
     *
     * @var array<string, array{label: string, description: string, employee: bool}>
     */
    private const SECTIONS = [
        'account' => ['label' => 'Account and access', 'description' => 'Your name, username, email, office, IMIS roles and the systems you can open.', 'employee' => false],
        'committees' => ['label' => 'Committee memberships', 'description' => 'The committees you sit on and your position in each.', 'employee' => false],
        'sign_in' => ['label' => 'Sign-in history and devices', 'description' => 'When and from where you signed in, and the devices registered for fingerprint or face sign-in.', 'employee' => false],
        'feedback' => ['label' => 'Feedback you sent', 'description' => 'Your feedback, suggestions and recommendations, and their status.', 'employee' => false],
        'employment' => ['label' => 'Employment record', 'description' => 'Your employee record, appointments, contracts, movements between offices and notices of salary adjustment or step increment.', 'employee' => true],
        'pds' => ['label' => 'Personal Data Sheet', 'description' => 'Everything in your PDS, including the encrypted identity numbers and items 34–40.', 'employee' => true],
        'service_record' => ['label' => 'Service record', 'description' => 'Your service record entries.', 'employee' => true],
        'learning' => ['label' => 'Learning & Development', 'description' => 'The trainings you logged.', 'employee' => true],
        'documents' => ['label' => '201 File (list only)', 'description' => 'The documents in your 201 File, by title and date. The files themselves are not included.', 'employee' => true],
        'leave' => ['label' => 'Leave', 'description' => 'Your leave credit ledger, leave card, applications and monetization requests.', 'employee' => true],
        'attendance' => ['label' => 'Attendance (DTR)', 'description' => 'Your biometric punches, entered times, signed DTRs, tardiness deductions, work schedules and flexible work.', 'employee' => true],
        'pay' => ['label' => 'Pay, loans and payslips', 'description' => 'Your pay items, loans, payroll entries and payslip requests.', 'employee' => true],
        'saln' => ['label' => 'SALN', 'description' => 'Your Statements of Assets, Liabilities and Net Worth, with their entries.', 'employee' => true],
        'corrections' => ['label' => 'Record correction requests', 'description' => 'The corrections you asked for and their outcome.', 'employee' => true],
    ];

    public function __construct(private readonly PdsReader $pds) {}

    /**
     * The sections this person can choose from.
     *
     * @return list<array{key: string, label: string, description: string}>
     */
    public function sections(User $user): array
    {
        $hasEmployee = $user->employee()->exists();

        return collect(self::SECTIONS)
            ->filter(fn (array $section) => $hasEmployee || ! $section['employee'])
            ->map(fn (array $section, string $key) => ['key' => $key, 'label' => $section['label'], 'description' => $section['description']])
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public static function sectionKeys(): array
    {
        return array_keys(self::SECTIONS);
    }

    /**
     * @param  list<string>|null  $sections  the keys to include; null for all
     * @return array<string, mixed>
     */
    public function build(User $user, ?array $sections = null): array
    {
        $user->loadMissing('organizationalUnit', 'activeRoles', 'employee');
        $employee = $user->employee;
        $chosen = collect(self::SECTIONS)
            ->filter(fn (array $section, string $key) => ($sections === null || in_array($key, $sections, true)) && ($employee !== null || ! $section['employee']));

        $data = [
            'generated_at' => now()->toIso8601String(),
            'sections_included' => $chosen->pluck('label')->values()->all(),
        ];

        foreach ($chosen->keys() as $key) {
            $data[$key] = self::SECTIONS[$key]['employee'] ? $this->employeeSection($key, $employee) : $this->accountSection($key, $user);
        }

        // Enums and dates become plain values, so JSON and HTML agree.
        return json_decode(json_encode($data, JSON_PARTIAL_OUTPUT_ON_ERROR), true);
    }

    /**
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    private function accountSection(string $key, User $user): array
    {
        return match ($key) {
            'account' => [
                ...$user->only(['name', 'username', 'email', 'position', 'status']),
                'office' => $user->organizationalUnit?->name,
                'roles' => $user->activeRoles->pluck('name')->all(),
                'system_access' => $user->applicationAccess()->with(['application:id,name', 'role:id,name'])->get()
                    ->map(fn ($access) => ['system' => $access->application?->name, 'role' => $access->role?->name])->all(),
            ],
            'committees' => CommitteeMember::query()->where('user_id', $user->id)->with('committee:id,name')->get()
                ->map(fn (CommitteeMember $member) => ['committee' => $member->committee?->name, ...$member->only(['position', 'effective_from', 'effective_until', 'status'])])->all(),
            'sign_in' => [
                'sign_ins' => $this->rows(LoginHistory::query()->where('user_id', $user->id)->latest('logged_in_at'), ['id', 'user_id', 'session_id']),
                'fingerprint_or_face_devices' => DB::table('passkeys')->where('user_id', $user->id)->get(['name', 'last_used_at', 'created_at'])
                    ->map(fn ($passkey) => (array) $passkey)->all(),
            ],
            'feedback' => $this->rows($user->feedback()->latest(), ['id', 'user_id', 'handled_by', 'seen_at']),
        };
    }

    /**
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    private function employeeSection(string $key, Employee $employee): array
    {
        $own = fn (string $model) => $model::query()->where('employee_id', $employee->id);

        return match ($key) {
            'employment' => [
                'record' => $employee->attributesToArray(),
                'appointments' => $this->rows($employee->plantillaAppointments(), ['employee_id']),
                'contracts' => $this->rows($employee->nonPlantillaContracts(), ['employee_id']),
                'movements_between_offices' => $this->rows($employee->assignments(), ['employee_id']),
                'salary_notices' => $this->rows($own(SalaryNotice::class), ['employee_id', 'employee_document_id']),
            ],
            'pds' => $this->pds->read($employee),
            'service_record' => $this->rows($employee->serviceRecordEntries(), ['employee_id']),
            'learning' => $this->rows($employee->trainings(), ['employee_id']),
            'documents' => $this->rows($own(EmployeeDocument::class)->whereNull('archived_at'), ['employee_id', 'file_path', 'mime_type', 'size', 'sha256', 'archived_at', 'archived_by', 'archive_reason']),
            'leave' => [
                'credit_ledger' => $this->rows($employee->leaveLedger(), ['employee_id']),
                'leave_card' => $this->rows($own(LeaveCardMonth::class), ['employee_id', 'imported_values']),
                'applications' => $this->rows($own(LeaveApplication::class), ['employee_id', 'attachment_path', 'attachment_name', 'attachment_mime', 'attachment_size', 'legacy_id']),
                'monetization' => $this->rows($own(LeaveMonetization::class), ['employee_id', 'legacy_id']),
            ],
            'attendance' => [
                'biometric_punches' => $employee->biometric_id
                    ? $this->rows(BiometricPunch::query()->where('biometric_id', $employee->biometric_id)->whereNull('voided_at')->orderBy('punched_at'), ['biometric_import_id', 'voided_at', 'voided_by', 'void_reason'])
                    : [],
                'entered_times' => $this->rows($own(DtrAdjustment::class), ['employee_id']),
                'signed_dtrs' => $this->rows($own(DtrSignature::class), ['employee_id', 'signature_path', 'content_hash']),
                'tardiness_deductions' => $this->rows($own(DtrLeaveDeduction::class), ['employee_id', 'leave_ledger_entry_id']),
                'work_schedules' => $this->rows($employee->workSchedules(), ['employee_id']),
                'flexible_work' => $this->rows($own(FwaArrangement::class), ['employee_id', 'legacy_id']),
                'flexible_work_reports' => $this->rows($own(FwaReport::class), ['employee_id', 'legacy_id']),
            ],
            'pay' => [
                'pay_items' => $this->rows($employee->payItems(), ['employee_id']),
                'loans' => $this->rows($employee->loans(), ['employee_id']),
                'payroll' => $this->rows($own(PayrollEntry::class)->whereNull('cancelled_at'), ['employee_id', 'cancelled_at', 'cancelled_by', 'cancel_reason']),
                'payslip_requests' => $this->rows($own(PayslipRequest::class), ['employee_id']),
            ],
            'saln' => $this->rows($own(Saln::class), ['employee_id', 'employee_document_id']),
            'corrections' => $this->rows($own(DataCorrectionRequest::class), ['employee_id']),
        };
    }

    /**
     * Each row's attributes (casts applied, so encrypted values are read),
     * without the given columns.
     *
     * @param  Builder<Model>|Relation<Model, Model, mixed>  $query
     * @param  list<string>  $except
     * @return list<array<string, mixed>>
     */
    private function rows(Builder|Relation $query, array $except = []): array
    {
        return $query->get()->map(fn (Model $row) => collect($row->attributesToArray())->except($except)->all())->values()->all();
    }

    /**
     * Writes the ZIP to a temporary file and returns its path; the caller
     * deletes it once sent.
     *
     * @param  list<string>|null  $sections
     */
    public function makeZip(User $user, ?array $sections = null): string
    {
        $data = $this->build($user, $sections);
        $path = tempnam(sys_get_temp_dir(), 'imis-data-');
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The export file could not be created.');
        }

        $zip->addFromString('data.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
        $zip->addFromString('data.html', $this->toHtml($data));
        $zip->close();

        return $path;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function toHtml(array $data): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>My IMIS data</title>'
            .'<style>body{font:14px/1.5 system-ui,sans-serif;margin:2rem auto;max-width:60rem;padding:0 1rem;color:#1f2937}'
            .'h1,h2,h3{color:#0b3a75}table{border-collapse:collapse;margin:.5rem 0 1rem}td,th{border:1px solid #d1d5db;padding:.25rem .5rem;text-align:left;vertical-align:top}'
            .'th{background:#f3f4f6;font-weight:600}</style></head><body><h1>My IMIS data</h1>'
            .'<p>Everything IMIS holds about you, as of '.e($data['generated_at']).'. Keep this file safe: it contains personal data.</p>'
            .$this->render($data, 2).'</body></html>';
    }

    private function render(mixed $value, int $level): string
    {
        if (! is_array($value)) {
            return e($this->scalar($value));
        }

        if ($value === []) {
            return '<em>None</em>';
        }

        if (array_is_list($value)) {
            if (collect($value)->every(fn ($row) => is_array($row) && ! array_is_list($row))) {
                $columns = collect($value)->flatMap(fn ($row) => array_keys($row))->unique()->values();
                $html = '<table><tr>'.$columns->map(fn ($column) => '<th>'.e($this->label((string) $column)).'</th>')->implode('').'</tr>';

                foreach ($value as $row) {
                    $html .= '<tr>'.$columns->map(fn ($column) => '<td>'.$this->render($row[$column] ?? null, $level + 1).'</td>')->implode('').'</tr>';
                }

                return $html.'</table>';
            }

            return '<ul>'.collect($value)->map(fn ($item) => '<li>'.$this->render($item, $level + 1).'</li>')->implode('').'</ul>';
        }

        $html = '';
        $scalars = '';

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $tag = 'h'.min($level, 4);
                $html .= "<{$tag}>".e($this->label((string) $key))."</{$tag}>".$this->render($item, $level + 1);
            } else {
                $scalars .= '<tr><th>'.e($this->label((string) $key)).'</th><td>'.e($this->scalar($item)).'</td></tr>';
            }
        }

        return ($scalars !== '' ? '<table>'.$scalars.'</table>' : '').$html;
    }

    private function scalar(mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' => '—',
            is_bool($value) => $value ? 'Yes' : 'No',
            default => (string) $value,
        };
    }

    private function label(string $key): string
    {
        return ucfirst(str_replace('_', ' ', $key));
    }
}
