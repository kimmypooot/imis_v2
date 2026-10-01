<?php

namespace App\Models;

use App\Enums\Status;
use App\Enums\Visibility;
use App\Models\Concerns\HasPortalVisibility;
use App\Models\Concerns\HasUniqueSlug;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A directory entry describing a CSC service — what it is, who's
 * responsible, and how to access it. Deliberately does not model the
 * service's actual transaction/workflow (no payment, no submission
 * tracking, no client accounts) — that belongs to a dedicated future
 * module, the same way Application never absorbs the ITG systems it
 * points to.
 */
#[Fillable([
    'organizational_unit_id', 'category_id', 'name', 'short_name', 'slug', 'description',
    'purpose', 'requirements', 'procedure', 'fees', 'processing_time', 'contact_information',
    'icon', 'external_url', 'status', 'visibility', 'featured', 'sort_order',
])]
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory, HasPortalVisibility, HasUniqueSlug;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => Status::class,
            'visibility' => Visibility::class,
            'featured' => 'boolean',
        ];
    }

    public function organizationalUnit(): BelongsTo
    {
        return $this->belongsTo(OrganizationalUnit::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }
}
