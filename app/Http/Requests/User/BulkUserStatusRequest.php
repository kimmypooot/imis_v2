<?php

namespace App\Http\Requests\User;

use App\Enums\PermissionName;
use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Activate or deactivate several accounts at once. Each account is still
 * checked one by one in the controller (policy, last Super Administrator,
 * yourself), so a mixed selection changes what it may and reports the rest.
 */
class BulkUserStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission(PermissionName::UsersDelete->value);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'distinct', 'exists:users,id'],
            'status' => ['required', new Enum(UserStatus::class)],
        ];
    }
}
