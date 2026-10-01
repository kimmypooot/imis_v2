<?php

namespace App\Http\Requests\Application;

use App\Enums\Status;
use App\Enums\Visibility;
use App\Models\Application;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Application::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:100'],
            'organizational_unit_id' => ['nullable', 'integer', 'exists:organizational_units,id'],
            'description' => ['nullable', 'string', 'max:2000'],
            'icon' => ['nullable', 'string', 'max:50'],
            'category' => ['nullable', 'string', 'max:100'],
            // Restricted to http/https so a stored value can never resolve
            // to a javascript:/data: URI when rendered as a launch link.
            'url' => ['nullable', 'url', 'regex:/^https?:\/\//i', 'max:255'],
            'external' => ['boolean'],
            'status' => ['required', new Enum(Status::class)],
            'visibility' => ['required', new Enum(Visibility::class)],
            'featured' => ['boolean'],
            'requires_access' => ['boolean'],
            // Extra dashboard-directory placements (besides the managing unit).
            'listed_unit_ids' => ['sometimes', 'array'],
            'listed_unit_ids.*' => ['integer', 'distinct', 'exists:organizational_units,id'],
            'listed_committee_ids' => ['sometimes', 'array'],
            'listed_committee_ids.*' => ['integer', 'distinct', 'exists:committees,id'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ];
    }
}
