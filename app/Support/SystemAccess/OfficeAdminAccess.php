<?php

namespace App\Support\SystemAccess;

use App\Enums\OfficeDesignation;
use App\Enums\Status;
use App\Enums\UserStatus;
use App\Models\Application;
use App\Models\ApplicationAccess;
use App\Models\ApplicationRole;
use App\Models\OrganizationalUnit;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Makes an office's Division Chief (Regional Support Unit) or Field
 * Director (field / satellite office) overall admin of the office's
 * systems: the ones it manages and the ones listed under it. Each gets the
 * system's top admin role ("superadmin" before "admin"), written through
 * SystemAccessManager, so it's audited and mirrored to legacy like any
 * grant.
 *
 * What the designation did is remembered on the grant (`via_designation`,
 * `designation_previous_role_id`), so taking it away undoes only that: a
 * grant it created is revoked; a role it raised goes back to what it was.
 * A grant an admin already gave as admin is left alone.
 */
class OfficeAdminAccess
{
    /** Role names that mean "overall admin", strongest first. */
    private const ADMIN_ROLES = [['superadmin', 'super admin', 'super administrator'], ['admin', 'administrator']];

    public function __construct(private readonly SystemAccessManager $access) {}

    /** Whether the user's designation fits their office (Division Chief ↔ RSU, Field Director ↔ field/satellite). */
    public function designationFits(?OfficeDesignation $designation, ?OrganizationalUnit $office): bool
    {
        return $designation !== null && $office !== null && OfficeDesignation::forUnitType($office->unit_type) === $designation;
    }

    /**
     * The office's systems: managed by it, or listed under it.
     *
     * @return Collection<int, Application>
     */
    public function systemsOf(OrganizationalUnit $office): Collection
    {
        return Application::query()
            ->where(fn ($query) => $query
                ->where('organizational_unit_id', $office->id)
                ->orWhereHas('listedUnits', fn ($query) => $query->whereKey($office->id)))
            ->with('roles')
            ->orderBy('name')
            ->get();
    }

    /** The system's overall admin role, if it has one. */
    public function adminRole(Application $application): ?ApplicationRole
    {
        $roles = $application->roles->where('status', Status::Active);

        foreach (self::ADMIN_ROLES as $names) {
            $role = $roles->first(fn (ApplicationRole $role) => in_array(mb_strtolower(trim($role->name)), $names, true));

            if ($role) {
                return $role;
            }
        }

        return null;
    }

    /**
     * Brings the user's designation grants in line with their designation,
     * office and status. Returns what changed, for messages.
     *
     * @return array{granted: int, raised: int, removed: int}
     */
    public function sync(User $user): array
    {
        $user->loadMissing('organizationalUnit');
        $counts = ['granted' => 0, 'raised' => 0, 'removed' => 0];

        $designated = $user->status === UserStatus::Active && $this->designationFits($user->office_designation, $user->organizationalUnit);
        $metadata = $designated ? ['via' => "{$user->office_designation->label()} of {$user->organizationalUnit->name}"] : [];

        DB::transaction(function () use ($user, $designated, $metadata, &$counts) {
            $grants = $user->applicationAccess()->with(['role', 'application', 'user'])->get()->keyBy('application_id');
            $targets = collect();

            if ($designated) {
                foreach ($this->systemsOf($user->organizationalUnit) as $application) {
                    if (! $role = $this->adminRole($application)) {
                        continue;
                    }
                    $targets->put($application->id, true);
                    $grant = $grants->get($application->id);

                    if ($grant === null) {
                        $grant = $this->access->grant($application, $user, $role, $metadata);
                        $grant->forceFill(['via_designation' => true, 'designation_previous_role_id' => null])->save();
                        $counts['granted']++;
                    } elseif ($grant->application_role_id !== $role->id) {
                        $previous = $grant->via_designation ? $grant->designation_previous_role_id : $grant->application_role_id;
                        $this->access->change($grant, $role, $metadata);
                        $grant->forceFill(['via_designation' => true, 'designation_previous_role_id' => $previous])->save();
                        $counts['raised']++;
                    }
                    // Already admin: nothing to do (an admin's own grant stays theirs).
                }
            }

            // Undo what the designation did on systems it no longer covers.
            foreach ($grants->filter(fn (ApplicationAccess $grant) => $grant->via_designation && ! $targets->has($grant->application_id)) as $grant) {
                $undo = ['via' => 'Office designation removed'];
                $previous = $grant->designation_previous_role_id ? ApplicationRole::query()->find($grant->designation_previous_role_id) : null;

                if ($previous && $previous->status === Status::Active) {
                    $this->access->change($grant, $previous, $undo);
                    $grant->forceFill(['via_designation' => false, 'designation_previous_role_id' => null])->save();
                } else {
                    $this->access->revoke($grant, $undo);
                }
                $counts['removed']++;
            }
        });

        return $counts;
    }

    /** Re-syncs everyone a change to this system may affect. */
    public function syncApplication(Application $application): void
    {
        $offices = $application->listedUnits()->pluck('organizational_units.id')
            ->push($application->organizational_unit_id)->filter()->unique();

        User::query()
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->whereNotNull('office_designation')->whereIn('organizational_unit_id', $offices))
                ->orWhereHas('applicationAccess', fn ($query) => $query->where('application_id', $application->id)->where('via_designation', true)))
            ->get()
            ->each(fn (User $user) => $this->sync($user));
    }

    /** Re-syncs the office's Division Chief / Field Director (after its system listings change). */
    public function syncOffice(OrganizationalUnit $office): void
    {
        User::query()->where('organizational_unit_id', $office->id)->whereNotNull('office_designation')->get()
            ->each(fn (User $user) => $this->sync($user));
    }

    /** Re-syncs every designated user, and anyone still holding a designation grant. */
    public function syncAll(): int
    {
        $users = User::query()
            ->whereNotNull('office_designation')
            ->orWhereHas('applicationAccess', fn ($query) => $query->where('via_designation', true))
            ->get();

        $users->each(fn (User $user) => $this->sync($user));

        return $users->count();
    }
}
