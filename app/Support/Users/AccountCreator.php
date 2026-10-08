<?php

namespace App\Support\Users;

use App\Enums\AuditEvent;
use App\Enums\UserStatus;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\HR\EmployeeRecords;
use App\Support\SystemAccess\OfficeAdminAccess;
use App\Support\Usernames;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Creates a staff account the one way IMIS does it — from Add User and
 * from the bulk import (Admin-3): username generated the legacy way when
 * none is given, a random password the person replaces through the
 * emailed set-password link, the roles, their employee record (HR-1),
 * the audit entry, and any system access their office designation gives.
 * Input is validated by the caller.
 */
class AccountCreator
{
    public function __construct(
        private readonly EmployeeRecords $records,
        private readonly OfficeAdminAccess $officeAdmins,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $profile  honorific, first/middle/last name, name extension, sex, birthday, position, organizational_unit_id, office_designation, email
     * @param  array<int, int>  $roleIds
     * @param  array<string, mixed>  $auditExtra  added to the audit entry's metadata
     * @return array{user: User, office: array<string, int>}
     */
    public function create(array $profile, ?string $username, array $roleIds, bool $sendPasswordLink = true, array $auditExtra = []): array
    {
        $user = DB::transaction(function () use ($profile, $username, $roleIds) {
            $user = User::create([
                ...$profile,
                'name' => User::composeName($profile['first_name'], $profile['middle_name'] ?? null, $profile['last_name'], $profile['name_extension'] ?? null, $profile['honorific'] ?? null),
                // Left blank → generated the legacy way (initials + surname).
                'username' => $username ?: Usernames::generateFromParts($profile['first_name'], $profile['middle_name'] ?? null, $profile['last_name']),
                'password' => Str::random(40),
                'status' => UserStatus::Active,
            ]);

            if ($roleIds) {
                $user->roles()->sync($roleIds);
            }

            // Every new account is a staff member: their employee record
            // (HR-1) comes with it, employment status "Not set" for HR, so
            // they can fill in their PDS right away.
            $this->records->setUp($user, how: 'Automatically set up');

            return $user;
        });

        if ($sendPasswordLink) {
            Password::sendResetLink(['email' => $user->email]);
        }

        $this->audit->record(AuditEvent::UserCreated, "Created user {$user->name}", $user, [
            'username' => $user->username,
            'email' => $user->email,
            'roles' => $user->roles()->pluck('name')->all(),
            ...$auditExtra,
        ]);

        return ['user' => $user, 'office' => $this->officeAdmins->sync($user)];
    }
}
