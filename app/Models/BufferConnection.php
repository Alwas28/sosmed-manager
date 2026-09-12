<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BufferConnection extends Model
{
    protected $fillable = [
        'buffer_user_id',
        'organization_id',
        'name',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scopes',
        'meta',
        'connected_by',
        'last_synced_at',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'scopes' => 'array',
            'meta' => 'array',
        ];
    }

    public function channels(): HasMany
    {
        return $this->hasMany(SocialChannel::class);
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    /**
     * The active Buffer connection (there is only ever one).
     */
    public static function current(): ?self
    {
        return static::query()->latest('id')->first();
    }
}
