<?php

namespace App\Services\AI\Image;

use App\Models\ImageAiProfile;

interface ImageDriver
{
    /** @return array{bytes: string, mime: string} */
    public function generate(ImageAiProfile $profile, string $prompt): array;

    /** @return array{bytes: string, mime: string} */
    public function edit(ImageAiProfile $profile, string $imageBytes, string $imageMime, string $instruction): array;
}
