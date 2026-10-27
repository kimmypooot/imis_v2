<?php

namespace App\Http\Requests\Feedback;

use App\Enums\FeedbackType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Anyone signed in may send feedback. The page they were on arrives from
 * the dialog; only its path is kept (never a query string or fragment),
 * and anything that is not an in-app path is dropped.
 */
class StoreFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $raw = (string) $this->input('page_path');
        $isInAppPath = str_starts_with($raw, '/') && ! str_starts_with($raw, '//') && ! str_contains($raw, '\\');
        $path = $isInAppPath ? parse_url($raw, PHP_URL_PATH) : null;

        $this->merge(['page_path' => is_string($path) ? mb_substr($path, 0, 255) : null]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', new Enum(FeedbackType::class)],
            'subject' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'page_path' => ['nullable', 'string', 'max:255'],
        ];
    }
}
