<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Whom the Recruitment Portal's Appointing Authority selected for a plantilla
 * item. IMIS can only appoint an existing employee at a step and status the
 * portal does not know, so this is a pending note for HR: the item stays
 * vacant here until HR records the appointment.
 */
class PlantillaSelection extends Model
{
    public const PENDING = 'pending';

    protected $fillable = ['recruitment_vacancy_id', 'applicant_name', 'applicant_email', 'selected_on', 'status', 'remarks'];

    protected function casts(): array
    {
        return ['selected_on' => 'date:Y-m-d'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(PlantillaItem::class, 'plantilla_item_id');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', self::PENDING);
    }
}
