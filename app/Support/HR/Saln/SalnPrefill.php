<?php

namespace App\Support\HR\Saln;

use App\Enums\SalnType;
use App\Models\Employee;
use App\Models\Saln;
use App\Support\HR\Pds\PdsReader;
use Carbon\CarbonImmutable;

/**
 * Where a new SALN starts: what IMIS already knows (the account, the
 * office, the PDS family background), or the employee's last SALN with
 * the children re-checked for the new reckoning date. The employee
 * reviews and edits everything before printing.
 */
class SalnPrefill
{
    /** Short enough for the form's AGENCY/OFFICE blank, with the office's short name. */
    public const AGENCY = 'Civil Service Commission RO VIII';

    public function __construct(private readonly PdsReader $reader) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Employee $employee, SalnType $type, CarbonImmutable $asOf): array
    {
        $employee->loadMissing('user.organizationalUnit');
        $pds = $this->reader->read($employee);
        $identity = $pds['identity'];
        $family = $pds['sections']['family'] ?? [];
        $office = $employee->user?->organizationalUnit;

        $statement = SalnStatement::blank();
        $statement['declarant'] = [
            'family_name' => $identity['surname'],
            'first_name' => trim($identity['first_name'].' '.($identity['name_extension'] ?? '')),
            'middle_initial' => self::initial($identity['middle_name'] ?? null),
            'position' => $employee->user?->position,
            'agency' => $office ? self::AGENCY.' — '.($office->short_name ?: $office->name) : self::AGENCY,
            'office_address' => $office?->address,
        ];

        if (filled($family['spouse_surname'] ?? null)) {
            $statement['spouse'] = [
                'family_name' => $family['spouse_surname'],
                'first_name' => trim(($family['spouse_first_name'] ?? '').' '.($family['spouse_name_extension'] ?? '')),
                'middle_initial' => self::initial($family['spouse_middle_name'] ?? null),
                'in_government' => false,
                'position' => null,
                'agency' => null,
                'office_address' => null,
            ];
        }

        $statement['children'] = SalnStatement::childrenAsOf(
            collect($pds['sections']['children'] ?? [])
                ->map(fn (array $child) => ['name' => $child['full_name'] ?? '', 'birth_date' => self::date($child['date_of_birth'] ?? null)])
                ->all(),
            $asOf,
        );

        return $statement;
    }

    /**
     * @return array<string, mixed>
     */
    public function fromPrevious(Saln $previous, SalnType $type, CarbonImmutable $asOf): array
    {
        $statement = array_replace(SalnStatement::blank(), $previous->latestVersion()?->statement ?? $previous->statement ?? []);

        unset($statement['totals']);
        $statement['government_id'] = SalnStatement::blank()['government_id'];
        $statement['children'] = SalnStatement::childrenAsOf($statement['children'], $asOf);

        return $statement;
    }

    private static function initial(?string $middleName): ?string
    {
        $middleName = trim((string) $middleName);

        return $middleName === '' ? null : mb_strtoupper(mb_substr($middleName, 0, 1)).'.';
    }

    private static function date(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
    }
}
