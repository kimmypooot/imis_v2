<?php

namespace App\Http\Requests\User;

use App\Http\Requests\User\Concerns\ProfileRules;
use App\Http\Requests\User\Concerns\UsernameRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    use ProfileRules;
    use UsernameRules;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->profileRules(),
            'username' => ['required', ...$this->usernameRules($this->route('user'))],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user'))],
        ];
    }
}
