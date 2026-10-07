<?php

namespace App\Support\Executive;

use App\Models\ExecutiveSnapshot;
use App\Models\ExecutiveTarget;
use App\Notifications\ExecutiveAlert;
use App\Support\Executive\Sources\MetricSource;
use App\Support\Settings\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Exec-3: tells top management (the bell) when a collection changes
 * something they should know — only on the change, never again while it
 * stays that way:
 * - a figure falls behind its target;
 * - a source's figures can't be collected (or come back).
 * Off when System Settings → Executive Dashboard → alerts is off.
 */
class MetricAlerts
{
    /**
     * @param  Collection<string, ExecutiveTarget>  $targets  keyed "source/metric"
     */
    public function compare(MetricSource $source, ?ExecutiveSnapshot $previous, ExecutiveSnapshot $current, Collection $targets): void
    {
        if (! Settings::get('executive_alerts')) {
            return;
        }

        $alerts = [];

        if (! $current->succeeded() && ($previous === null || $previous->succeeded())) {
            $alerts[] = ["{$source->label()}: figures not updating", "The last collection failed: {$current->error}"];
        } elseif ($current->succeeded() && $previous && ! $previous->succeeded()) {
            $alerts[] = ["{$source->label()}: figures updating again", 'The latest collection succeeded.'];
        }

        if ($current->succeeded()) {
            $before = collect($previous?->succeeded() ? $previous->metrics : [])->keyBy('key');

            foreach ($current->metrics ?? [] as $metric) {
                $target = $targets[TargetStatus::key($source->key(), $metric['key'])] ?? null;
                $now = TargetStatus::of($metric['value'], $target);
                $was = isset($before[$metric['key']]) ? TargetStatus::of($before[$metric['key']]['value'], $target) : null;

                if ($now === TargetStatus::BEHIND && $was !== TargetStatus::BEHIND) {
                    $alerts[] = [
                        "{$source->label()}: {$metric['label']} is behind target",
                        'Now '.MetricFormat::value($metric['value'], $metric['unit']).' against a target of '.MetricFormat::value($target->target, $metric['unit']).'.',
                    ];
                }
            }
        }

        if ($alerts === []) {
            return;
        }

        $audience = ExecutiveAudience::members();

        foreach ($alerts as [$title, $message]) {
            Notification::send($audience, new ExecutiveAlert($title, $message));
        }
    }
}
