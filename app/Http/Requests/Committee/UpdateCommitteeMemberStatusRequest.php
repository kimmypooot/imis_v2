<?php

namespace App\Http\Requests\Committee;

use App\Enums\Status;
use App\Http\Requests\Committee\Concerns\GuardsDuplicateActiveMembership;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class UpdateCommitteeMemberStatusRequest extends FormRequest
{
    use GuardsDuplicateActiveMembership;

    public function authorize(): bool
    {
        return $this->user()->can('manageMembers', $this->route('committee'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', new Enum(Status::class)],
        ];
    }

    /**
     * Reactivating a membership (inactive -> active) must still respect
     * the one-active-membership-per-user-per-committee rule.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('status') !== Status::Active->value) {
                return;
            }

            $member = $this->route('member');

            if ($this->hasOtherActiveMembership($member->committee_id, $member->user_id, $member->id)) {
                $validator->errors()->add(
                    'status',
                    'This person already has another active membership on this committee.',
                );
            }
        });
    }
}
