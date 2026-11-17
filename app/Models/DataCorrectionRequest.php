<?php

namespace App\Models;

use App\Enums\DataCorrectionStatus;
use Database\Factories\DataCorrectionRequestFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staff member's request to correct one field of their own employee
 * record. HR approves (the change is applied) or rejects it. Written only
 * by App\Support\HR\DataCorrections.
 */
class DataCorrectionRequest extends Model
{
    /** @use HasFactory<DataCorrectionRequestFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => DataCorrectionStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    protected function label(): Attribute
    {
        return Attribute::get(fn () => 'correction request '.($this->reference_number ?? "#{$this->id}")
            .' of '.$this->loadMissing('employee.user')->employee?->user?->name);
    }
}
