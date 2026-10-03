<?php

namespace App\Enums;

/**
 * HR-4: why a period of service on a plantilla item began — the CSC
 * natures of appointment, plus a step increment (same item and status, a
 * higher step: a new Service Record line, not a new appointment).
 */
enum AppointmentNature: string
{
    case Original = 'original';
    case Promotion = 'promotion';
    case Transfer = 'transfer';
    case Reemployment = 'reemployment';
    case Reappointment = 'reappointment';
    case Reclassification = 'reclassification';
    case Demotion = 'demotion';
    case StepIncrement = 'step_increment';

    public function label(): string
    {
        return match ($this) {
            self::StepIncrement => 'Step increment',
            default => ucfirst($this->value),
        };
    }

    /**
     * Natures HR picks when appointing someone (a step increment has its
     * own action).
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function appointmentOptions(): array
    {
        return array_values(array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            array_filter(self::cases(), fn (self $case) => $case !== self::StepIncrement),
        ));
    }
}
