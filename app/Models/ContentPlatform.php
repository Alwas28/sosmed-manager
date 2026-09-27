<?php

namespace App\Models;

use App\Enums\Platform;
use App\Enums\PostType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentPlatform extends Model
{
    public $timestamps = false;

    protected $fillable = ['content_id', 'platform', 'post_type', 'social_channel_id'];

    public function postTypeLabel(): string
    {
        $platform = Platform::tryFrom($this->platform);
        $type = PostType::tryFrom((string) $this->post_type) ?? PostType::Post;

        return $platform ? $type->label($platform) : ucfirst($type->value);
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(SocialChannel::class, 'social_channel_id');
    }
}
