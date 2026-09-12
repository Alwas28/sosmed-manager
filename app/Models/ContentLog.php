<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentLog extends Model
{
    protected $fillable = [
        'content_id', 'user_id', 'action', 'from_status', 'to_status', 'note', 'meta',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
