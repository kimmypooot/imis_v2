<?php

namespace App\Http\Resources;

use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Service
 */
class ServiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'short_name' => $this->short_name,
            'slug' => $this->slug,
            'description' => $this->description,
            'purpose' => $this->purpose,
            'requirements' => $this->requirements,
            'procedure' => $this->procedure,
            'fees' => $this->fees,
            'processing_time' => $this->processing_time,
            'contact_information' => $this->contact_information,
            'icon' => $this->icon,
            'external_url' => $this->external_url,
            'featured' => $this->featured,
            'category' => $this->whenLoaded(
                'category',
                fn () => $this->category ? ['id' => $this->category->id, 'name' => $this->category->name] : null,
            ),
            'organizational_unit' => $this->whenLoaded(
                'organizationalUnit',
                fn () => $this->organizationalUnit ? [
                    'id' => $this->organizationalUnit->id,
                    'name' => $this->organizationalUnit->name,
                ] : null,
            ),
        ];
    }
}
