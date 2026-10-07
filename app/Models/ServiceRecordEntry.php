<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * HR-5: a Service Record line HR entered by hand — service in another
 * government agency, which no plantilla item here holds. Written only by
 * App\Support\HR\ServiceRecords.
 */
class ServiceRecordEntry extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'date_from', 'date_to', 'designation', 'status', 'salary_grade', 'step', 'annual_salary',
        'agency', 'station', 'branch', 'leave_without_pay', 'separation_date', 'separation_cause', 'remarks',
    ];

    protected function casts(): array
    {
        return [
            'date_from' => 'date:Y-m-d',
            'date_to' => 'date:Y-m-d',
            'salary_grade' => 'integer',
            'step' => 'integer',
            'annual_salary' => 'decimal:2',
            'leave_without_pay' => 'decimal:3',
            'separation_date' => 'date:Y-m-d',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** How audit entries name it. */
    protected function label(): Attribute
    {
        return Attribute::get(fn () => "service line {$this->designation}, {$this->agency} ({$this->date_from?->toDateString()} to {$this->date_to?->toDateString()})");
    }
}
