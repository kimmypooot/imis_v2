<?php

namespace App\Http\Requests\User\Concerns;

use App\Enums\OfficeDesignation;
use App\Enums\PermissionName;
use App\Enums\UserStatus;
use App\Models\OrganizationalUnit;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The legacy IMIS account profile fields, shared by creating and editing
 * an account. Names are required; the rest may be blank because older
 * records (and the legacy import) don't always have them.
 */
trait ProfileRules
{
    /**
     * @return array<string, mixed>
     */
    private function profileRules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:64'],
            'middle_name' => ['nullable', 'string', 'max:64'],
            'last_name' => ['required', 'string', 'max:64'],
            'name_extension' => ['nullable', 'string', 'max:10'],
            'sex' => ['nullable', 'in:male,female'],
            'birthday' => ['nullable', 'date', 'after:1900-01-01', 'before:today'],
            'position' => ['nullable', 'string', 'max:128'],
            'organizational_unit_id' => ['nullable', 'integer', 'exists:organizational_units,id'],
            'office_designation' => ['nullable', Rule::enum(OfficeDesignation::class)],
        ];
    }

    /**
     * Division Chief / Field Director: it must fit the office (RSU vs
     * field/satellite office), one per office, and — since it makes the
     * person overall admin of the office's systems — only someone who may
     * grant system access may set or change it (never on themselves).
     */
    private function validateDesignation(Validator $validator, ?User $target): void
    {
        // Not sent: left as it is (the office may still change; see OfficeAdminAccess::sync).
        if (! $this->has('office_designation')) {
            return;
        }

        $designation = OfficeDesignation::tryFrom((string) $this->input('office_designation'));
        $officeId = $this->input('organizational_unit_id') ?: null;

        if ($designation !== ($target?->office_designation)) {
            $allowed = $target ? $this->user()->can('manageSystemAccess', $target) : $this->user()->hasPermission(PermissionName::ApplicationsManageAccess->value);

            if (! $allowed) {
                $validator->errors()->add('office_designation', 'You may not set the designation: it grants admin access to the office\'s systems.');

                return;
            }
        }

        if ($designation === null) {
            return;
        }

        $office = $officeId ? OrganizationalUnit::query()->find($officeId) : null;

        if (OfficeDesignation::forUnitType($office?->unit_type) !== $designation) {
            $validator->errors()->add('office_designation', 'Division Chief is for Regional Support Units; Field Director is for field and satellite offices.');

            return;
        }

        $holder = User::query()
            ->where('organizational_unit_id', $office->id)
            ->where('office_designation', $designation)
            ->where('status', UserStatus::Active)
            ->when($target, fn ($query) => $query->whereKeyNot($target->id))
            ->first();

        if ($holder) {
            $validator->errors()->add('office_designation', "{$holder->name} is already the {$designation->label()} of {$office->name}. Remove theirs first.");
        }
    }

    /**
     * Profile photo upload: images only, up to 5 MB (the legacy limit). It
     * is re-encoded server-side (ProfilePhotos), so the original file —
     * and any metadata in it — is never stored.
     *
     * @return array<int, string>
     */
    private function photoRules(): array
    {
        return ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=64,min_height=64,max_width=8000,max_height=8000'];
    }
}
