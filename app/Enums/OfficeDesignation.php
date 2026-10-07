<?php

namespace App\Enums;

/**
 * Who heads an office for system administration: the Division Chief of a
 * Regional Support Unit, or the Field Director of a field or satellite
 * office. One per office. Holding it makes the person overall admin of the
 * office's systems (App\Support\SystemAccess\OfficeAdminAccess).
 */
enum OfficeDesignation: string
{
    case DivisionChief = 'division_chief';
    case FieldDirector = 'field_director';

    public function label(): string
    {
        return match ($this) {
            self::DivisionChief => 'Division Chief',
            self::FieldDirector => 'Field Director',
        };
    }

    /** The designation an office of this type has, if any. */
    public static function forUnitType(?UnitType $type): ?self
    {
        return match ($type) {
            UnitType::RegionalSupportUnit => self::DivisionChief,
            UnitType::FieldOffice, UnitType::SatelliteOffice => self::FieldDirector,
            default => null,
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $designation) => ['value' => $designation->value, 'label' => $designation->label()], self::cases());
    }
}
