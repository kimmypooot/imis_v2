<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * HR-6b: an officer-in-charge who acts for the Head of Agency on leave
 * applications for a period (legacy lms_agency_designations).
 */
class LeaveDesignation extends Model
{
    protected $fillable = ['designee_id', 'acting_for_id', 'date_from', 'date_to', 'order_number', 'remarks'];

    protected function casts(): array
    {
        return ['date_from' => 'date:Y-m-d', 'date_to' => 'date:Y-m-d'];
    }

    public function designee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'designee_id');
    }

    public function actingFor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acting_for_id');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActiveOn(Builder $query, ?Carbon $date = null): void
    {
        $date ??= today();
        $query->whereDate('date_from', '<=', $date)->whereDate('date_to', '>=', $date);
    }

    protected function label(): Attribute
    {
        return Attribute::get(fn () => 'officer-in-charge designation of '.$this->loadMissing('designee')->designee?->name);
    }
}
