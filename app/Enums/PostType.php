<?php

namespace App\Enums;

/** Values match Buffer's `PostType` / `PostTypeFacebook` enum names. */
enum PostType: string
{
    case Post = 'post';
    case Reel = 'reel';
    case Story = 'story';
    case Link = 'link';

    public function icon(): string
    {
        return match ($this) {
            self::Post => 'fa-regular fa-image',
            self::Reel => 'fa-solid fa-clapperboard',
            self::Story => 'fa-solid fa-circle-notch',
            self::Link => 'fa-solid fa-link',
        };
    }

    public function label(Platform $platform): string
    {
        return match ($this) {
            self::Post => match ($platform) {
                Platform::Instagram => 'Postingan Feed',
                Platform::Facebook => 'Postingan Biasa',
                Platform::Tiktok => 'Video / Foto',
                default => 'Postingan',
            },
            self::Reel => 'Reel',
            self::Story => $platform === Platform::Instagram ? 'Instagram Story' : 'Story',
            self::Link => 'Bagikan Link',
        };
    }

    public function hint(Platform $platform): string
    {
        return match ($this) {
            self::Post => match ($platform) {
                Platform::Instagram => 'Foto/video (bisa lebih dari satu = carousel) dengan caption.',
                Platform::Facebook => 'Teks, foto, atau video di beranda halaman.',
                Platform::Tiktok => 'Video atau foto dengan caption.',
                default => 'Teks dengan atau tanpa media.',
            },
            self::Reel => 'Wajib satu video vertikal. Caption tampil di bawah video.',
            self::Story => 'Wajib satu foto/video vertikal (9:16). Caption tidak dikirim, hilang setelah 24 jam.',
            self::Link => 'Membagikan URL website sebagai kartu link, tanpa foto/video.',
        };
    }

    public function needsMedia(): bool
    {
        return in_array($this, [self::Reel, self::Story], true);
    }
}
