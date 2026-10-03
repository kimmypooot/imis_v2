<?php

namespace App\Enums;

/**
 * Nature of an employee's appointment (CSC appointment status). Job Order
 * and Contract of Service workers are not government employees and hold
 * no plantilla item, but HR still keeps their records.
 */
enum EmploymentStatus: string
{
    case Permanent = 'permanent';
    case Temporary = 'temporary';
    case Coterminous = 'coterminous';
    case Casual = 'casual';
    case Contractual = 'contractual';
    case JobOrder = 'job_order';
    case ContractOfService = 'contract_of_service';

    public function label(): string
    {
        return match ($this) {
            self::Permanent => 'Permanent',
            self::Temporary => 'Temporary',
            self::Coterminous => 'Coterminous',
            self::Casual => 'Casual',
            self::Contractual => 'Contractual',
            self::JobOrder => 'Job Order',
            self::ContractOfService => 'Contract of Service',
        };
    }

    /**
     * HR-4: the statuses that occupy a plantilla item.
     *
     * @return array<int, self>
     */
    public static function onPlantilla(): array
    {
        return [self::Permanent, self::Temporary, self::Coterminous];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function plantillaOptions(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::onPlantilla());
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
