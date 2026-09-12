<?php

namespace App\Services\AI;

use App\Enums\AiProvider;
use App\Enums\ContentCategory;
use App\Enums\Platform;
use App\Models\AiSetting;
use App\Services\AI\Contracts\AiDriver;
use App\Services\AI\Drivers\AnthropicDriver;
use App\Services\AI\Drivers\GeminiDriver;
use App\Services\AI\Drivers\OpenAiDriver;

/**
 * Entry point for the optional AI writing assistant (Blueprint §17 Phase 5 —
 * a support feature, not an agent). Provider + model are configured in
 * Pengaturan → Integrasi AI and stored in `ai_settings`.
 */
class AiService
{
    public function settings(): AiSetting
    {
        return AiSetting::current();
    }

    public function enabled(): bool
    {
        $settings = $this->settings();

        return $settings->enabled && $settings->isConfigured();
    }

    /**
     * Low-level single-turn completion. Throws AiException on any failure.
     */
    public function complete(string $systemPrompt, string $userPrompt): string
    {
        $settings = $this->settings();

        if (! $settings->enabled) {
            throw new AiException('Asisten AI dinonaktifkan. Aktifkan di Pengaturan → Integrasi AI.');
        }

        if (! $settings->isConfigured()) {
            throw new AiException('Integrasi AI belum lengkap. Isi provider, model, dan API key.');
        }

        return $this->driver($settings->provider)->chat($settings, $systemPrompt, $userPrompt);
    }

    /**
     * Generate both a title and a caption from a free-text brief — used by the
     * "Buat dengan AI" modal when there is nothing to work from yet.
     *
     * @return array{title: string, caption: string}
     */
    public function draftFromBrief(string $brief, array $platforms, ?ContentCategory $category = null): array
    {
        $raw = $this->complete(
            $this->systemPrompt().' '.($category?->aiGuidance() ?? '')
                .' Berdasarkan brief pengguna, buat draf konten social media dalam Bahasa Indonesia. '
                ."Balas PERSIS dengan format berikut, tanpa penjelasan lain:\n"
                ."JUDUL: <judul singkat, maksimal 12 kata>\n"
                .'CAPTION: <caption 2-4 kalimat, ada ajakan bertindak, 3-5 hashtag relevan di baris terakhir>',
            "Brief: {$brief}\nPlatform tujuan: ".$this->platformList($platforms),
        );

        return $this->parseDraft($raw);
    }

    public function suggestTitle(string $topic, array $platforms, ?string $caption): string
    {
        $context = trim($topic) !== '' ? "Topik/judul saat ini: {$topic}" : 'Belum ada judul.';
        if (trim((string) $caption) !== '') {
            $context .= "\nCaption saat ini: ".trim($caption);
        }
        $context .= "\nPlatform: ".$this->platformList($platforms);

        return $this->clean($this->complete(
            $this->systemPrompt().' Buat SATU judul konten social media yang singkat, menarik, maksimal 12 kata. Balas hanya judulnya tanpa tanda kutip.',
            $context,
        ));
    }

    public function suggestCaption(string $topic, array $platforms, ?string $existing): string
    {
        $context = 'Topik: '.(trim($topic) !== '' ? $topic : 'umum');
        if (trim((string) $existing) !== '') {
            $context .= "\nDraf caption yang sudah ada (kembangkan): ".trim($existing);
        }
        $context .= "\nPlatform tujuan: ".$this->platformList($platforms);

        return $this->complete(
            $this->systemPrompt().' Tulis caption social media dalam Bahasa Indonesia: 2–4 kalimat, ada ajakan bertindak, dan 3–5 hashtag relevan di baris terakhir.',
            $context,
        );
    }

    public function improveCaption(string $current, array $platforms, ?ContentCategory $category = null): string
    {
        return $this->complete(
            $this->systemPrompt().' '.($category?->aiGuidance() ?? '')
                .' Perbaiki caption berikut agar lebih jelas, menarik, dan enak dibaca. Pertahankan maksudnya. Balas hanya caption hasil perbaikan.',
            'Platform: '.$this->platformList($platforms)."\n\nCaption:\n".$current,
        );
    }

    private function driver(AiProvider $provider): AiDriver
    {
        return match ($provider) {
            AiProvider::Anthropic => app(AnthropicDriver::class),
            AiProvider::Gemini => app(GeminiDriver::class),
            AiProvider::OpenAi, AiProvider::OpenAiCompatible => app(OpenAiDriver::class),
        };
    }

    private function systemPrompt(): string
    {
        $custom = trim((string) $this->settings()->system_prompt);
        $base = 'Kamu asisten penulis konten social media untuk sebuah instansi. Tulis dalam Bahasa Indonesia yang rapi dan sopan.';

        return $custom !== '' ? $base.' '.$custom : $base;
    }

    private function platformList(array $platforms): string
    {
        $labels = array_map(fn ($p) => Platform::tryLabel((string) $p), $platforms);

        return $labels === [] ? 'umum' : implode(', ', $labels);
    }

    private function clean(string $text): string
    {
        return trim($text, " \t\n\r\0\x0B\"'");
    }

    /**
     * @return array{title: string, caption: string}
     */
    private function parseDraft(string $raw): array
    {
        $title = '';
        $caption = '';

        if (preg_match('/JUDUL\s*[:\-]\s*(.+)/i', $raw, $m)) {
            $title = trim(strtok($m[1], "\n"));
        }
        if (preg_match('/CAPTION\s*[:\-]\s*(.+)/is', $raw, $m)) {
            $caption = trim($m[1]);
        }

        // Fallback: first line is the title, the rest is the caption.
        if ($title === '' && $caption === '') {
            $parts = preg_split('/\r?\n/', trim($raw), 2);
            $title = trim($parts[0] ?? '');
            $caption = trim($parts[1] ?? '');
        }

        if ($title === '') {
            throw new AiException('AI tidak mengembalikan judul yang bisa dibaca. Coba lagi dengan brief yang lebih jelas.');
        }

        return ['title' => $this->clean($title), 'caption' => $caption];
    }
}
