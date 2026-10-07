<?php

namespace App\Models;

use App\Enums\PayslipRequestStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HR-8: an employee's request for one month's payslip. The head of HRD
 * (or whoever is designated head of HRD) approves or rejects it; once
 * approved, the employee can view and print that payslip. Written only by
 * App\Support\HR\Payroll\PayslipRequests.
 */
class PayslipRequest extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => PayslipRequestStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(PayrollEntry::class, 'payroll_entry_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function actingFor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acting_for_id');
    }

    public function isApproved(): bool
    {
        return $this->status === PayslipRequestStatus::Approved;
    }

    protected function label(): Attribute
    {
        return Attribute::get(fn () => 'payslip request '.($this->reference_number ?? "#{$this->id}")
            .' of '.$this->loadMissing('employee.user')->employee?->user?->name);
    }
}
