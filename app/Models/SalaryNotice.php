<?php

namespace App\Models;

use App\Enums\SalaryNoticeType;
use Database\Factories\SalaryNoticeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Notice of Salary Adjustment or of Step Increment, as printed: every
 * figure and name is a snapshot taken when it was generated. Filed once
 * the signed copy is in the employee's 201 File. Written only by
 * App\Support\HR\SalaryNotices\SalaryNotices.
 */
class SalaryNotice extends Model
{
    /** @use HasFactory<SalaryNoticeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => SalaryNoticeType::class,
            'notice_date' => 'date:Y-m-d',
            'effective_date' => 'date:Y-m-d',
            'salary_grade' => 'integer',
            'from_step' => 'integer',
            'to_step' => 'integer',
            'from_salary' => 'decimal:2',
            'to_salary' => 'decimal:2',
            'filed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(PlantillaAppointment::class, 'plantilla_appointment_id')->withTrashed();
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(SalarySchedule::class, 'salary_schedule_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'employee_document_id');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function isFiled(): bool
    {
        return $this->filed_at !== null;
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeUnfiled(Builder $query): void
    {
        $query->whereNull('filed_at');
    }

    /** The increase: the adjusted salary less the one before. */
    protected function difference(): Attribute
    {
        return Attribute::get(fn () => round((float) $this->to_salary - (float) $this->from_salary, 2));
    }

    protected function label(): Attribute
    {
        return Attribute::get(fn () => "{$this->type->code()} of {$this->addressee} effective {$this->effective_date->toDateString()}");
    }
}
