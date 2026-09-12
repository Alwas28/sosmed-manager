<?php

namespace App\Models;

use App\Enums\ApprovalDecision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentApproval extends Model
{
    protected $fillable = ['content_id', 'reviewer_id', 'decision', 'note'];

    protected function casts(): array
    {
        return ['decision' => ApprovalDecision::class];
    }

    public function content(): BelongsTo
    {
        return $this->belongsTo(Content::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
