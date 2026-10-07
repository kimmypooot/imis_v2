<?php

namespace App\Support\Executive\Sources;

use App\Models\Application;
use App\Support\Executive\MetricFeed;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Exec-2: a connected system's summary feed. IMIS asks the system's
 * `feed_url` for its figures with the shared key as a bearer token; the
 * system answers in the MetricFeed format (docs/executive-feed.md). IMIS
 * never connects to a system's database.
 */
class SystemFeedSource implements MetricSource
{
    public const TIMEOUT_SECONDS = 10;

    /** A summary is small; anything bigger isn't one. */
    public const MAX_BYTES = 256 * 1024;

    public function __construct(private readonly Application $application) {}

    public static function keyFor(Application $application): string
    {
        return "system:{$application->id}";
    }

    public function key(): string
    {
        return self::keyFor($this->application);
    }

    public function label(): string
    {
        return $this->application->name;
    }

    public function kind(): string
    {
        return 'system';
    }

    public function collect(): array
    {
        if (blank($this->application->feed_key)) {
            throw new RuntimeException('No feed key yet — generate one in Executive Dashboard → Settings.');
        }

        $response = Http::withToken($this->application->feed_key)
            ->acceptJson()
            ->timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(5)
            ->withoutRedirecting()
            ->get($this->application->feed_url);

        if (! $response->successful()) {
            throw new RuntimeException("The system answered HTTP {$response->status()}.");
        }

        if (strlen($response->body()) > self::MAX_BYTES) {
            throw new RuntimeException('The feed is larger than '.(self::MAX_BYTES / 1024).' KB.');
        }

        return MetricFeed::fromFeed($response->json());
    }
}
