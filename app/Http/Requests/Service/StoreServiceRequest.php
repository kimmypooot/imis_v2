<?php

namespace App\Http\Requests\Service;

use App\Enums\Status;
use App\Enums\Visibility;
use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Service::class);
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
            'category_id' => ['nullable', 'integer', 'exists:service_categories,id'],
            'description' => ['nullable', 'string', 'max:2000'],
            'purpose' => ['nullable', 'string', 'max:2000'],
            'requirements' => ['nullable', 'string', 'max:2000'],
            'procedure' => ['nullable', 'string', 'max:2000'],
            'fees' => ['nullable', 'string', 'max:255'],
            'processing_time' => ['nullable', 'string', 'max:255'],
            'contact_information' => ['nullable', 'string', 'max:1000'],
            // Restricted to http/https so a stored value can never resolve
            // to a javascript:/data: URI when rendered as a link.
            'external_url' => ['nullable', 'url', 'regex:/^https?:\/\//i', 'max:255'],
            'status' => ['required', new Enum(Status::class)],
            'visibility' => ['required', new Enum(Visibility::class)],
            'featured' => ['boolean'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ];
    }
}
