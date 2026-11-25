<?php

namespace App\Support\HR\Saln;

use App\Enums\SalnOathMode;
use App\Enums\SalnOwner;
use App\Enums\SalnStatus;
use App\Enums\SalnType;
use App\Models\Saln;
use App\Models\SalnEvent;
use App\Models\SalnVersion;
use App\Support\HR\EmployeeDocuments;
use App\Support\Settings\Settings;

/**
 * How SALNs look on the employee's and HRD's pages: a summary row, the
 * editor's props, the print page's props and the timeline. Never more of
 * the statement than the page needs.
 */
class SalnPresenter
{
    public function __construct(private readonly SalnFilings $filings) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(Saln $saln): array
    {
        $saln->loadMissing('employee.user.organizationalUnit');
        $user = $saln->employee->user;

        return [
            'id' => $saln->id,
            'reference' => $saln->reference(),
            'type' => $saln->type->value,
            'type_label' => $saln->type->label(),
            'reckoning_date' => $saln->reckoning_date->toDateString(),
            'status' => $saln->status->value,
            'status_label' => $saln->status->label(),
            'current_version' => $saln->current_version,
            'received_version' => $saln->received_version,
            'employee' => [
                'id' => $saln->employee_id,
                'name' => $user?->name ?? 'Employee #'.$saln->employee_id,
                'position' => $user?->position,
                'unit' => $user?->organizationalUnit?->short_name ?: $user?->organizationalUnit?->name,
                'photo_url' => $user?->profile_photo_url,
            ],
            'document_id' => $saln->employee_document_id,
            'updated_at' => $saln->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Everything the editor and the review page show.
     *
     * @return array<string, mixed>
     */
    public function detail(Saln $saln): array
    {
        $saln->loadMissing('events.user:id,name', 'versions');

        // Once HRD has the hard copy, show exactly what was printed on it.
        $received = $saln->received_version && ! $saln->status->isEditable()
            ? $saln->versions->firstWhere('version', $saln->received_version)
            : null;

        return [
            ...$this->summary($saln),
            'statement' => $received?->statement ?? $saln->statement,
            'oath_mode' => ($received?->oath_mode ?? $saln->oath_mode)->value,
            'officer_name' => $received ? $received->officer_name : $saln->officer_name,
            'officer_title' => $received ? $received->officer_title : $saln->officer_title,
            'editable' => $saln->status->isEditable(),
            'swears_before_self' => $this->filings->headsTheOrd($saln->employee),
            'versions' => $saln->versions->sortByDesc('version')->values()->map(fn (SalnVersion $version) => [
                'version' => $version->version,
                'printed_reference' => $version->printedReference(),
                'created_at' => $version->created_at?->toIso8601String(),
                'net_worth' => $version->statement['totals']['net_worth'] ?? null,
            ])->all(),
            'events' => $saln->events->sortByDesc('id')->values()->map(fn (SalnEvent $event) => [
                'action' => $event->action,
                'version' => $event->version,
                'remarks' => $event->remarks,
                'override' => $event->override,
                'by' => $event->user?->name,
                'at' => $event->created_at?->toIso8601String(),
            ])->all(),
            'returned_remarks' => $saln->status === SalnStatus::Returned ? $saln->events->sortByDesc('id')->firstWhere('action', 'returned')?->remarks : null,
        ];
    }

    /**
     * The print page's props for one version.
     *
     * @return array<string, mixed>
     */
    public function print(Saln $saln, SalnVersion $version, ?string $backUrl): array
    {
        $layout = SalnSheets::layout($version->statement);

        return [
            'saln' => ['type' => $saln->type->value, 'reckoning_date' => $saln->reckoning_date->toDateString()],
            'version' => [
                'reference' => $version->reference,
                'version' => $version->version,
                'statement' => $version->statement,
                'oath_mode' => $version->oath_mode->value,
                'officer_name' => $version->officer_name,
                'officer_title' => $version->officer_title,
            ],
            'layout' => $layout,
            'pageCount' => SalnSheets::pageCount($layout),
            'backUrl' => $backUrl,
        ];
    }

    /**
     * The editor's fixed choices.
     *
     * @return array<string, mixed>
     */
    public static function options(): array
    {
        return [
            'types' => collect(SalnType::cases())->map(fn (SalnType $type) => ['value' => $type->value, 'label' => $type->label()])->all(),
            'oathModes' => collect(SalnOathMode::cases())->map(fn (SalnOathMode $mode) => ['value' => $mode->value, 'label' => $mode->label()])->all(),
            'owners' => [
                ['value' => SalnOwner::Declarant->value, 'label' => 'Mine (or ours, conjugal)'],
                ['value' => SalnOwner::Spouse->value, 'label' => "Spouse's exclusive (AS-2)"],
                ['value' => SalnOwner::Child->value, 'label' => "A child's (AS-2)"],
            ],
            'multipleMarriages' => (bool) Settings::get('saln_multiple_marriages'),
            'maxKilobytes' => EmployeeDocuments::MAX_KILOBYTES,
        ];
    }
}
