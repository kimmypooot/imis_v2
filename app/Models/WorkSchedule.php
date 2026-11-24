<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * DTR: a work schedule. Arriving after `latest_arrival` is late; time
 * before `start_time` is never counted; `required_minutes` is a full day.
 * The noon break, if any, is deducted only when worked through.
 */
class WorkSchedule extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'required_minutes' => 'integer',
            'break_minutes' => 'integer',
            'rest_weekdays' => 'array',
        ];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeWorkSchedule::class);
    }

    public function startsAt(Carbon $day): Carbon
    {
        return $this->atTime($day, $this->start_time);
    }

    public function latestArrivalAt(Carbon $day): Carbon
    {
        return $this->atTime($day, $this->latest_arrival);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public function breakWindow(Carbon $day): ?array
    {
        if (! $this->break_start || $this->break_minutes <= 0) {
            return null;
        }

        $start = $this->atTime($day, $this->break_start);

        return [$start, $start->copy()->addMinutes($this->break_minutes)];
    }

    public function isRestWeekday(Carbon $day): bool
    {
        return in_array($day->isoWeekday(), $this->rest_weekdays ?? [], true);
    }

    private function atTime(Carbon $day, string $time): Carbon
    {
        return $day->copy()->startOfDay()->setTimeFromTimeString(substr($time, 0, 5));
    }
}
