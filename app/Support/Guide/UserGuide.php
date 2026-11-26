<?php

namespace App\Support\Guide;

use App\Models\User;
use App\Support\HR\Dtr\DtrEntryVerification;
use App\Support\HR\Leave\LeaveApplications;
use App\Support\HR\Payroll\PayrollAccounting;
use App\Support\HR\Payroll\PayslipApprovers;
use Illuminate\Support\Str;

/**
 * The in-app User Guide: the categories and articles in config/user-guide.php,
 * trimmed to what the given person may use. An article with `requires`
 * (permission names) or `audience` (roles that come from someone's position,
 * the same ones the sidebar uses) is kept when they match at least one of
 * them; an article with neither is for everyone. Empty categories are dropped.
 */
class UserGuide
{
    /**
     * @return array<int, array{title: string, icon: string, articles: array<int, array<string, mixed>>}>
     */
    public function for(User $user): array
    {
        $audiences = [];

        return collect(config('user-guide.categories', []))
            ->map(function (array $category) use ($user, &$audiences): array {
                $articles = collect($category['articles'])
                    ->filter(fn (array $article): bool => $this->visibleTo($user, $article['requires'] ?? [], $article['audience'] ?? [], $audiences))
                    ->map(fn (array $article): array => [
                        'slug' => $article['slug'],
                        'title' => $article['title'],
                        'summary' => $article['summary'],
                        'steps' => $article['steps'],
                        'tips' => $article['tips'] ?? [],
                        'link' => $article['link'] ?? null,
                    ])
                    ->values()
                    ->all();

                return [
                    'title' => $category['title'],
                    'icon' => $category['icon'],
                    'articles' => $articles,
                ];
            })
            ->filter(fn (array $category): bool => $category['articles'] !== [])
            ->values()
            ->all();
    }

    /**
     * The article that explains the page at `$path` (e.g. "my/leave/apply"),
     * for the page header's "How does this work?" link: one whose link goes
     * to that page, or else one whose `pages` patterns cover it. Only
     * articles this person may read count; the first in guide order wins.
     *
     * @return array{slug: string, title: string, href: string}|null
     */
    public function articleFor(User $user, string $path): ?array
    {
        $path = trim($path, '/');
        $articles = collect(config('user-guide.categories', []))->flatMap(fn (array $category): array => $category['articles']);

        $linked = $articles->filter(fn (array $article): bool => trim(parse_url($article['link']['href'] ?? '', PHP_URL_PATH) ?? '', '/') === $path);
        $covered = $articles->filter(fn (array $article): bool => Str::is($article['pages'] ?? [], $path));

        $audiences = [];
        $article = $linked->concat($covered)->first(
            fn (array $article): bool => $this->visibleTo($user, $article['requires'] ?? [], $article['audience'] ?? [], $audiences),
        );

        return $article ? [
            'slug' => $article['slug'],
            'title' => $article['title'],
            'href' => '/guide#'.$article['slug'],
        ] : null;
    }

    /**
     * @param  array<int, string>  $requires
     * @param  array<int, string>  $audience
     * @param  array<string, bool>  $audiences  answers already worked out for this person
     */
    private function visibleTo(User $user, array $requires, array $audience, array &$audiences): bool
    {
        if ($requires === [] && $audience === []) {
            return true;
        }

        foreach ($requires as $permission) {
            if ($user->hasPermission($permission)) {
                return true;
            }
        }

        foreach ($audience as $name) {
            $audiences[$name] ??= $this->belongsTo($user, $name);

            if ($audiences[$name]) {
                return true;
            }
        }

        return false;
    }

    /**
     * The position-based roles, worked out as the sidebar's `flags` are.
     */
    private function belongsTo(User $user, string $audience): bool
    {
        return match ($audience) {
            // A supervisor, HR certifier, the Head or an officer-in-charge.
            'leaveApprover' => app(LeaveApplications::class)->isApprover($user),
            // Supervises or heads people whose entered DTR days they verify.
            'dtrVerifier' => app(DtrEntryVerification::class)->employeeIdsFor($user)->isNotEmpty(),
            // The head of HRD, or someone designated head of HRD.
            'payslipApprover' => app(PayslipApprovers::class)->isApprover($user),
            // The Accountant III (or `payroll.tax`), who enters withholding tax.
            'payrollTaxReviewer' => app(PayrollAccounting::class)->isReviewer($user),
            default => false,
        };
    }
}
