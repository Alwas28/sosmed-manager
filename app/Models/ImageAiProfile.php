<?php

namespace App\Models;

use App\Enums\ImageAiProvider;
use Illuminate\Database\Eloquent\Model;

class ImageAiProfile extends Model
{
    protected $fillable = [
        'label', 'provider', 'model', 'api_key', 'base_url', 'size', 'is_active',
    ];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'provider' => ImageAiProvider::class,
            'api_key' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    public function effectiveBaseUrl(): string
    {
        return rtrim($this->base_url ?: $this->provider->defaultBaseUrl(), '/');
    }

    public function isConfigured(): bool
    {
        return filled($this->model) && filled($this->api_key);
    }

    /** Label used to tag media created with this profile, e.g. "openai:gpt-image-1". */
    public function tag(): string
    {
        return $this->provider->value.':'.$this->model;
    }
}
