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
        $details = [
            ...$this->figures(),
            ...collect($this->systemsNeedingAttention())->mapWithKeys(fn (array $system) => [$system['fullName'] => $system['detail']])->all(),
            ...$this->offTarget(),
        ];

        return EmailTemplates::mail('weekly_summary', [
            'first_name' => $notifiable->first_name ?: $notifiable->name,
            'name' => $notifiable->name,
        ], url('/executive'), after: [$this->headline()], details: $details);
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
     * @return array<string, string>
     */
    private function figures(): array
    {
        $summary = $this->dashboard['summary'];
        $figures = ['Systems needing attention' => "{$summary['systemsNeedingAttention']} of {$summary['systems']}"];

        if ($summary['withTargets'] > 0) {
            $figures['Targets on track'] = "{$summary['onTrack']} of {$summary['withTargets']}";
            $figures['At risk / behind'] = "{$summary['atRisk']} / {$summary['behind']}";
        }

        return $figures;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function systemsNeedingAttention(): array
    {
        return collect($this->dashboard['systems'])->whereNotIn('state', ['ok', 'not_connected'])->values()->all();
    }

    /**
     * @return array<string, string> "Section — metric" → "value (status; target …)"
     */
    private function offTarget(): array
    {
        return collect($this->dashboard['sections'])
            ->flatMap(fn (array $section) => collect($section['metrics'])
                ->whereIn('status', [TargetStatus::BEHIND, TargetStatus::AT_RISK])
                ->mapWithKeys(fn (array $metric) => [$section['label'].' — '.$metric['label'] => sprintf(
                    '%s (%s; target %s)',
                    MetricFormat::value($metric['value'], $metric['unit']),
                    TargetStatus::LABELS[$metric['status']],
                    MetricFormat::value($metric['target']['value'], $metric['unit']),
                )]))
            ->all();
    }
}
