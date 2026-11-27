<?php

namespace App\Support\HR\Leave;

use App\Models\Holiday;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The holidays falling in a period: the ones entered for those dates plus
 * the repeating holidays placed in each year of the period. When a
 * proclamation moves a repeating holiday, HR enters that year's date with
 * the same name and it replaces the repeating one for that year. A
 * repeating February 29 only falls in leap years.
 */
class HolidayCalendar
{
    /**
     * @return Collection<int, Holiday> sorted by date; repeating ones carry the year's date
     */
    public function between(Carbon|string $from, Carbon|string $to): Collection
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay();

        if ($from->gt($to)) {
            return collect();
        }

        $dated = Holiday::query()->where('repeats_annually', false)
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->get();

        $repeating = Holiday::query()->where('repeats_annually', true)->get();

        if ($repeating->isNotEmpty()) {
            $enteredThatYear = Holiday::query()->where('repeats_annually', false)
                ->whereYear('date', '>=', $from->year)->whereYear('date', '<=', $to->year)
                ->whereIn('name', $repeating->pluck('name'))->get()
                ->map(fn (Holiday $holiday) => $holiday->date->year.'|'.$holiday->name)->flip();

            foreach (range($from->year, $to->year) as $year) {
                foreach ($repeating as $holiday) {
                    $date = Carbon::create($year, $holiday->date->month, $holiday->date->day);

                    if ($date->day !== $holiday->date->day || $date->lt($from) || $date->gt($to) || $enteredThatYear->has("{$year}|{$holiday->name}")) {
                        continue;
                    }

                    $occurrence = clone $holiday;
                    $occurrence->date = $date;
                    $dated->push($occurrence);
                }
            }
        }

        return $dated->sortBy(fn (Holiday $holiday) => $holiday->date->toDateString())->values();
    }
}
