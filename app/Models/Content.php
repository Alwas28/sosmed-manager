<?php

namespace App\Models;

use App\Enums\ApprovalDecision;
use App\Enums\ContentCategory;
use App\Enums\ContentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Content extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title', 'caption', 'category', 'status', 'scheduled_at', 'published_at',
        'created_by', 'approved_by', 'buffer_post_ids',
    ];

    protected function casts(): array
    {
        return [
            'status' => ContentStatus::class,
            'category' => ContentCategory::class,
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
            'buffer_post_ids' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'content_media')
            ->withPivot('position')
            ->orderByPivot('position');
    }

    public function platforms(): HasMany
    {
        return $this->hasMany(ContentPlatform::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ContentLog::class)->latest();
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(ContentApproval::class)->latest();
    }

    /**
     * The reviewer's note when this content was sent back for revision
     * (shown while it is being fixed — status Revision or Draft).
     */
    public function pendingRevisionNote(): ?string
    {
        if (! in_array($this->status, [ContentStatus::Revision, ContentStatus::Draft], true)) {
            return null;
        }

        $last = $this->approvals()->first();

        return $last?->decision === ApprovalDecision::Revision ? $last->note : null;
    }

    public function logActivity(string $action, ?string $from = null, ?string $to = null, ?string $note = null): ContentLog
    {
        return $this->logs()->create([
            'user_id' => auth()->id(),
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'note' => $note,
        ]);
    }
}
