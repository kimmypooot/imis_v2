<?php

namespace App\Enums;

/**
 * Controlled set of organizational unit types. The frontend never
 * hardcodes these — every page that needs the list renders options the
 * backend passed as props (see OrganizationalUnitController).
 */
enum UnitType: string
{
    case RegionalSupportUnit = 'regional_support_unit';
    case FieldOffice = 'field_office';
    case SatelliteOffice = 'satellite_office';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::RegionalSupportUnit => 'Regional Support Unit',
            self::FieldOffice => 'Field Office',
            self::SatelliteOffice => 'Satellite Office',
            self::Other => 'Other',
        };
    }

    /**
     * Heading for a group of units, as on the dashboard directory.
     */
    public function pluralLabel(): string
    {
        return match ($this) {
            self::RegionalSupportUnit => 'Regional Support Units',
            self::FieldOffice => 'Field Offices',
            self::SatelliteOffice => 'Satellite Offices',
            self::Other => 'Other Offices',
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
