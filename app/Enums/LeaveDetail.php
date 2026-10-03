<?php

namespace App\Enums;

/**
 * HR-6b: the "Details of leave" boxes of CS Form No. 6 (6.B), by the
 * leave types they belong to. Which box applies is chosen from the
 * type's code; HR-added types without a box use "Other".
 */
enum LeaveDetail: string
{
    case WithinPhilippines = 'within_ph';
    case Abroad = 'abroad';
    case InHospital = 'in_hospital';
    case OutPatient = 'out_patient';
    case SpecialIllness = 'special_illness';
    case MastersDegree = 'masters_degree';
    case BarBoardReview = 'bar_board_review';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::WithinPhilippines => 'Within the Philippines',
            self::Abroad => 'Abroad (specify)',
            self::InHospital => 'In hospital (specify illness)',
            self::OutPatient => 'Out patient (specify illness)',
            self::SpecialIllness => 'Specify illness',
            self::MastersDegree => 'Completion of Master\'s degree',
            self::BarBoardReview => 'BAR/Board examination review',
            self::Other => 'Other (specify)',
        };
    }

    /** Whether the line beside the box must be filled in. */
    public function needsText(): bool
    {
        return in_array($this, [self::Abroad, self::InHospital, self::OutPatient, self::SpecialIllness, self::Other], true);
    }

    /**
     * The boxes for a leave type code (empty: no details asked).
     *
     * @return array<int, self>
     */
    public static function forCode(string $code): array
    {
        return match ($code) {
            'VL', 'SPL' => [self::WithinPhilippines, self::Abroad],
            'SL' => [self::InHospital, self::OutPatient],
            'SLBW' => [self::SpecialIllness],
            'STUDY' => [self::MastersDegree, self::BarBoardReview],
            default => [],
        };
    }

    /**
     * @return array<string, array<int, array{value: string, label: string, needs_text: bool}>>
     */
    public static function optionsByCode(): array
    {
        return collect(['VL', 'SPL', 'SL', 'SLBW', 'STUDY'])->mapWithKeys(fn (string $code) => [
            $code => array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label(), 'needs_text' => $case->needsText()], self::forCode($code)),
        ])->all();
    }
}
