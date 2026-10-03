<?php

namespace App\Models;

use App\Enums\LedgerEntryKind;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HR-6: one entry in someone's leave-credit ledger — the leave card's
 * lines. Append-only: written only by App\Support\HR\Leave\LeaveCredits,
 * never edited or deleted; a mistake is cancelled by a reversing entry.
 */
class LeaveLedgerEntry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date:Y-m-d',
            'kind' => LedgerEntryKind::class,
            'amount' => 'decimal:3',
            'period_year' => 'integer',
            'period_month' => 'integer',
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

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    protected function label(): Attribute
    {
        return Attribute::get(fn () => 'leave credit entry for '.$this->loadMissing('employee.user')->employee?->user?->name);
    }
}
