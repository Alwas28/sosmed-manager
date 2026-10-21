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

    /**
     * The actual text sent to Buffer for a link post: caption + the URL
     * appended (unless already present). Used both when building the real
     * Buffer payload (BufferPostService) and when validating length limits
     * (e.g. Threads' 500-character cap includes the link itself) — single
     * source of truth so the two never drift apart.
     */
    public static function composeLinkText(?string $text, ?string $url): string
    {
        if (! $url) {
            return (string) $text;
        }

        $text = trim((string) $text);

        if ($text === '') {
            return $url;
        }

        return str_contains($text, $url) ? $text : "{$text}\n\n{$url}";
    }
}
