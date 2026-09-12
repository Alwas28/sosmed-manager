<?php

namespace App\Services\AI\Image\Drivers;

use App\Models\ImageAiProfile;
use App\Services\AI\AiException;
use App\Services\AI\Image\ImageDriver;
use Illuminate\Support\Facades\Http;

/**
 * Google Gemini native image generation/editing via generateContent with
 * responseModalities: ["IMAGE"] (e.g. gemini-2.5-flash-image).
 */
class GeminiImageDriver implements ImageDriver
{
    public function generate(ImageAiProfile $profile, string $prompt): array
    {
        return $this->call($profile, [
            ['parts' => [['text' => $prompt]]],
        ]);
    }

    public function edit(ImageAiProfile $profile, string $imageBytes, string $imageMime, string $instruction): array
    {
        return $this->call($profile, [
            ['parts' => [
                ['inlineData' => ['mimeType' => $imageMime, 'data' => base64_encode($imageBytes)]],
                ['text' => $instruction],
            ]],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $contents
     * @return array{bytes: string, mime: string}
     */
    private function call(ImageAiProfile $profile, array $contents): array
    {
        $response = Http::baseUrl($profile->effectiveBaseUrl())
            ->acceptJson()
            ->timeout(120)
            ->post('/models/'.$profile->model.':generateContent?key='.urlencode((string) $profile->api_key), [
                'contents' => $contents,
                'generationConfig' => ['responseModalities' => ['TEXT', 'IMAGE']],
            ]);

        if ($response->failed()) {
            throw new AiException(
                'Generate gambar gagal ('.$response->status().'): '
                .($response->json('error.message') ?? $response->body())
            );
        }

        foreach ($response->json('candidates.0.content.parts', []) as $part) {
            if (isset($part['inlineData']['data'])) {
                return [
                    'bytes' => base64_decode($part['inlineData']['data']),
                    'mime' => $part['inlineData']['mimeType'] ?? 'image/png',
                ];
            }
        }

        throw new AiException('AI tidak mengembalikan gambar. Pastikan model yang dipilih mendukung output gambar.');
    }
}
