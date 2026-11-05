<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HR-6: one month of an imported leave card (VL and SL: earned, used,
 * month-end balance, and the card's own notes). History for display only,
 * never read for balances — the ledger is the record of what's left.
 */
class LeaveCardMonth extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'vl_earned' => 'float',
            'vl_used' => 'float',
            'vl_balance' => 'float',
            'sl_earned' => 'float',
            'sl_used' => 'float',
            'sl_balance' => 'float',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
