<?php

namespace App\Http\Requests\Application;

use App\Enums\Status;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class UpdateApplicationRoleStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageRoles', $this->route('application'));
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
     * A role still held by someone can't be deactivated — those grants
     * would point at a role nobody can be assigned anymore. Reassign or
     * revoke them first.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('status') !== Status::Inactive->value) {
                return;
            }

            $holders = $this->route('applicationRole')->accessGrants()->count();

            if ($holders > 0) {
                $validator->errors()->add('status', trans_choice(
                    ':count person still holds this role.|:count people still hold this role.',
                    $holders,
                ).' Move them to another role or revoke their access first.');
            }
        });
    }
}
