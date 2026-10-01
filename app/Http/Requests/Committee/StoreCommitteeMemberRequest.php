<?php

namespace App\Http\Requests\Committee;

use App\Enums\Status;
use App\Http\Requests\Committee\Concerns\GuardsDuplicateActiveMembership;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class StoreCommitteeMemberRequest extends FormRequest
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
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'position' => ['required', 'string', 'max:100'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'status' => ['required', new Enum(Status::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('status') !== Status::Active->value) {
                return;
            }

            $committee = $this->route('committee');
            $userId = (int) $this->input('user_id');

            if ($userId && $this->hasOtherActiveMembership($committee->id, $userId)) {
                $validator->errors()->add(
                    'user_id',
                    'This person already has an active membership on this committee.',
                );
            }
        });
    }
}
