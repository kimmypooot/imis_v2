<?php

namespace App\Http\Requests\ServiceCategory;

use App\Enums\Status;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateServiceCategoryStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('archive', $this->route('serviceCategory'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', new Enum(Status::class)],
        ];
    }
}
