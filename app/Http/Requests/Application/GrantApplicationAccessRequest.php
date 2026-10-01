<?php

namespace App\Http\Requests\Application;

use App\Http\Requests\Application\Concerns\AuthorizesSystemAccessChange;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class GrantApplicationAccessRequest extends FormRequest
{
    use AuthorizesSystemAccessChange;

    public function authorize(): bool
    {
        return $this->mayChangeAccess($this->route('application'), $this->targetUser());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $application = $this->route('application');

        return [
            'user_id' => [
                'required', 'integer', 'exists:users,id',
                Rule::unique('application_access', 'user_id')->where('application_id', $application->id),
            ],
            'application_role_id' => ['required', 'integer', $this->assignableRoleRule($application)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.unique' => 'This person already has access to this system. Change their role instead.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $target = $this->targetUser();

            if ($target && ! $target->isActive()) {
                $validator->errors()->add('user_id', 'Reactivate this account before granting it system access.');
            }
        });
    }

    public function targetUser(): ?User
    {
        $id = $this->input('user_id');

        return is_numeric($id) ? User::query()->find((int) $id) : null;
    }
}
