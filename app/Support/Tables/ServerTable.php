<?php

namespace App\Support\Tables;

use Illuminate\Contracts\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

/**
 * Server-side search / sort / paging for the admin DataTable's "server"
 * mode (large logs). Mirrors the query parameters the component sends:
 * `search`, `sort`, `direction`, `per_page`, `page`.
 *
 * Sorting is whitelisted (`$sortable` maps a column key the UI sends to a
 * real column), so a request can never sort by an arbitrary column, and
 * search terms are LIKE-escaped.
 */
class ServerTable
{
    public const PAGE_SIZES = [10, 25, 50, 100];

    /**
     * @param  array<int, string>  $searchable  columns matched with LIKE
     * @param  array<string, string>  $sortable  UI key => column
     */
    public static function apply(EloquentBuilder $query, Request $request, array $searchable, array $sortable, string $defaultSort, string $defaultDirection = 'desc'): EloquentBuilder
    {
        $search = trim((string) $request->query('search', ''));

        if ($search !== '' && $searchable !== []) {
            $pattern = '%'.addcslashes($search, '\\%_').'%';
            $query->where(function ($query) use ($searchable, $pattern) {
                foreach ($searchable as $column) {
                    $query->orWhere($column, 'like', $pattern);
                }
            });
        }

        $sortKey = (string) $request->query('sort', '');
        $column = $sortable[$sortKey] ?? $defaultSort;
        $direction = $request->query('direction') === 'asc' ? 'asc' : ($request->query('direction') === 'desc' ? 'desc' : $defaultDirection);

        return $query->orderBy($column, $sortKey === '' ? $defaultDirection : $direction);
    }

    public static function paginate(EloquentBuilder $query, Request $request): LengthAwarePaginator
    {
        $perPage = (int) $request->query('per_page', 25);

        return $query->paginate(in_array($perPage, self::PAGE_SIZES, true) ? $perPage : 25)->withQueryString();
    }

    /**
     * The table state echoed back to the page (so the component starts in
     * the state the URL describes), plus any page-specific filters.
     *
     * @param  array<int, string>  $filters
     * @return array<string, mixed>
     */
    public static function query(Request $request, array $filters = []): array
    {
        return array_filter(
            $request->only(['search', 'sort', 'direction', 'per_page', ...$filters]),
            fn ($value) => $value !== null && $value !== '',
        );
    }
}
