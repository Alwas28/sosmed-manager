<?php

namespace App\Services\AI\Drivers;

use App\Models\AiSetting;
use App\Services\AI\AiException;
use App\Services\AI\Contracts\AiDriver;
use Illuminate\Support\Facades\Http;

/**
 * Works with OpenAI and any OpenAI-compatible endpoint
 * (OpenRouter, Groq, Together, DeepSeek, Ollama, LM Studio, …).
 */
class OpenAiDriver implements AiDriver
{
    public function chat(AiSetting $settings, string $systemPrompt, string $userPrompt): string
    {
        $response = Http::baseUrl($settings->effectiveBaseUrl())
            ->withToken((string) $settings->api_key)
            ->acceptJson()
            ->timeout(45)
            ->post('/chat/completions', [
                'model' => $settings->model,
                'max_tokens' => $settings->max_tokens,
                'temperature' => $settings->temperature,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ]);

        if ($response->failed()) {
            throw new AiException($this->error($response->json(), $response->status()));
        }

        $text = trim((string) $response->json('choices.0.message.content'));

        if ($text === '') {
            throw new AiException('AI mengembalikan respons kosong.');
        }

        return $text;
    }

    private function error(mixed $body, int $status): string
    {
        $message = is_array($body)
            ? ($body['error']['message'] ?? $body['message'] ?? null)
            : null;

        return 'AI gagal ('.$status.'): '.($message ?: 'kesalahan tidak diketahui');
    }
}
