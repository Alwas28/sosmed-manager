<?php

namespace App\Services\AI\Contracts;

use App\Models\AiSetting;

interface AiDriver
{
    /**
     * Send a single-turn chat completion and return the assistant's text.
     */
    public function chat(AiSetting $settings, string $systemPrompt, string $userPrompt): string;
}
