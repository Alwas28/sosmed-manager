<?php

namespace App\Services\AI\Drivers;

use App\Models\AiSetting;
use App\Services\AI\AiException;
use App\Services\AI\Contracts\AiDriver;
use Illuminate\Support\Facades\Http;

/**
 * Google Gemini — generativelanguage.googleapis.com/v1beta.
 */
class GeminiDriver implements AiDriver
{
    public function chat(AiSetting $settings, string $systemPrompt, string $userPrompt): string
    {
        $response = Http::baseUrl($settings->effectiveBaseUrl())
            ->acceptJson()
            ->timeout(45)
            ->post('/models/'.$settings->model.':generateContent?key='.urlencode((string) $settings->api_key), [
                'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
                'contents' => [
                    ['role' => 'user', 'parts' => [['text' => $userPrompt]]],
                ],
                'generationConfig' => [
                    'maxOutputTokens' => $settings->max_tokens,
                    'temperature' => $settings->temperature,
                ],
            ]);

        if ($response->failed()) {
            $message = $response->json('error.message') ?? $response->body();
            throw new AiException('AI gagal ('.$response->status().'): '.$message);
        }

        $text = trim((string) $response->json('candidates.0.content.parts.0.text'));

        if ($text === '') {
            throw new AiException('AI mengembalikan respons kosong.');
        }

        return $text;
    }
}
