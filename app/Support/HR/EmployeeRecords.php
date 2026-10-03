<?php

namespace App\Support\HR;

use App\Enums\AuditEvent;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Employee;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one place an employee record is set up for an account — whether HR
 * adds one, sets up many at once, or an admin creates a new account (which
 * gets its record automatically). Each record is audited the same way.
 */
class EmployeeRecords
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $employment  fillable employment fields
     */
    public function setUp(User $user, array $employment = [], string $how = 'Set up'): Employee
    {
        $employee = new Employee($employment);
        $employee->user()->associate($user);
        $employee->save();

        $this->audit->record(AuditEvent::EmployeeCreated, "{$how} the {$employee->label}", $employee);

        return $employee;
    }

    /**
     * Active accounts that don't have an employee record yet — the people
     * HR can still set one up for. HR never sets up their own (a Super
     * Administrator may).
     *
     * @return Builder<User>
     */
    public function candidates(User $viewer): Builder
    {
        return User::query()
            ->where('status', UserStatus::Active)
            ->whereDoesntHave('employee')
            ->when(! $viewer->hasRole(RoleName::SuperAdmin), fn (Builder $query) => $query->whereKeyNot($viewer->id));
    }
}
