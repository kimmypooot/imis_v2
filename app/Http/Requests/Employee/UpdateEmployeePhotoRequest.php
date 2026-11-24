<?php

namespace App\Http\Requests\Employee;

use App\Http\Requests\User\Concerns\ProfileRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * HR setting the person's profile photo from the employee record (the
 * cropped square from the photo picker).
 */
class UpdateEmployeePhotoRequest extends FormRequest
{
    use ProfileRules;

    public function authorize(): bool
    {
        return $this->user()->can('updatePersonal', $this->route('employee'));
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
