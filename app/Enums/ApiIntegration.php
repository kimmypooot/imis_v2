<?php

namespace App\Enums;

/**
 * The connected systems that call IMIS's server-to-server APIs with a
 * bearer token. Each one's token can still come from its `.env` setting
 * (the config key below); tokens issued on the Integration Tokens page
 * work alongside it.
 */
enum ApiIntegration: string
{
    case Recruitment = 'recruitment';
    case Jportal = 'jportal';
    case IctSrts = 'ictsrts';
    case Etts = 'etts';
    case Ipcrs = 'ipcrs';
    case Bmis = 'bmis';
    case Proctad = 'proctad';
    case Cares = 'cares';
    case Tims = 'tims';

    public function label(): string
    {
        return match ($this) {
            self::Recruitment => 'Recruitment Portal',
            self::Jportal => 'JPortal',
            self::IctSrts => 'ICT SRTS',
            self::Etts => 'ETTS',
            self::Ipcrs => 'IPCRS',
            self::Bmis => 'BMIS',
            self::Proctad => 'PROCTAD',
            self::Cares => 'CARES',
            self::Tims => 'TIMS',
        };
    }

    /**
     * Where the legacy single shared secret lives.
     */
    public function configKey(): string
    {
        return "{$this->value}.api_token";
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
