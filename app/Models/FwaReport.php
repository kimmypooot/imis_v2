<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HR-6c: a weekly field activity report (FAR) under a flexible work
 * arrangement — the week's targets and accomplishments, acknowledged by
 * HR. Written only by App\Support\HR\Leave\FlexibleWork.
 */
class FwaReport extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'week_start' => 'date:Y-m-d',
            'week_end' => 'date:Y-m-d',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function arrangement(): BelongsTo
    {
        return $this->belongsTo(FwaArrangement::class, 'fwa_arrangement_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    protected function label(): Attribute
    {
        return Attribute::get(fn () => 'field activity report ('.$this->week_start?->toDateString().') of '.$this->loadMissing('employee.user')->employee?->user?->name);
    }
}
