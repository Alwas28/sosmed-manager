<?php

namespace App\Enums;

enum ContentCategory: string
{
    case Berita = 'berita';
    case Pengumuman = 'pengumuman';
    case Iklan = 'iklan';
    case Kegiatan = 'kegiatan';
    case Edukasi = 'edukasi';
    case Prestasi = 'prestasi';
    case Lowongan = 'lowongan';
    case Umum = 'umum';

    public function label(): string
    {
        return match ($this) {
            self::Berita => 'Berita',
            self::Pengumuman => 'Pengumuman',
            self::Iklan => 'Iklan / Promosi',
            self::Kegiatan => 'Kegiatan / Event',
            self::Edukasi => 'Edukasi / Tips',
            self::Prestasi => 'Prestasi',
            self::Lowongan => 'Lowongan',
            self::Umum => 'Umum',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Berita => 'fa-solid fa-newspaper',
            self::Pengumuman => 'fa-solid fa-bullhorn',
            self::Iklan => 'fa-solid fa-tags',
            self::Kegiatan => 'fa-solid fa-calendar-day',
            self::Edukasi => 'fa-solid fa-lightbulb',
            self::Prestasi => 'fa-solid fa-trophy',
            self::Lowongan => 'fa-solid fa-briefcase',
            self::Umum => 'fa-solid fa-hashtag',
        };
    }

    /** Instruction injected into the AI prompt so the tone/format fits the category. */
    public function aiGuidance(): string
    {
        return match ($this) {
            self::Berita => 'Kategori konten: BERITA. Tulis dengan gaya jurnalistik yang faktual dan lugas, cantumkan unsur 5W1H bila relevan, hindari bahasa promosi.',
            self::Pengumuman => 'Kategori konten: PENGUMUMAN RESMI. Sampaikan informasi dengan jelas dan ringkas: apa, untuk siapa, kapan/tenggat, dan langkah yang perlu dilakukan pembaca.',
            self::Iklan => 'Kategori konten: IKLAN / PROMOSI. Tulis persuasif: tonjolkan keunggulan dan penawaran, sertakan ajakan bertindak (CTA) yang jelas dan kesan mendesak yang wajar.',
            self::Kegiatan => 'Kategori konten: KEGIATAN / EVENT. Ajak audiens hadir atau ikut serta: sebutkan nama acara, waktu, tempat, dan cara berpartisipasi.',
            self::Edukasi => 'Kategori konten: EDUKASI / TIPS. Berikan informasi atau tips yang praktis, akurat, dan mudah dipahami; boleh memakai poin singkat.',
            self::Prestasi => 'Kategori konten: PRESTASI. Sampaikan dengan nada bangga namun santun; sebutkan pencapaian, pihak yang berjasa, dan dampaknya.',
            self::Lowongan => 'Kategori konten: LOWONGAN. Cantumkan posisi, kualifikasi utama, dan cara melamar; gunakan nada profesional.',
            self::Umum => 'Kategori konten: UMUM.',
        };
    }
}
