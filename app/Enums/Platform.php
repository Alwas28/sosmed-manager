<?php

namespace App\Enums;

enum Platform: string
{
    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case Twitter = 'twitter';
    case Linkedin = 'linkedin';
    case Tiktok = 'tiktok';

    public function label(): string
    {
        return match ($this) {
            self::Facebook => 'Facebook',
            self::Instagram => 'Instagram',
            self::Twitter => 'X / Twitter',
            self::Linkedin => 'LinkedIn',
            self::Tiktok => 'TikTok',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Facebook => 'fa-brands fa-facebook',
            self::Instagram => 'fa-brands fa-instagram',
            self::Twitter => 'fa-brands fa-x-twitter',
            self::Linkedin => 'fa-brands fa-linkedin',
            self::Tiktok => 'fa-brands fa-tiktok',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function tryLabel(string $value): string
    {
        return self::tryFrom($value)?->label() ?? ucfirst($value);
    }

    public static function tryIcon(string $value): string
    {
        return self::tryFrom($value)?->icon() ?? 'fa-solid fa-share-nodes';
    }
}
