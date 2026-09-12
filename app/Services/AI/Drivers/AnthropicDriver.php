<?php

namespace App\Services\AI\Drivers;

use App\Models\AiSetting;
use App\Services\AI\AiException;
use App\Services\AI\Contracts\AiDriver;
use Illuminate\Support\Facades\Http;

/**
 * Anthropic Messages API (https://api.anthropic.com/v1/messages).
 * Raw HTTP is used here so every provider goes through the same layer.
 */
class AnthropicDriver implements AiDriver
{
    public function chat(AiSetting $settings, string $systemPrompt, string $userPrompt): string
    {
        $response = Http::baseUrl($settings->effectiveBaseUrl())
            ->withHeaders([
                'x-api-key' => (string) $settings->api_key,
                'anthropic-version' => '2023-06-01',
            ])
            ->acceptJson()
            ->timeout(45)
            ->post('/messages', [
                'model' => $settings->model,
                'max_tokens' => $settings->max_tokens,
                'temperature' => $settings->temperature,
                'system' => $systemPrompt,
                'messages' => [
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ]);

        if ($response->failed()) {
            $message = $response->json('error.message') ?? $response->body();
            throw new AiException('AI gagal ('.$response->status().'): '.$message);
        }

        $text = collect($response->json('content', []))
            ->firstWhere('type', 'text')['text'] ?? '';

        $text = trim($text);

        if ($text === '') {
            throw new AiException('AI mengembalikan respons kosong.');
        }

        return $text;
    }
}
