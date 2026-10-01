<?php

namespace App\Http\Requests\User\Concerns;

use App\Models\User;
use App\Support\Usernames;
use Illuminate\Validation\Rule;

/**
 * Shared username rules for creating and editing accounts. Usernames are
 * stored lowercase (sign-in lowercases what's typed), so they're
 * normalized before validation.
 */
trait UsernameRules
{
    protected function prepareForValidation(): void
    {
        if ($this->has('username')) {
            $this->merge(['username' => Usernames::normalize($this->input('username')) ?: null]);
        }
    }

    /**
     * @return array<int, mixed>
     */
    private function usernameRules(?User $ignore = null): array
    {
        return [
            'string', 'min:3', 'max:'.Usernames::MAX_LENGTH,
            'regex:'.Usernames::PATTERN,
            Rule::unique('users', 'username')->ignore($ignore),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.regex' => 'Use only lowercase letters, numbers, dots, dashes, or underscores, starting with a letter or number.',
        ];
    }
}
