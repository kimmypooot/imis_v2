<?php

namespace App\Enums;

/**
 * How an L&D intervention was delivered (ldms's modality list).
 */
enum LdModality: string
{
    case FaceToFace = 'face_to_face';
    case Online = 'online';
    case Blended = 'blended';
    case SelfPaced = 'self_paced';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::FaceToFace => 'Face-to-face',
            self::Online => 'Online',
            self::Blended => 'Blended',
            self::SelfPaced => 'Self-paced',
            self::Other => 'Other',
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
