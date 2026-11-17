<?php

namespace App\Models;

use Database\Factories\ApprovalDelegationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An approver's out-of-office hand-over: for the dates given, the delegate
 * may act on the leave applications waiting for the delegator as their
 * supervisor. Supervisor steps only; HR and Head steps follow permissions
 * and officer-in-charge designations instead.
 */
class ApprovalDelegation extends Model
{
    /** @use HasFactory<ApprovalDelegationFactory> */
    use HasFactory;

    protected $fillable = ['delegator_id', 'delegate_id', 'date_from', 'date_to', 'reason'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['date_from' => 'date:Y-m-d', 'date_to' => 'date:Y-m-d', 'ended_at' => 'datetime'];
    }

    public function delegator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegator_id');
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_id');
    }

    /**
     * In force on the date (default today) and not ended early.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActiveOn(Builder $query, ?Carbon $date = null): void
    {
        $date ??= today();
        $query->whereNull('ended_at')->whereDate('date_from', '<=', $date)->whereDate('date_to', '>=', $date);
    }

    /**
     * Not yet over (running now or starting later) and not ended early.
     *
     * @param  Builder<self>  $query
     */
    public function scopeCurrentOrUpcoming(Builder $query): void
    {
        $query->whereNull('ended_at')->whereDate('date_to', '>=', today());
    }
}
