<?php

namespace App\Services\AI\Image;

use App\Enums\ImageAiProvider;
use App\Models\ImageAiProfile;
use App\Services\AI\AiException;
use App\Services\AI\Image\Drivers\GeminiImageDriver;
use App\Services\AI\Image\Drivers\OpenAiImageDriver;
use Illuminate\Database\Eloquent\Collection;

/**
 * Two ways to get an image (Blueprint Phase 5 — AI support features):
 *  - generate(): pure text-to-image.
 *  - edit(): take an uploaded image + instruction and let the model add
 *    text/captions or otherwise modify it.
 *
 * Multiple ImageAiProfile rows can be configured so the user can run the
 * same prompt through different providers/models and compare results.
 */
class ImageGenerationService
{
    public function activeProfiles(): Collection
    {
        return ImageAiProfile::where('is_active', true)->orderBy('label')->get();
    }

    /** @return array{bytes: string, mime: string} */
    public function generate(ImageAiProfile $profile, string $prompt): array
    {
        $this->assertConfigured($profile);

        return $this->driver($profile->provider)->generate($profile, $prompt);
    }

    /** @return array{bytes: string, mime: string} */
    public function edit(ImageAiProfile $profile, string $imageBytes, string $imageMime, string $instruction): array
    {
        $this->assertConfigured($profile);

        return $this->driver($profile->provider)->edit($profile, $imageBytes, $imageMime, $instruction);
    }

    private function assertConfigured(ImageAiProfile $profile): void
    {
        if (! $profile->isConfigured()) {
            throw new AiException('Profil AI gambar "'.$profile->label.'" belum lengkap (model / API key).');
        }
    }

    private function driver(ImageAiProvider $provider): ImageDriver
    {
        return match ($provider) {
            ImageAiProvider::OpenAi => app(OpenAiImageDriver::class),
            ImageAiProvider::Gemini => app(GeminiImageDriver::class),
        };
    }
}
