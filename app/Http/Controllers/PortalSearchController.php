<?php

namespace App\Http\Controllers;

use App\Enums\SearchType;
use App\Support\Search\PortalSearch;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `GET /search?q=…[&type=…][&page=…]`.
 *
 * Without `type`: per-type match counts plus the top few results of each
 * type that matched. With `type`: that type only, paginated. All matching
 * and visibility rules live in App\Support\Search\PortalSearch.
 */
class PortalSearchController extends Controller
{
    public function index(Request $request): Response
    {
        $query = PortalSearch::normalize($request->query('q'));
        $type = SearchType::tryFrom((string) (is_string($request->query('type')) ? $request->query('type') : ''));
        $search = new PortalSearch($request->user());

        $counts = null;
        $groups = [];
        $results = null;

        if (PortalSearch::isSearchable($query)) {
            $counts = $search->counts($query);

            if ($type) {
                $results = $search->query($type, $query)
                    ->paginate(PortalSearch::PER_PAGE)
                    ->withQueryString()
                    ->through(fn ($model) => $search->present($type, $model));
            } else {
                // Only types that matched are queried for a preview.
                $groups = collect(SearchType::cases())
                    ->filter(fn (SearchType $type) => $counts[$type->value] > 0)
                    ->map(fn (SearchType $type) => [
                        'type' => $type->value,
                        'label' => $type->label(),
                        'total' => $counts[$type->value],
                        'items' => $search->query($type, $query)
                            ->limit(PortalSearch::PREVIEW_LIMIT)
                            ->get()
                            ->map(fn ($model) => $search->present($type, $model)),
                    ])
                    ->values();
            }
        }

        return Inertia::render('Portal/Search', [
            'query' => $query,
            'type' => $type?->value,
            'typeOptions' => SearchType::options(),
            'minLength' => PortalSearch::MIN_LENGTH,
            'maxLength' => PortalSearch::MAX_LENGTH,
            'counts' => $counts,
            'groups' => $groups,
            'results' => $results,
        ]);
    }
}
