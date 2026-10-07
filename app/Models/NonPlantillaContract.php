<?php

namespace App\Models;

use App\Enums\EmploymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A contract or appointment period of someone who holds no plantilla
 * item: casual, contractual, Job Order or Contract of Service. Renewals
 * are new rows. Written only by App\Support\HR\NonPlantilla\NonPlantilla.
 */
class NonPlantillaContract extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'employment_status', 'designation', 'organizational_unit_id', 'date_from', 'date_to',
        'rate', 'rate_basis', 'funding_source', 'reference', 'remarks',
    ];

    protected function casts(): array
    {
        return [
            'employment_status' => EmploymentStatus::class,
            'date_from' => 'date:Y-m-d',
            'date_to' => 'date:Y-m-d',
            'rate' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function organizationalUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class);
    }

    /** Running on the date: started, and not yet ended. */
    public function isCurrentOn(Carbon $date): bool
    {
        return $this->date_from->lte($date) && ($this->date_to === null || $this->date_to->gte($date));
    }
}
