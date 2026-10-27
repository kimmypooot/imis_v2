<?php

namespace App\Models;

use App\Enums\AppointmentNature;
use App\Enums\EmploymentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * HR-4: one period of service by an employee on a plantilla item, at a
 * step and status — a Service Record line (HR-5). Open (`date_to` null)
 * while they hold it. `salary_override` replaces the salary schedule's amount
 * for this period (an agreed or special rate); blank follows the schedule.
 * Written only by App\Support\HR\Plantilla\Plantilla.
 */
class PlantillaAppointment extends Model
{
    use SoftDeletes;

    protected $fillable = ['step', 'status', 'nature', 'date_from', 'date_to', 'end_reason', 'remarks', 'salary_override'];

    protected function casts(): array
    {
        return [
            'step' => 'integer',
            'salary_override' => 'decimal:2',
            'status' => EmploymentStatus::class,
            'nature' => AppointmentNature::class,
            'date_from' => 'date:Y-m-d',
            'date_to' => 'date:Y-m-d',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(PlantillaItem::class, 'plantilla_item_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function isOpen(): bool
    {
        return $this->date_to === null;
    }

    /**
     * A link made before HR had the details (`imis:link-plantilla`) lacks
     * some of these. Incomplete periods stay off the Service Record.
     *
     * @return array<int, string> the missing details, as labels
     */
    public function missingDetails(): array
    {
        return array_keys(array_filter([
            'salary grade' => $this->loadMissing('item')->item?->salary_grade === null,
            'step' => $this->step === null,
            'status' => $this->status === null,
            'nature' => $this->nature === null,
            'start date' => $this->date_from === null,
        ]));
    }

    public function isComplete(): bool
    {
        return $this->missingDetails() === [];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeComplete(Builder $query): void
    {
        $query->whereNotNull(['step', 'status', 'nature', 'date_from'])
            ->whereHas('item', fn (Builder $query) => $query->whereNotNull('salary_grade'));
    }

    protected function label(): Attribute
    {
        return Attribute::get(fn () => 'appointment of '.$this->loadMissing('employee.user')->employee?->user?->name
            .' to '.$this->loadMissing('item')->item?->reference);
    }
}
