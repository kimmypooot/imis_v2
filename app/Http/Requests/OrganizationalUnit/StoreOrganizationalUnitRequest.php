<?php

namespace App\Http\Requests\OrganizationalUnit;

use App\Enums\Status;
use App\Enums\UnitType;
use App\Models\OrganizationalUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreOrganizationalUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', OrganizationalUnit::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:organizational_units,code'],
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:100'],
            'parent_id' => ['nullable', 'integer', 'exists:organizational_units,id'],
            'description' => ['nullable', 'string', 'max:2000'],
            'unit_type' => ['required', new Enum(UnitType::class)],
            'status' => ['required', new Enum(Status::class)],
            'sort_order' => ['required', 'integer', 'min:0'],
            'email' => ['nullable', 'email', 'max:255'],
            'telephone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'website_url' => ['nullable', 'url', 'max:255'],
        ];
    }
}
