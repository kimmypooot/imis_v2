<?php

namespace App\Http\Resources;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An employee record as the HR pages and "My Employee Record" show it:
 * identity read from the user account, employment facts from the record.
 * Expects `user.organizationalUnit` to be loaded.
 *
 * @mixin Employee
 */
class EmployeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->user;

        return [
            'id' => $this->id,
            'employee_number' => $this->employee_number,
            'employment_status' => $this->employment_status?->value,
            'employment_status_label' => $this->employment_status?->label() ?? 'Not set',
            'original_appointment_date' => $this->original_appointment_date?->toDateString(),
            'separation_date' => $this->separation_date?->toDateString(),
            'separation_reason' => $this->separation_reason,
            'remarks' => $this->remarks,
            'separated' => $this->isSeparated(),
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'position' => $user->position,
                'sex' => $user->sex,
                'birthday' => $user->birthday?->toDateString(),
                'status' => $user->status->value,
                'profile_photo_url' => $user->profile_photo_url,
                'unit' => $user->organizationalUnit?->short_name ?: $user->organizationalUnit?->name,
                'unit_id' => $user->organizational_unit_id,
            ],
            // What this viewer may do — resolved here so the page never
            // guesses (the routes and policy still enforce it).
            'can' => [
                'update' => $request->user()?->can('update', $this->resource) ?? false,
                'viewPds' => $request->user()?->can('viewPds', $this->resource) ?? false,
                'viewDocuments' => $request->user()?->can('viewDocuments', $this->resource) ?? false,
                'viewLd' => $request->user()?->can('viewLd', $this->resource) ?? false,
                'reassign' => $request->user()?->can('reassign', $this->resource) ?? false,
                'viewServiceRecord' => $request->user()?->can('viewServiceRecord', $this->resource) ?? false,
                'viewLeave' => $request->user()?->can('viewLeave', $this->resource) ?? false,
            ],
        ];
    }
}
