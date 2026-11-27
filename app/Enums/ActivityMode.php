<?php

namespace App\Enums;

/**
 * Calendar of Activities: how an activity is held. The labels are the
 * text the monthly sheet prints.
 */
enum ActivityMode: string
{
    case FaceToFace = 'face_to_face';
    case Hybrid = 'hybrid';
    case Online = 'online';

    public function label(): string
    {
        return match ($this) {
            self::FaceToFace => 'Face-to-face',
            self::Hybrid => 'Hybrid',
            self::Online => 'Online',
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
