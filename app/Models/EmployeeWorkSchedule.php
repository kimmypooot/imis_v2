<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DTR: the work schedule an employee follows from one date to another
 * (an open end means "still").
 */
class EmployeeWorkSchedule extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['valid_from' => 'date:Y-m-d', 'valid_to' => 'date:Y-m-d'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class, 'work_schedule_id');
    }

    /**
     * Assignments in force on a date.
     *
     * @param  Builder<EmployeeWorkSchedule>  $query
     */
    public function scopeCoveringDate(Builder $query, Carbon $date): void
    {
        $day = $date->toDateString();

        $query->where('valid_from', '<=', $day)
            ->where(fn (Builder $q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $day));
    }
}
