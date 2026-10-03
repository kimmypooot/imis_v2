<?php

namespace App\Http\Requests\User\Concerns;

/**
 * The legacy IMIS account profile fields, shared by creating and editing
 * an account. Names are required; the rest may be blank because older
 * records (and the legacy import) don't always have them.
 */
trait ProfileRules
{
    /**
     * @return array<string, mixed>
     */
    private function profileRules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:64'],
            'middle_name' => ['nullable', 'string', 'max:64'],
            'last_name' => ['required', 'string', 'max:64'],
            'name_extension' => ['nullable', 'string', 'max:10'],
            'sex' => ['nullable', 'in:male,female'],
            'birthday' => ['nullable', 'date', 'after:1900-01-01', 'before:today'],
            'position' => ['nullable', 'string', 'max:128'],
            'organizational_unit_id' => ['nullable', 'integer', 'exists:organizational_units,id'],
        ];
    }

    /**
     * Profile photo upload: images only, up to 5 MB (the legacy limit). It
     * is re-encoded server-side (ProfilePhotos), so the original file —
     * and any metadata in it — is never stored.
     *
     * @return array<int, string>
     */
    private function photoRules(): array
    {
        return ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=64,min_height=64,max_width=8000,max_height=8000'];
    }
}
