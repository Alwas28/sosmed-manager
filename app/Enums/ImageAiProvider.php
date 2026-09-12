<?php

namespace App\Enums;

/**
 * Providers that can generate/edit images. Claude (Anthropic) is deliberately
 * absent — Claude can read images but does not generate them.
 */
enum ImageAiProvider: string
{
    case OpenAi = 'openai';
    case Gemini = 'gemini';

    public function label(): string
    {
        return match ($this) {
            self::OpenAi => 'OpenAI (DALL·E / gpt-image-1)',
            self::Gemini => 'Google Gemini (Imagen)',
        };
    }

    public function defaultBaseUrl(): string
    {
        return match ($this) {
            self::OpenAi => 'https://api.openai.com/v1',
            self::Gemini => 'https://generativelanguage.googleapis.com/v1beta',
        };
    }

    /** @return array<int, string> suggested model IDs for the datalist */
    public function suggestedModels(): array
    {
        return match ($this) {
            self::OpenAi => ['gpt-image-1', 'dall-e-3', 'dall-e-2'],
            self::Gemini => ['gemini-2.5-flash-image', 'gemini-2.0-flash-preview-image-generation'],
        };
    }

    public function supportsSize(): bool
    {
        return $this === self::OpenAi;
    }
}
