<?php

namespace App\Support\Executive\Sources;

use App\Enums\LdApprovalStatus;
use App\Enums\PermissionName;
use App\Models\Pds\PdsLearningDevelopment;

/**
 * Learning & Development (HR-3): approved trainings this year, their
 * hours, and the share of staff in service with at least one. Starter
 * figures, counted like the L&D dashboard (approved entries only).
 */
class LearningSource implements MetricSource
{
    public function key(): string
    {
        return 'ld';
    }

    public function label(): string
    {
        return 'Learning & Development';
    }

    public function kind(): string
    {
        return 'module';
    }

    public function collect(): array
    {
        $year = today()->year;
        $staff = Staff::inService();
        $thisYear = PdsLearningDevelopment::query()
            ->where('approval_status', LdApprovalStatus::Approved)
            ->whereYear('date_from', $year)
            ->whereIn('employee_id', $staff->modelKeys());
        $trained = (clone $thisYear)->distinct()->pluck('employee_id');
        $link = ['href' => '/admin/hr/learning-development/dashboard', 'permission' => PermissionName::LdView->value];

        return [
            ['key' => 'ld.trainings_year', 'label' => 'Trainings attended this year', 'value' => (clone $thisYear)->count(), 'unit' => 'count', 'period' => (string) $year, ...$link],
            ['key' => 'ld.hours_year', 'label' => 'Training hours this year', 'value' => (float) (clone $thisYear)->sum('hours'), 'unit' => 'hours', 'period' => (string) $year, ...$link],
            [
                'key' => 'ld.staff_trained',
                'label' => 'Employees with at least one training this year',
                'value' => $staff->isEmpty() ? null : round($trained->count() / $staff->count() * 100, 1),
                'unit' => 'percent',
                'period' => (string) $year,
                'note' => "{$trained->count()} of {$staff->count()} employees",
                'breakdown' => Staff::byOffice($staff->whereIn('id', $trained), Staff::office(...)),
                ...$link,
            ],
        ];
    }
}
