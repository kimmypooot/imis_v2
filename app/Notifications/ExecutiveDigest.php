<?php

namespace App\Notifications;

use App\Notifications\Concerns\EmailsWhenWanted;
use App\Support\Executive\MetricFormat;
use App\Support\Executive\TargetStatus;
use App\Support\Mail\EmailTemplates;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Exec-3: the weekly Executive Dashboard summary, by email and under the
 * bell. Built from the dashboard's own data (ExecutiveDashboard::build)
 * for the recipient; totals only.
 */
class ExecutiveDigest extends Notification
{
    use EmailsWhenWanted;

    public const KIND = 'executive_digest';

    /**
     * @param  array<string, mixed>  $dashboard
     */
    public function __construct(private readonly array $dashboard) {}

    /** In the office's "weekly summary" wording (Admin → Email Templates). */
    public function toMail(object $notifiable): MailMessage
    {
        $lines = [
            $this->headline(),
            ...array_map(fn (array $system) => "• {$system['fullName']}: {$system['detail']}", $this->systemsNeedingAttention()),
            ...array_map(fn (string $line) => "• {$line}", $this->offTarget()),
        ];

        return EmailTemplates::mail('weekly_summary', [
            'first_name' => $notifiable->first_name ?: $notifiable->name,
            'name' => $notifiable->name,
        ], url('/executive'), after: $lines);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['kind' => 'executive', 'title' => 'Weekly summary', 'message' => $this->headline(), 'url' => '/executive'];
    }

    private function headline(): string
    {
        $summary = $this->dashboard['summary'];
        $parts = [
            $summary['systemsNeedingAttention'] === 0
                ? 'All systems are running normally.'
                : "{$summary['systemsNeedingAttention']} of {$summary['systems']} systems need attention.",
        ];

        if ($summary['withTargets'] > 0) {
            $parts[] = "Against targets: {$summary['onTrack']} on track, {$summary['atRisk']} at risk, {$summary['behind']} behind.";
        }

        return implode(' ', $parts);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function systemsNeedingAttention(): array
    {
        return collect($this->dashboard['systems'])->whereNotIn('state', ['ok', 'not_connected'])->values()->all();
    }

    /**
     * @return array<int, string>
     */
    private function offTarget(): array
    {
        return collect($this->dashboard['sections'])
            ->flatMap(fn (array $section) => collect($section['metrics'])
                ->whereIn('status', [TargetStatus::BEHIND, TargetStatus::AT_RISK])
                ->map(fn (array $metric) => sprintf(
                    '%s — %s: %s (%s; target %s)',
                    $section['label'],
                    $metric['label'],
                    MetricFormat::value($metric['value'], $metric['unit']),
                    TargetStatus::LABELS[$metric['status']],
                    MetricFormat::value($metric['target']['value'], $metric['unit']),
                )))
            ->values()->all();
    }
}
