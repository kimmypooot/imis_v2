<?php

namespace App\Http\Requests\Executive;

use App\Models\ExecutiveSnapshot;
use Illuminate\Foundation\Http\FormRequest;

class UpdateFeedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage', ExecutiveSnapshot::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // http(s) only, like a system's launch URL; plain http is allowed
            // for systems on the office network.
            'feed_url' => ['nullable', 'url', 'regex:/^https?:\/\//i', 'max:500'],
        ];
    }
}
