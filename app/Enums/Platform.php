<?php

namespace App\Enums;

enum Platform: string
{
    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case Twitter = 'twitter';
    case Linkedin = 'linkedin';
    case Tiktok = 'tiktok';
    case Threads = 'threads';

    public function label(): string
    {
        return match ($this) {
            self::Facebook => 'Facebook',
            self::Instagram => 'Instagram',
            self::Twitter => 'X / Twitter',
            self::Linkedin => 'LinkedIn',
            self::Tiktok => 'TikTok',
            self::Threads => 'Threads',
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
            self::Threads => 'fa-brands fa-threads',
        };
    }

    /** @return array<int, PostType> jenis postingan yang bisa dipilih untuk platform ini */
    public function postTypes(): array
    {
        return match ($this) {
            self::Instagram, self::Facebook => [PostType::Post, PostType::Reel, PostType::Story],
            default => [PostType::Post],
        };
    }

    public function defaultPostType(): PostType
    {
        return PostType::Post;
    }

    /** Slug izin role untuk memposting dengan jenis tertentu, mis. "post.instagram.story". */
    public function postPermission(PostType $type): string
    {
        return "post.{$this->value}.{$type->value}";
    }

    /** @return array<string, string> slug => nama izin, untuk semua jenis postingan platform ini */
    public function postPermissions(): array
    {
        $names = [];

        foreach ($this->postTypes() as $type) {
            $names[$this->postPermission($type)] = 'Posting '.$this->label().' — '.$type->label($this);
        }

        return $names;
    }

    /** Platform ini menolak postingan tanpa media sama sekali. */
    public function requiresMedia(): bool
    {
        return in_array($this, [self::Instagram, self::Tiktok], true);
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
