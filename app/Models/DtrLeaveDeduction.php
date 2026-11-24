<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DTR: a month's tardiness and undertime charged to an employee's leave
 * credits. `days` is what came off the credits (the leave ledger entry);
 * `days_without_pay` is what the credits could not cover. A charge is active
 * until it is reversed here or its ledger entry is reversed on the leave card.
 */
class DtrLeaveDeduction extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'period_month' => 'integer',
            'minutes' => 'integer',
            'days' => 'decimal:3',
            'days_without_pay' => 'decimal:3',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(LeaveLedgerEntry::class, 'leave_ledger_entry_id');
    }

    /** Not reversed: the month still counts as charged. */
    public function isActive(): bool
    {
        if ($this->reversed_at !== null) {
            return false;
        }

        return $this->leave_ledger_entry_id === null
            || ! LeaveLedgerEntry::query()->where('reverses_id', $this->leave_ledger_entry_id)->exists();
    }
}
