<?php

namespace App\Http\Resources;

use App\Models\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Application
 */
class ApplicationResource extends JsonResource
{
    /**
     * Shapes what the frontend receives — deliberately never the raw
     * model. No internal status/visibility flags, foreign keys, or
     * timestamps reach the client; only what a directory card/detail page
     * needs to render.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $access = $this->accessFor($request->user());

        return [
            'id' => $this->id,
            'name' => $this->name,
            'short_name' => $this->short_name,
            'description' => $this->description,
            'icon' => $this->icon,
            // Resolved for the viewer: their role's entry point, the
            // system's own URL, or null (under maintenance, or requires
            // access they don't have). Never the raw column.
            'url' => $this->launchUrlFor($request->user()),
            'requires_access' => $this->requires_access,
            'role' => $access?->role->name,
            'under_maintenance' => $this->under_maintenance,
            'maintenance_message' => $this->maintenance_message,
            'maintenance_started_at' => $this->maintenance_started_at?->toIso8601String(),
            'category' => $this->category,
            'external' => $this->external,
            'featured' => $this->featured,
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
