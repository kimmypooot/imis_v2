<?php

namespace App\Http\Requests\ServiceCategory;

use App\Enums\Status;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class UpdateServiceCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('serviceCategory'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('service_categories', 'name')->ignore($this->route('serviceCategory')),
            ],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', new Enum(Status::class)],
            'sort_order' => ['required', 'integer', 'min:0'],
        ];
    }
}
