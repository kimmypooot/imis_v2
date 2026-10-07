<?php

namespace App\Http\Requests\Account;

use App\Rules\NotRecentPassword;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Self-service password change (the legacy "Change Password" menu item).
 * The current password is re-checked server-side; the new one follows the
 * same policy as password resets (Password::defaults(), from System
 * Settings → Security) and can't be a recently used one.
 */
class UpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::defaults(), new NotRecentPassword($this->user())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.current_password' => 'Your current password is incorrect.',
            'password.different' => 'Choose a password different from your current one.',
        ];
    }
}
