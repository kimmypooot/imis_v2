<?php

namespace App\Http\Requests\ServiceCategory;

use App\Enums\Status;
use App\Models\ServiceCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreServiceCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', ServiceCategory::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:service_categories,name'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', new Enum(Status::class)],
            'sort_order' => ['required', 'integer', 'min:0'],
        ];
    }
}
