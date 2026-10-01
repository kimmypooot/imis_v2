<?php

namespace App\Http\Requests\Announcement;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAnnouncementFeaturedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('announcement'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'featured' => ['required', 'boolean'],
        ];
    }
}
