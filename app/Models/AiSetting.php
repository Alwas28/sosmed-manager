<?php

namespace App\Models;

use App\Enums\AiProvider;
use Illuminate\Database\Eloquent\Model;

class AiSetting extends Model
{
    protected $fillable = [
        'enabled', 'provider', 'model', 'api_key', 'base_url', 'max_tokens', 'temperature', 'system_prompt',
    ];

    protected $attributes = [
        'enabled' => false,
        'provider' => 'anthropic',
        'max_tokens' => 600,
        'temperature' => 0.70,
    ];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'provider' => AiProvider::class,
            'api_key' => 'encrypted',
            'max_tokens' => 'integer',
            'temperature' => 'float',
        ];
    }

    /** The single settings row (created on first access). */
    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }

    public function effectiveBaseUrl(): string
    {
        return rtrim($this->base_url ?: $this->provider->defaultBaseUrl(), '/');
    }

    public function isConfigured(): bool
    {
        return filled($this->model) && (filled($this->api_key) || $this->provider === AiProvider::OpenAiCompatible);
    }
}
