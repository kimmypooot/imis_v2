<?php

namespace App\Models;

use App\Enums\EmploymentStatus;
use App\Enums\PermissionName;
use App\Models\Pds\PdsLearningDevelopment;
use App\Models\Pds\PdsProfile;
use App\Models\Pds\PdsSubmission;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * HR-1: a person's employee record, one per user. Identity (name, sex,
 * birthday, position, office) is read from the user; this holds the
 * employment facts HR owns. Personal data under RA 10173: every read by
 * someone other than the employee is audited (see EmployeeController).
 */
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

    /**
     * `user_id` is deliberately not fillable: it is set once, explicitly,
     * when the record is created, and never moved to another person.
     */
    protected $fillable = [
        'employee_number',
        'employment_status',
        'original_appointment_date',
        'separation_date',
        'separation_reason',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'employment_status' => EmploymentStatus::class,
            'original_appointment_date' => 'date:Y-m-d',
            'separation_date' => 'date:Y-m-d',
            // HR-6 leave settings (written by LeaveCredits::updateSettings).
            'is_solo_parent' => 'boolean',
            'solo_parent_id_number' => 'encrypted',
            'solo_parent_id_expiry' => 'date:Y-m-d',
        ];
    }

    /**
     * HR-6: who recommends this person's leave (CS Form 6, Part B).
     */
    public function immediateSupervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'immediate_supervisor_id');
    }

    /**
     * HR-6: the leave-credit ledger (the leave card's lines).
     */
    public function leaveLedger(): HasMany
    {
        return $this->hasMany(LeaveLedgerEntry::class);
    }

    /**
     * The same ledger, named for the `{entry}` route parameter so admin
     * routes can scope an entry to its employee.
     */
    public function entries(): HasMany
    {
        return $this->leaveLedger();
    }

    /** A solo parent with an ID that hasn't expired (Solo Parent Leave). */
    public function hasValidSoloParentId(?Carbon $on = null): bool
    {
        return $this->is_solo_parent
            && filled($this->solo_parent_id_number)
            && ($this->solo_parent_id_expiry === null || $this->solo_parent_id_expiry->gte(($on ?? today())->copy()->startOfDay()));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * HR-2: the single-value items of this person's PDS. The repeating
     * sections are their own models (App\Models\Pds), defined by PdsSchema.
     */
    public function pdsProfile(): HasOne
    {
        return $this->hasOne(PdsProfile::class);
    }

    /**
     * HR-2b: the signed PDS submissions (the verified ones are the PDS
     * history in the 201 file).
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(PdsSubmission::class);
    }

    /**
     * HR-2c: the documents in this person's 201 file (incl. archived).
     */
    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    /**
     * HR-3: the Learning & Development log (PDS Section VI). Named for the
     * `{training}` route parameter, so admin routes can scope it.
     */
    public function trainings(): HasMany
    {
        return $this->hasMany(PdsLearningDevelopment::class);
    }

    /**
     * HR-4: periods of service on plantilla items (incl. past ones).
     */
    public function plantillaAppointments(): HasMany
    {
        return $this->hasMany(PlantillaAppointment::class);
    }

    /**
     * HR-1b: movements between offices (reassignments, details, …).
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeAssignment::class);
    }

    /**
     * How audit entries name this record ("Employee record of Juan D.
     * Cruz"), since it has no name of its own.
     */
    protected function label(): Attribute
    {
        return Attribute::get(fn () => 'Employee record of '.$this->loadMissing('user')->user?->name);
    }

    public function isSeparated(): bool
    {
        return $this->separation_date !== null && $this->separation_date->isPast();
    }

    /**
     * The records a viewer may see: everyone's with `employees.view`, their
     * own office's with `employees.view_unit`, and always their own. The
     * policy applies the same rule to a single record.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $viewer): void
    {
        if ($viewer->hasPermission(PermissionName::EmployeesView->value)) {
            return;
        }

        $query->where(function (Builder $query) use ($viewer) {
            $query->where('user_id', $viewer->id);

            if ($viewer->organizational_unit_id && $viewer->hasPermission(PermissionName::EmployeesViewUnit->value)) {
                $query->orWhereHas('user', fn (Builder $user) => $user->where('organizational_unit_id', $viewer->organizational_unit_id));
            }
        });
    }
}
