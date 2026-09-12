<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialChannel extends Model
{
    protected $fillable = [
        'buffer_connection_id',
        'buffer_profile_id',
        'service',
        'service_type',
        'username',
        'display_name',
        'avatar',
        'timezone',
        'is_active',
        'meta',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'meta' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(BufferConnection::class, 'buffer_connection_id');
    }

    public function iconClass(): string
    {
        return match ($this->service) {
            'facebook' => 'fa-brands fa-facebook',
            'instagram' => 'fa-brands fa-instagram',
            'twitter', 'x' => 'fa-brands fa-x-twitter',
            'linkedin' => 'fa-brands fa-linkedin',
            'tiktok' => 'fa-brands fa-tiktok',
            'pinterest' => 'fa-brands fa-pinterest',
            'youtube' => 'fa-brands fa-youtube',
            'threads' => 'fa-brands fa-threads',
            'mastodon' => 'fa-brands fa-mastodon',
            'googlebusiness', 'google' => 'fa-brands fa-google',
            default => 'fa-solid fa-share-nodes',
        };
    }

    public function serviceLabel(): string
    {
        return match ($this->service) {
            'twitter', 'x' => 'X / Twitter',
            'googlebusiness' => 'Google Business',
            'unknown' => 'Lainnya',
            default => ucfirst($this->service),
        };
    }
}
