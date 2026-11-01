<?php

namespace App\Support\Guide;

use App\Models\User;

/**
 * The in-app User Guide: the categories and articles in config/user-guide.php,
 * trimmed to what the given person may use (an article with `requires` is
 * kept only when they hold at least one of those permissions). Empty
 * categories are dropped.
 */
class UserGuide
{
    /**
     * @return array<int, array{title: string, icon: string, articles: array<int, array<string, mixed>>}>
     */
    public function for(User $user): array
    {
        return collect(config('user-guide.categories', []))
            ->map(function (array $category) use ($user): array {
                $articles = collect($category['articles'])
                    ->filter(fn (array $article): bool => $this->visibleTo($user, $article['requires'] ?? []))
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
     * @param  array<int, string>  $requires
     */
    private function visibleTo(User $user, array $requires): bool
    {
        if ($requires === []) {
            return true;
        }

        foreach ($requires as $permission) {
            if ($user->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }
}
