<?php

namespace App\Http\Requests\User;

use App\Http\Requests\User\Concerns\ProfileRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateUserPhotoRequest extends FormRequest
{
    use ProfileRules;

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
            'profile_photo' => ['required', ...$this->photoRules()],
        ];
    }
}
