<?php

namespace App\Http\Resources;

use App\Models\OrganizationalUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrganizationalUnit
 */
class OrganizationalUnitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'short_name' => $this->short_name,
            'slug' => $this->slug,
            'unit_type' => $this->unit_type?->value,
            'unit_type_label' => $this->unit_type?->label(),
            'description' => $this->description,
            'email' => $this->email,
            'telephone' => $this->telephone,
            'address' => $this->address,
            'website_url' => $this->website_url,
            'children_count' => $this->when(
                $this->children_count !== null,
                fn () => $this->children_count,
            ),
            'parent' => $this->whenLoaded(
                'parent',
                fn () => $this->parent ? [
                    'id' => $this->parent->id,
                    'name' => $this->parent->name,
                ] : null,
            ),
            'children' => $this->whenLoaded(
                'children',
                fn () => $this->children->map(fn (OrganizationalUnit $child) => [
                    'id' => $child->id,
                    'name' => $child->name,
                    'short_name' => $child->short_name,
                ]),
            ),
        ];
    }
}
