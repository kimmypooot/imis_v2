<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * HR-8: one person's pay in a monthly payroll — a snapshot of who they
 * were then (name, position, item, grade/step, office), their earnings
 * and deductions (lines), and the totals, with net pay split between the
 * two releases. What a payslip prints.
 */
class PayrollEntry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'salary_grade' => 'integer',
            'step' => 'integer',
            'basic_salary' => 'decimal:2',
            'gross_pay' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'net_pay' => 'decimal:2',
            'first_half' => 'decimal:2',
            'second_half' => 'decimal:2',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollLine::class)->orderBy('sort_order')->orderBy('id');
    }

    protected function label(): Attribute
    {
        return Attribute::get(fn () => "pay of {$this->name} for ".$this->loadMissing('run')->run?->period);
    }
}
