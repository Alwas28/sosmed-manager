<?php

namespace App\Enums;

/**
 * Alur redaksi tetap (bukan konfigurasi bebas):
 * Wartawan → Editor → Publisher → Pimpinan Redaksi → terbit.
 *
 * `value` dipakai sebagai nilai kolom `contents.workflow_stage`, sedangkan
 * `roleSlug()` dipakai untuk membatasi siapa yang boleh mengambil keputusan
 * pada tahap tersebut.
 */
enum WorkflowStep: string
{
    case Writer = 'wartawan';
    case Editor = 'editor';
    case Publisher = 'publisher';
    case ChiefEditor = 'pimpinan_redaksi';

    public const AUTO_PUBLISH_MINUTES = 10;

    public function label(): string
    {
        return match ($this) {
            self::Writer => 'Wartawan',
            self::Editor => 'Editor',
            self::Publisher => 'Publisher',
            self::ChiefEditor => 'Pimpinan Redaksi',
        };
    }

    public function roleSlug(): string
    {
        return match ($this) {
            self::Writer => 'content-creator',
            self::Editor => 'reviewer',
            self::Publisher => 'publisher',
            self::ChiefEditor => 'pimpinan-redaksi',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Writer => 'Membuat dan mengajukan konten.',
            self::Editor => 'Menyunting isi konten, lalu meneruskan ke Publisher.',
            self::Publisher => 'Meninjau hasil suntingan dan dapat meminta revisi.',
            self::ChiefEditor => 'Keputusan terakhir. Tanpa respon 10 menit, konten terbit otomatis.',
        };
    }

    /** @return array<int, array<string, mixed>> */
    public static function stages(): array
    {
        return array_map(
            fn (self $step, int $index) => [
                'slug' => $step->value,
                'label' => $step->label(),
                'role_slug' => $step->roleSlug(),
                'description' => $step->description(),
                'sort_order' => $index + 1,
                'is_active' => true,
            ],
            self::cases(),
            array_keys(self::cases()),
        );
    }
}
