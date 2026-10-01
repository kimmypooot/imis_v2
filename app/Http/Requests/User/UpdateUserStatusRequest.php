<?php

namespace App\Http\Requests\User;

use App\Enums\UserStatus;
use App\Http\Requests\Concerns\GuardsLastSuperAdmin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class UpdateUserStatusRequest extends FormRequest
{
    use GuardsLastSuperAdmin;

    public function authorize(): bool
    {
        return $this->user()->can('updateStatus', $this->route('user'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', new Enum(UserStatus::class)],
        ];
    }

    /**
     * A Super Administrator can never be deactivated if doing so would
     * leave the system with no other active Super Administrator — the
     * account that could reverse the deactivation would no longer exist.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $target = $this->route('user');

            if ($this->input('status') === UserStatus::Inactive->value && $this->isLastActiveSuperAdmin($target)) {
                $validator->errors()->add(
                    'status',
                    'The last active Super Administrator cannot be deactivated.',
                );
            }
        });
    }
}
