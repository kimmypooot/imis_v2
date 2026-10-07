<?php

use App\Enums\AuditEvent;
use App\Enums\EmploymentStatus;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Casual, contractual, Job Order and Contract of Service staff hold no
 * plantilla item, but `imis:link-plantilla` once made items for them from
 * their account position (e.g. a Contract of Service Computer Technician).
 * Takes those items off: an item with no item number whose every holder,
 * past and present, is non-plantilla staff, with its holder rows. A
 * Positions-list title left with no item and no plantilla staff goes too.
 * They're listed on the Non-Plantilla page instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        $nonPlantilla = array_map(fn (EmploymentStatus $status) => $status->value, EmploymentStatus::nonPlantilla());
        $plantilla = array_map(fn (EmploymentStatus $status) => $status->value, EmploymentStatus::onPlantilla());

        $items = DB::table('plantilla_items')
            ->whereNull('item_number')
            ->whereExists(fn ($query) => $query->from('plantilla_appointments')->whereColumn('plantilla_appointments.plantilla_item_id', 'plantilla_items.id'))
            ->whereNotExists(fn ($query) => $query->from('plantilla_appointments')
                ->join('employees', 'employees.id', '=', 'plantilla_appointments.employee_id')
                ->whereColumn('plantilla_appointments.plantilla_item_id', 'plantilla_items.id')
                ->where(fn ($query) => $query->whereNull('employees.employment_status')->orWhereNotIn('employees.employment_status', $nonPlantilla)))
            ->get(['id', 'position_id', 'position_title']);

        if ($items->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($items, $plantilla) {
            DB::table('plantilla_appointments')->whereIn('plantilla_item_id', $items->pluck('id'))->delete();
            DB::table('plantilla_items')->whereIn('id', $items->pluck('id'))->delete();

            $positions = DB::table('positions')
                ->whereIn('id', $items->pluck('position_id')->filter())
                ->whereNotExists(fn ($query) => $query->from('plantilla_items')->whereColumn('plantilla_items.position_id', 'positions.id'))
                ->whereNotExists(fn ($query) => $query->from('users')
                    ->join('employees', 'employees.user_id', '=', 'users.id')
                    ->whereColumn('users.position', 'positions.title')
                    ->whereIn('employees.employment_status', $plantilla))
                ->pluck('title', 'id');
            DB::table('positions')->whereIn('id', $positions->keys())->delete();

            app(AuditLogger::class)->recordFor(null, AuditEvent::PlantillaNonPlantillaItemsRemoved,
                'Took '.$items->count().' item(s) held only by non-plantilla staff off the plantilla', null, [
                    'items' => $items->map(fn ($item) => ['id' => $item->id, 'position_title' => $item->position_title])->all(),
                    'positions' => $positions->values()->all(),
                ]);
        });
    }

    public function down(): void
    {
        // Not restored: those items should never have been on the plantilla.
    }
};
