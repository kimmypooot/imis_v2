<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Enums\HolidayType;
use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Models\LeaveType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-6a: official holidays, entered by HR from each year's proclamations.
 * Regular and special non-working days are skipped when counting leave.
 */
class HolidayController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', LeaveType::class);

        $year = $request->integer('year', (int) now()->year);
        $year = $year >= 1990 && $year <= 2100 ? $year : (int) now()->year;

        $years = Holiday::query()->pluck('date')->map(fn ($date) => (int) substr((string) $date, 0, 4))
            ->push((int) now()->year)->push((int) now()->year + 1)->push($year)->unique()->sortDesc()->values();

        return Inertia::render('Admin/HR/Leave/Holidays', [
            'year' => $year,
            'years' => $years,
            'holidays' => Holiday::query()->whereYear('date', $year)->orderBy('date')->get()->map(fn (Holiday $holiday) => [
                'id' => $holiday->id,
                'date' => $holiday->date->toDateString(),
                'name' => $holiday->name,
                'type' => $holiday->type->value,
                'type_label' => $holiday->type->label(),
                'day_off' => $holiday->type->isDayOff(),
                'proclamation' => $holiday->proclamation,
            ]),
            'typeOptions' => HolidayType::options(),
            'canManage' => $request->user()->can('manage', LeaveType::class),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manage', LeaveType::class);

        $holiday = Holiday::query()->create($this->validated($request));
        $this->audit()->record(AuditEvent::HolidaySaved, "Added the {$holiday->label}", $holiday);

        return back()->with('status', "{$holiday->name} was added.");
    }

    public function update(Request $request, Holiday $holiday): RedirectResponse
    {
        $this->authorize('manage', LeaveType::class);

        $holiday->fill($this->validated($request, $holiday))->save();
        $this->audit()->recordChanges(AuditEvent::HolidaySaved, "Updated the {$holiday->label}", $holiday);

        return back()->with('status', "{$holiday->name} was saved.");
    }

    public function destroy(Holiday $holiday): RedirectResponse
    {
        $this->authorize('manage', LeaveType::class);

        $holiday->delete();
        $this->audit()->record(AuditEvent::HolidayDeleted, "Removed the {$holiday->label}", null, ['date' => $holiday->date->toDateString(), 'name' => $holiday->name]);

        return back()->with('status', "{$holiday->name} was removed.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Holiday $holiday = null): array
    {
        return $request->validate([
            'date' => ['required', 'date', 'after:1990-01-01'],
            'name' => ['required', 'string', 'max:255', Rule::unique('holidays', 'name')->where('date', $request->input('date'))->ignore($holiday)],
            'type' => ['required', Rule::enum(HolidayType::class)],
            'proclamation' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
