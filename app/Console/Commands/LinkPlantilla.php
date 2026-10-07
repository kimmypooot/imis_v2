<?php

namespace App\Console\Commands;

use App\Enums\AuditEvent;
use App\Enums\EmploymentStatus;
use App\Models\Employee;
use App\Support\Audit\AuditLogger;
use App\Support\HR\Plantilla\Plantilla;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Links staff to the plantilla before HR has the item details: everyone
 * in service with an active account and a position title, who holds no
 * item yet, gets a new item of that title in their account's office and
 * an incomplete link to it (Plantilla::linkFromAccount). Nothing is
 * guessed: item number, step, nature and start date stay blank for HR.
 *
 * Shows what it would do unless run with --apply. Re-running is safe:
 * anyone who holds an item is skipped.
 */
class LinkPlantilla extends Command
{
    protected $signature = 'imis:link-plantilla
        {--apply : Save the links (without it, only shows what would happen)}';

    protected $description = 'Link staff without a plantilla item to new, incomplete items from their account position and office';

    public function handle(Plantilla $plantilla, AuditLogger $audit): int
    {
        $apply = (bool) $this->option('apply');

        $employees = Employee::query()->with(['user.organizationalUnit', 'plantillaAppointments' => fn ($query) => $query->whereNull('date_to')])->get()
            ->sortBy(fn (Employee $employee) => $employee->user->name, SORT_NATURAL | SORT_FLAG_CASE);

        $skipped = ['separated' => [], 'inactive account' => [], 'non-plantilla (casual, contractual, JO, COS)' => [], 'no position title' => [], 'already holds an item' => []];
        $toLink = collect();

        foreach ($employees as $employee) {
            $reason = match (true) {
                $employee->isSeparated() => 'separated',
                ! $employee->user->isActive() => 'inactive account',
                // They hold no item: the Non-Plantilla page keeps them.
                in_array($employee->employment_status, EmploymentStatus::nonPlantilla(), true) => 'non-plantilla (casual, contractual, JO, COS)',
                blank($employee->user->position) => 'no position title',
                $employee->plantillaAppointments->isNotEmpty() => 'already holds an item',
                default => null,
            };

            $reason ? $skipped[$reason][] = $employee->user->name : $toLink->push($employee);
        }

        $this->table(['Name', 'Position', 'Place of assignment (account office)'], $toLink->map(fn (Employee $employee) => [
            $employee->user->name,
            $employee->user->position,
            $employee->user->organizationalUnit?->name ?? '—',
        ])->all());

        foreach ($skipped as $reason => $names) {
            if ($names !== []) {
                $this->line(sprintf('Skipped, %s (%d): %s', $reason, count($names), implode(', ', $names)));
            }
        }

        if (! $apply) {
            $this->info("Would link {$toLink->count()} staff. Nothing was saved; run with --apply to link them.");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($toLink, $plantilla) {
            $toLink->each(fn (Employee $employee) => $plantilla->linkFromAccount($employee));
        });

        $audit->recordFor(null, AuditEvent::PlantillaLinkedFromAccounts, "Linked {$toLink->count()} staff to new plantilla items from their accounts", null, [
            'linked' => $toLink->count(),
            'skipped' => array_map('count', $skipped),
        ]);

        $this->info("Linked {$toLink->count()} staff. Each link is incomplete until HR enters the item number, step, nature and start date (and the grade on the Positions list).");

        return self::SUCCESS;
    }
}
