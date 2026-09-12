<?php

namespace App\Enums;

/** Where the logo watermark is stamped on the photo — a standard 9-point anchor grid. */
enum LogoPosition: string
{
    case TopLeft = 'top-left';
    case TopCenter = 'top-center';
    case TopRight = 'top-right';
    case MiddleLeft = 'middle-left';
    case Center = 'center';
    case MiddleRight = 'middle-right';
    case BottomLeft = 'bottom-left';
    case BottomCenter = 'bottom-center';
    case BottomRight = 'bottom-right';

    public function label(): string
    {
        return match ($this) {
            self::TopLeft => 'Kiri Atas',
            self::TopCenter => 'Tengah Atas',
            self::TopRight => 'Kanan Atas',
            self::MiddleLeft => 'Kiri Tengah',
            self::Center => 'Tengah Gambar',
            self::MiddleRight => 'Kanan Tengah',
            self::BottomLeft => 'Kiri Bawah',
            self::BottomCenter => 'Tengah Bawah',
            self::BottomRight => 'Kanan Bawah',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
