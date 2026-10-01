<?php

namespace App\Http\Requests\Changelog;

use App\Enums\ChangelogItemType;
use App\Models\Changelog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * Creating or editing a release note with its entries. Entries are plain
 * text, shown escaped.
 */
class ChangelogRequest extends FormRequest
{
    public function authorize(): bool
    {
        $changelog = $this->route('changelog');

        return $changelog
            ? $this->user()->can('update', $changelog)
            : $this->user()->can('create', Changelog::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'version' => [
                'required', 'string', 'max:20', 'regex:/^[0-9A-Za-z.\-]+$/',
                Rule::unique('changelogs', 'version')->ignore($this->route('changelog')),
            ],
            'title' => ['required', 'string', 'max:150'],
            'release_date' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.type' => ['required', new Enum(ChangelogItemType::class)],
            'items.*.description' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'version.regex' => 'Use letters, numbers, dots, and dashes only (e.g. 2.1.0).',
            'items.required' => 'Add at least one entry.',
            'items.min' => 'Add at least one entry.',
            'items.*.description.required' => 'Describe this entry.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function release(): array
    {
        return $this->safe()->only(['version', 'title', 'release_date']);
    }

    /**
     * @return array<int, array{type: string, description: string}>
     */
    public function entries(): array
    {
        return array_map(
            fn (array $item) => ['type' => $item['type'], 'description' => trim($item['description'])],
            $this->validated('items'),
        );
    }
}
