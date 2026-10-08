<?php

namespace App\Http\Requests\EmailTemplate;

use App\Models\Setting;
use App\Support\Mail\EmailTemplates;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Admin-3: the office's wording for one email. Changing it goes with
 * changing the settings (`settings.update`). Placeholders must be ones
 * the template offers, so a typo can't reach people as "{frist_name}".
 */
class UpdateEmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', Setting::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:200'],
            'intro' => ['nullable', 'string', 'max:3000'],
            'button' => ['nullable', 'string', 'max:60'],
            'outro' => ['nullable', 'string', 'max:3000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $allowed = EmailTemplates::placeholders($this->route('key'));

            foreach (['subject', 'intro', 'button', 'outro'] as $field) {
                preg_match_all('/\{([a-z_]+)\}/', (string) $this->input($field), $matches);
                $unknown = array_diff($matches[1], $allowed);

                if ($unknown !== []) {
                    $validator->errors()->add($field, 'Unknown placeholder: {'.implode('}, {', $unknown).'}. This email can use: {'.implode('}, {', $allowed).'}.');
                }
            }
        });
    }
}
