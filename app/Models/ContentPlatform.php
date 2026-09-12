<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentPlatform extends Model
{
    public $timestamps = false;

    protected $fillable = ['content_id', 'platform', 'social_channel_id'];

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(SocialChannel::class, 'social_channel_id');
    }
}
