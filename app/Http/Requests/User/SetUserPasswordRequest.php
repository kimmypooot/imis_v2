<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * A Super Administrator's password override. They confirm with their own
 * password; the new one follows the password policy (System Settings →
 * Security). Their own account goes through Change Password instead.
 */
class SetUserPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return ! $this->user()->is($target) && $this->user()->can('setPassword', $target);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'admin_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'must_change_password' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'admin_password.current_password' => 'Your password is incorrect.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'admin_password' => 'your password',
            'password' => 'new password',
        ];
    }
}
