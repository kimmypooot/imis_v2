<?php

namespace App\Http\Requests\OrganizationalUnit;

use App\Enums\Status;
use App\Enums\UnitType;
use App\Models\OrganizationalUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

class UpdateOrganizationalUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('organizationalUnit'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => [
                'required', 'string', 'max:50', 'alpha_dash',
                Rule::unique('organizational_units', 'code')->ignore($this->route('organizationalUnit')),
            ],
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

    /**
     * Reject a parent assignment that would make this unit its own
     * ancestor — directly (self-parent) or via one of its own descendants
     * (which would otherwise flip the hierarchy in a circle). Enforced
     * here, independently of the frontend, so it cannot be bypassed by
     * hand-crafting the request.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $parentId = $this->input('parent_id');

            if (! $parentId) {
                return;
            }

            /** @var OrganizationalUnit $unit */
            $unit = $this->route('organizationalUnit');
            $candidateParent = OrganizationalUnit::find($parentId);

            if ($candidateParent && $unit->wouldCreateCycleIfParentedTo($candidateParent)) {
                $validator->errors()->add(
                    'parent_id',
                    'This parent would create a circular organizational hierarchy.',
                );
            }
        });
    }
}
