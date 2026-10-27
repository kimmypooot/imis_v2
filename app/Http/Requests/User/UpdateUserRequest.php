<?php

namespace App\Http\Requests\User;

use App\Http\Requests\User\Concerns\ProfileRules;
use App\Http\Requests\User\Concerns\UsernameRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'plantilla_item_id' => $this->plantillaItemRules(),
            'username' => ['required', ...$this->usernameRules($this->route('user'))],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user'))],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateDesignation($validator, $this->route('user'));
            $this->validatePlantillaItem($validator, $this->route('user'));
        });
    }
}
