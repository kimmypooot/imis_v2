<?php

namespace App\Support\Privacy;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\LeaveApplication;
use App\Models\PayrollEntry;
use App\Models\Pds\PdsLearningDevelopment;
use App\Models\Pds\PdsSubmission;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Admin-2, "Who accessed my record" (RA 10173, the right to be informed):
 * every time someone else viewed or printed this employee's HR records,
 * from the personal-data access entries in the audit log.
 *
 * An entry points at the record that was opened — the employee record,
 * a PDS submission, a 201-file document, a leave application, a training,
 * a payslip — so each is traced back to the employee. Reports covering
 * everyone (the L&D training report) name no one and aren't listed; your
 * own visits aren't either. Entries are kept as long as System Settings →
 * Log Retention → personal-data access entries says.
 */
class RecordAccessLog
{
    public const PER_PAGE = 25;

    public function __construct(private readonly Employee $employee) {}

    public function paginate(?int $year = null): LengthAwarePaginator
    {
        return $this->query()
            ->when($year, fn (Builder $query) => $query->whereYear('created_at', $year))
            ->with('actor:id,name,position')
            ->latest('created_at')->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (AuditLog $entry) => [
                'id' => $entry->id,
                'at' => $entry->created_at->toIso8601String(),
                'who' => $entry->actor?->name ?? $entry->actor_label
                    ?? (in_array($entry->event, [AuditEvent::RecruitmentPdsRead, AuditEvent::RecruitmentDocumentRead], true) ? 'Recruitment Portal' : 'Unknown account'),
                'position' => $entry->actor?->position,
                'what' => $this->what($entry),
                'detail' => $entry->description,
            ]);
    }

    /**
     * Years that have entries, newest first, for the filter.
     *
     * @return array<int, int>
     */
    public function years(): array
    {
        return $this->query()->pluck('created_at')
            ->map(fn ($at) => $at->year)->unique()->sortDesc()->values()->all();
    }

    public function query(): Builder
    {
        $targets = $this->targets();

        return AuditLog::query()
            ->whereIn('event', $this->events())
            ->where(fn (Builder $query) => $query->whereNull('actor_id')->orWhere('actor_id', '!=', $this->employee->user_id))
            ->where(function (Builder $query) use ($targets) {
                foreach ($targets as $type => $ids) {
                    $query->orWhere(fn (Builder $query) => $query->where('target_type', $type)->whereIn('target_id', $ids));
                }
            });
    }

    /**
     * @return array<int, string>
     */
    private function events(): array
    {
        return collect(AuditEvent::personalDataAccess())
            ->reject(fn (AuditEvent $event) => $event === AuditEvent::LdReportViewed)
            ->map(fn (AuditEvent $event) => $event->value)
            ->values()->all();
    }

    /**
     * The records that belong to this employee, by audit target type.
     *
     * @return array<string, array<int, int>>
     */
    private function targets(): array
    {
        $owned = fn (string $model) => $model::query()->where('employee_id', $this->employee->id)->pluck('id')->all();

        return array_filter([
            'employee' => [$this->employee->id],
            'pds_submission' => $owned(PdsSubmission::class),
            'employee_document' => $owned(EmployeeDocument::class),
            'leave_application' => $owned(LeaveApplication::class),
            'ld_record' => $owned(PdsLearningDevelopment::class),
            'payroll_entry' => $owned(PayrollEntry::class),
        ]);
    }

    /** What the entry opened, in words. */
    private function what(AuditLog $entry): string
    {
        return match ($entry->event) {
            AuditEvent::EmployeeViewed => ($entry->metadata['section'] ?? null) === 'pay_profile' ? 'Pay profile' : 'Employee record',
            AuditEvent::ServiceRecordViewed => 'Service Record',
            AuditEvent::ServiceRecordPrinted => 'Service Record (printed)',
            AuditEvent::LeaveCardViewed => 'Leave card',
            AuditEvent::LeaveApplicationViewed => 'Leave application',
            AuditEvent::LeaveAttachmentViewed => 'Leave application attachment',
            AuditEvent::PayslipViewed => 'Payslip',
            AuditEvent::PdsViewed => $entry->target_type === 'pds_submission' ? 'Personal Data Sheet (signed submission)' : 'Personal Data Sheet',
            AuditEvent::PdsPrinted => 'Personal Data Sheet (printed)',
            AuditEvent::EmployeeDocumentViewed => '201 file document',
            AuditEvent::LdCertificateViewed => 'Training certificate',
            AuditEvent::LdLogViewed => 'Learning & Development log',
            AuditEvent::RecruitmentPdsRead => 'Personal Data Sheet (read by the Recruitment Portal)',
            AuditEvent::RecruitmentDocumentRead => '201 file document (read by the Recruitment Portal)',
            default => $entry->event->label(),
        };
    }
}
