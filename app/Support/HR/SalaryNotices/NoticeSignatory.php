<?php

namespace App\Support\HR\SalaryNotices;

use App\Support\HR\CertifyingOfficer;
use Illuminate\Validation\ValidationException;

/**
 * Who signs salary notices: the Regional Director (the Director IV), or
 * the Director III as the alternate. The name is whoever holds that
 * plantilla item now. The notice prints the name and position over a
 * blank signature space; it is signed by hand or with a PNPKI digital
 * signature outside IMIS.
 */
class NoticeSignatory
{
    public const POSITIONS = [
        'director_iv' => ['position' => 'Director IV', 'label' => 'Regional Director (Director IV)'],
        'director_iii' => ['position' => 'Director III', 'label' => 'Alternate (Director III)'],
    ];

    public function __construct(private readonly CertifyingOfficer $officers) {}

    /**
     * @return array<int, array{value: string, label: string, position: string, name: ?string}>
     */
    public function options(): array
    {
        return collect(self::POSITIONS)->map(fn (array $signatory, string $key) => [
            'value' => $key,
            'label' => $signatory['label'],
            'position' => $signatory['position'],
            'name' => $this->officers->holderOf($signatory['position']),
        ])->values()->all();
    }

    /**
     * @return array{name: string, position: string}
     */
    public function resolve(string $key): array
    {
        $position = self::POSITIONS[$key]['position'] ?? null;
        $name = $position ? $this->officers->holderOf($position) : null;

        if ($name === null) {
            throw ValidationException::withMessages(['signatory' => "Nobody holds the {$position} item on the Plantilla now, so the notice has no signatory. Record the appointment first, or choose the other signatory."]);
        }

        return ['name' => $name, 'position' => $position];
    }
}
