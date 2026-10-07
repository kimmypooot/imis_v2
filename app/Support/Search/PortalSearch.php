<?php

namespace App\Support\Search;

use App\Enums\SearchType;
use App\Models\Announcement;
use App\Models\Application;
use App\Models\OrganizationalUnit;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Portal-wide search over the four portal content types — plain SQL
 * `LIKE` on a small, visibility-filtered dataset, not a search engine.
 *
 * Authorization is the same as every portal listing, because every query
 * starts from the same scope those listings use:
 * - services/applications: `visibleTo($user)` (active + visibility tier;
 *   administrative-tier entries only for `access-admin` or `access-hr` users)
 * - organizational units: `active()`
 * - announcements: `published()` (published, past publish date, not expired)
 *
 * Every query is bounded (a count, a top-N preview, or one page), selects
 * only the columns a result needs (never long text such as service
 * procedures or announcement bodies), and eager-loads its one relation,
 * so there are no N+1 queries.
 */
class PortalSearch
{
    public const MIN_LENGTH = 2;

    public const MAX_LENGTH = 100;

    public const PREVIEW_LIMIT = 5;

    public const PER_PAGE = 15;

    /**
     * Searched columns per type; the first is the title used for ranking.
     */
    private const SEARCH_COLUMNS = [
        'services' => ['name', 'short_name', 'description'],
        'applications' => ['name', 'short_name', 'description', 'category'],
        'organizational_units' => ['name', 'short_name', 'code', 'description'],
        'announcements' => ['title', 'summary'],
    ];

    private const SELECT_COLUMNS = [
        'services' => ['id', 'name', 'short_name', 'description', 'category_id', 'sort_order'],
        'applications' => ['id', 'name', 'short_name', 'description', 'category', 'sort_order'],
        'organizational_units' => ['id', 'name', 'short_name', 'code', 'unit_type', 'description', 'sort_order'],
        'announcements' => ['id', 'title', 'slug', 'summary', 'featured', 'publish_at'],
    ];

    public function __construct(private readonly ?User $user) {}

    /**
     * Collapses whitespace and caps the length. Anything that isn't a
     * string (e.g. `?q[]=x`) becomes an empty query.
     */
    public static function normalize(mixed $query): string
    {
        if (! is_string($query)) {
            return '';
        }

        return Str::of($query)->squish()->substr(0, self::MAX_LENGTH)->trim()->value();
    }

    public static function isSearchable(string $query): bool
    {
        return mb_strlen($query) >= self::MIN_LENGTH;
    }

    /**
     * @return array<string, int> keyed by SearchType value
     */
    public function counts(string $query): array
    {
        return collect(SearchType::cases())
            ->mapWithKeys(fn (SearchType $type) => [$type->value => $this->matching($type, $query)->count()])
            ->all();
    }

    /**
     * Visible, matching, ranked records of one type — ready to `limit()`
     * or `paginate()`.
     */
    public function query(SearchType $type, string $query): Builder
    {
        $builder = $this->matching($type, $query)->select(self::SELECT_COLUMNS[$type->value]);
        $title = self::SEARCH_COLUMNS[$type->value][0];
        $pattern = $this->escapeLike($query);

        // Exact title match, then title prefix, then any other match.
        $builder->orderByRaw(
            "CASE WHEN {$this->wrap($builder, $title)} LIKE ? ESCAPE '!' THEN 0"
            ." WHEN {$this->wrap($builder, $title)} LIKE ? ESCAPE '!' THEN 1 ELSE 2 END",
            [$pattern, "{$pattern}%"],
        );

        return match ($type) {
            SearchType::Services => $builder->with('category:id,name')->orderBy('sort_order')->orderBy('name'),
            SearchType::Applications,
            SearchType::OrganizationalUnits => $builder->orderBy('sort_order')->orderBy('name'),
            SearchType::Announcements => $builder->orderByDesc('featured')->orderByDesc('publish_at'),
        };
    }

    /**
     * One result, in the same shape for every type, so the frontend has a
     * single result component.
     *
     * @return array{type: string, type_label: string, id: int, title: string, subtitle: ?string, excerpt: ?string, date: ?string, href: string}
     */
    public function present(SearchType $type, Model $model): array
    {
        [$title, $subtitle, $excerpt, $href] = match ($type) {
            SearchType::Services => [
                $model->name,
                $model->category?->name ?? $model->short_name,
                $model->description,
                "/services/{$model->id}",
            ],
            SearchType::Applications => [
                $model->name,
                collect([$model->short_name, $model->category])->filter()->implode(' · ') ?: null,
                $model->description,
                "/applications/{$model->id}",
            ],
            SearchType::OrganizationalUnits => [
                $model->name,
                collect([$model->unit_type?->label(), $model->code])->filter()->implode(' · ') ?: null,
                $model->description,
                "/organizational-units/{$model->id}",
            ],
            SearchType::Announcements => [
                $model->title,
                null,
                $model->summary,
                "/announcements/{$model->slug}",
            ],
        };

        return [
            'type' => $type->value,
            'type_label' => $type->singularLabel(),
            'id' => $model->id,
            'title' => $title,
            'subtitle' => $subtitle,
            'excerpt' => $excerpt === null ? null : Str::limit($excerpt, 180),
            'date' => $type === SearchType::Announcements ? $model->publish_at?->toIso8601String() : null,
            'href' => $href,
        ];
    }

    private function matching(SearchType $type, string $query): Builder
    {
        $builder = match ($type) {
            SearchType::Services => Service::query()->visibleTo($this->user),
            SearchType::Applications => Application::query()->visibleTo($this->user),
            SearchType::OrganizationalUnits => OrganizationalUnit::query()->active(),
            SearchType::Announcements => Announcement::query()->published(),
        };

        $pattern = '%'.$this->escapeLike($query).'%';

        return $builder->where(function (Builder $builder) use ($type, $pattern) {
            foreach (self::SEARCH_COLUMNS[$type->value] as $column) {
                $builder->orWhereRaw("{$this->wrap($builder, $column)} LIKE ? ESCAPE '!'", [$pattern]);
            }
        });
    }

    /**
     * `%` and `_` typed by the user are matched literally rather than as
     * wildcards. `!` is the escape character because, unlike `\`, it means
     * the same thing in MySQL and SQLite string literals.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    private function wrap(Builder $builder, string $column): string
    {
        return $builder->getQuery()->getGrammar()->wrap($builder->getModel()->qualifyColumn($column));
    }
}
