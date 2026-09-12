<?php

namespace App\Services\Content;

use App\Enums\ApprovalDecision;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\User;

/**
 * Approval workflow (Blueprint §7): Menunggu Approval → Reviewer → Approve / Minta Revisi.
 */
class ContentApprovalService
{
    public function approve(Content $content, User $reviewer, ?string $note = null): void
    {
        abort_unless($content->status === ContentStatus::PendingApproval, 422, 'Konten tidak sedang menunggu approval.');

        $content->update([
            'status' => ContentStatus::Approved,
            'approved_by' => $reviewer->id,
        ]);

        $content->approvals()->create([
            'reviewer_id' => $reviewer->id,
            'decision' => ApprovalDecision::Approved,
            'note' => $note,
        ]);

        $content->logActivity(
            'approved',
            ContentStatus::PendingApproval->value,
            ContentStatus::Approved->value,
            $note,
        );
    }

    public function requestRevision(Content $content, User $reviewer, string $note): void
    {
        abort_unless($content->status === ContentStatus::PendingApproval, 422, 'Konten tidak sedang menunggu approval.');

        $content->update([
            'status' => ContentStatus::Revision,
            'approved_by' => null,
        ]);

        $content->approvals()->create([
            'reviewer_id' => $reviewer->id,
            'decision' => ApprovalDecision::Revision,
            'note' => $note,
        ]);

        $content->logActivity(
            'revision_requested',
            ContentStatus::PendingApproval->value,
            ContentStatus::Revision->value,
            $note,
        );
    }
}
