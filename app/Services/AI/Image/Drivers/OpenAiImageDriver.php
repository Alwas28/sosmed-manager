<?php

namespace App\Services\AI\Image\Drivers;

use App\Models\ImageAiProfile;
use App\Services\AI\AiException;
use App\Services\AI\Image\ImageDriver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI Images API — generations (text-to-image) and edits (image-to-image).
 * Works with gpt-image-1 and the dall-e-* models.
 */
class OpenAiImageDriver implements ImageDriver
{
    public function generate(ImageAiProfile $profile, string $prompt): array
    {
        $payload = [
            'model' => $profile->model,
            'prompt' => $prompt,
            'n' => 1,
        ];

        if ($profile->size) {
            $payload['size'] = $profile->size;
        }

        // dall-e-* defaults to returning a URL; gpt-image-1 always returns
        // b64_json and rejects an explicit response_format.
        if (str_starts_with($profile->model, 'dall-e')) {
            $payload['response_format'] = 'b64_json';
        }

        $response = Http::baseUrl($profile->effectiveBaseUrl())
            ->withToken((string) $profile->api_key)
            ->acceptJson()
            ->timeout(120)
            ->post('/images/generations', $payload);

        return $this->extract($response);
    }

    public function edit(ImageAiProfile $profile, string $imageBytes, string $imageMime, string $instruction): array
    {
        $response = Http::baseUrl($profile->effectiveBaseUrl())
            ->withToken((string) $profile->api_key)
            ->acceptJson()
            ->timeout(120)
            ->attach('image', $imageBytes, 'image.png', ['Content-Type' => $imageMime])
            ->post('/images/edits', [
                'model' => $profile->model,
                'prompt' => $instruction,
            ]);

        return $this->extract($response);
    }

    /** @return array{bytes: string, mime: string} */
    private function extract(Response $response): array
    {
        if ($response->failed()) {
            throw new AiException(
                'Generate gambar gagal ('.$response->status().'): '
                .($response->json('error.message') ?? $response->body())
            );
        }

        if ($b64 = $response->json('data.0.b64_json')) {
            return ['bytes' => base64_decode($b64), 'mime' => 'image/png'];
        }

        if ($url = $response->json('data.0.url')) {
            $image = Http::timeout(60)->get($url);

            if ($image->failed()) {
                throw new AiException('Gambar berhasil dibuat tetapi gagal diunduh dari OpenAI.');
            }

            return ['bytes' => $image->body(), 'mime' => $image->header('Content-Type') ?: 'image/png'];
        }

        throw new AiException('AI tidak mengembalikan gambar.');
    }
}
