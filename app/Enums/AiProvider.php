<?php

namespace App\Enums;

enum AiProvider: string
{
    case Anthropic = 'anthropic';
    case OpenAi = 'openai';
    case Gemini = 'gemini';
    case OpenAiCompatible = 'openai_compatible';

    public function label(): string
    {
        return match ($this) {
            self::Anthropic => 'Anthropic (Claude)',
            self::OpenAi => 'OpenAI',
            self::Gemini => 'Google Gemini',
            self::OpenAiCompatible => 'OpenAI-compatible (OpenRouter, Groq, Ollama, dll.)',
        };
    }

    public function defaultBaseUrl(): string
    {
        return match ($this) {
            self::Anthropic => 'https://api.anthropic.com/v1',
            self::OpenAi => 'https://api.openai.com/v1',
            self::Gemini => 'https://generativelanguage.googleapis.com/v1beta',
            self::OpenAiCompatible => 'https://openrouter.ai/api/v1',
        };
    }

    /** @return array<int, string> suggested model IDs for the datalist */
    public function suggestedModels(): array
    {
        return match ($this) {
            self::Anthropic => ['claude-opus-5', 'claude-sonnet-5', 'claude-haiku-4-5', 'claude-fable-5-1'],
            self::OpenAi => ['gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini', 'o4-mini'],
            self::Gemini => ['gemini-2.0-flash', 'gemini-1.5-flash', 'gemini-1.5-pro'],
            self::OpenAiCompatible => ['openai/gpt-4o-mini', 'meta-llama/llama-3.1-8b-instruct', 'llama3.1', 'qwen2.5'],
        };
    }

    public function needsBaseUrl(): bool
    {
        return $this === self::OpenAiCompatible;
    }
}
