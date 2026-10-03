<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HR-4: one cell of a salary schedule — the monthly salary for a grade
 * and step. Written only by App\Support\HR\Plantilla\SalarySchedules.
 */
class SalaryRate extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['salary_grade' => 'integer', 'step' => 'integer', 'monthly_salary' => 'decimal:2'];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(SalarySchedule::class, 'salary_schedule_id');
    }
}
