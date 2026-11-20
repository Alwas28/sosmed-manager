<?php

namespace App\Services\Content;

use App\Enums\ApprovalDecision;
use App\Enums\ContentStatus;
use App\Enums\WorkflowStep;
use App\Models\Content;
use App\Models\User;
use App\Services\Buffer\BufferException;

/**
 * Alur redaksi tetap: Wartawan → Editor → Publisher → Pimpinan Redaksi.
 *
 * Status `pending_approval` dipakai selama menunggu keputusan di tahap mana pun;
 * tahap aktifnya disimpan di `contents.workflow_stage`. Tahap Pimpinan Redaksi
 * punya batas waktu 10 menit (`stage_due_at`) — bila terlewat, konten terbit
 * otomatis lewat `publishDue()` yang dipanggil scheduler tiap menit (dan juga
 * sebagai pengaman saat daftar konten dibaca).
 */
class ContentApprovalService
{
    /** Draft/revisi dikirim Wartawan; tahap aktif berikutnya adalah Editor. */
    public function markSubmitted(Content $content): void
    {
        $content->update([
            'workflow_stage' => WorkflowStep::Editor->value,
            'stage_due_at' => null,
        ]);
    }

    /**
     * Setuju pada tahap aktif. Editor → Publisher → Pimpinan Redaksi → terbit.
     *
     * @return string Pesan hasil keputusan untuk ditampilkan ke pengguna.
     */
    public function approve(Content $content, User $reviewer, ?string $note = null): string
    {
        abort_unless($content->status === ContentStatus::PendingApproval, 422, 'Konten tidak sedang menunggu approval.');

        $step = $this->currentStep($content);
        abort_if($step === WorkflowStep::Writer, 422, 'Konten belum diajukan ke Editor.');
        $this->assertCanDecide($reviewer, $step);

        $from = $content->status->value;
        $fromStage = $step->value;

        if ($step === WorkflowStep::ChiefEditor) {
            $this->finalizePublish(
                $content,
                $reviewer,
                'Disetujui Pimpinan Redaksi: '.$reviewer->name.'.',
            );
            $message = 'Konten disetujui Pimpinan Redaksi dan langsung dipublikasikan.';
        } else {
            $next = $step === WorkflowStep::Editor
                ? WorkflowStep::Publisher
                : WorkflowStep::ChiefEditor;

            $content->update([
                'workflow_stage' => $next->value,
                'approved_by' => $reviewer->id,
                'stage_due_at' => $next === WorkflowStep::ChiefEditor
                    ? now()->addMinutes(WorkflowStep::AUTO_PUBLISH_MINUTES)
                    : null,
            ]);

            $message = $next === WorkflowStep::Publisher
                ? 'Konten disetujui Editor dan diteruskan ke Publisher.'
                : 'Konten disetujui Publisher. Pimpinan Redaksi punya 10 menit untuk merespons.';
        }

        $content->approvals()->create([
            'reviewer_id' => $reviewer->id,
            'decision' => ApprovalDecision::Approved,
            'note' => $note,
        ]);

        $content->logActivity(
            'approved',
            $from,
            $content->status->value,
            $step->label().' → '.($step === WorkflowStep::ChiefEditor ? 'Dipublikasikan' : $this->currentStep($content)->label())
                .($note ? ' — '.$note : ''),
        );

        return $message;
    }

    /** Penolakan/revisi mengembalikan konten ke meja Wartawan. */
    public function requestRevision(Content $content, User $reviewer, string $note): void
    {
        abort_unless($content->status === ContentStatus::PendingApproval, 422, 'Konten tidak sedang menunggu approval.');

        $step = $this->currentStep($content);
        abort_if($step === WorkflowStep::Writer, 422, 'Konten belum diajukan ke Editor.');
        $this->assertCanDecide($reviewer, $step);

        $from = $content->status->value;
        $content->update([
            'status' => ContentStatus::Revision,
            'approved_by' => null,
            'workflow_stage' => WorkflowStep::Writer->value,
            'stage_due_at' => null,
        ]);

        $content->approvals()->create([
            'reviewer_id' => $reviewer->id,
            'decision' => ApprovalDecision::Revision,
            'note' => $note,
        ]);

        $content->logActivity(
            'revision_requested',
            $from,
            ContentStatus::Revision->value,
            'Diminta oleh '.$step->label().': '.$note,
        );
    }

    /**
     * Publikasikan semua konten yang sudah 10 menit menunggu Pimpinan Redaksi.
     * Dipanggil oleh `php artisan content:auto-publish` tiap menit.
     */
    public function publishDue(): int
    {
        $contents = Content::query()
            ->where('status', ContentStatus::PendingApproval->value)
            ->where('workflow_stage', WorkflowStep::ChiefEditor->value)
            ->whereNotNull('stage_due_at')
            ->where('stage_due_at', '<=', now())
            ->with(['platforms', 'media', 'creator', 'approver'])
            ->get();

        if ($contents->isEmpty()) {
            return 0;
        }

        $actor = $this->autoPublishActor();
        if ($actor === null) {
            return 0;
        }

        foreach ($contents as $content) {
            try {
                $this->finalizePublish(
                    $content,
                    $actor,
                    'Publikasi otomatis: tidak ada respon Pimpinan Redaksi dalam 10 menit.',
                );
            } catch (\Throwable $e) {
                $content->refresh();
                $content->update([
                    'status' => ContentStatus::Failed,
                    'stage_due_at' => null,
                ]);
                $content->logActivity(
                    'publish_failed',
                    ContentStatus::PendingApproval->value,
                    ContentStatus::Failed->value,
                    $e->getMessage(),
                );
            }
        }

        return $contents->count();
    }

    private function currentStep(Content $content): WorkflowStep
    {
        return WorkflowStep::tryFrom((string) $content->workflow_stage) ?? WorkflowStep::Writer;
    }

    /** Peran penentu mengikuti tahap aktif; Administrator tetap berkecuali. */
    private function assertCanDecide(User $actor, WorkflowStep $step): void
    {
        if ($actor->isAdministrator()) {
            return;
        }

        if ($actor->role?->slug !== $step->roleSlug()) {
            abort(403, 'Peran Anda tidak sesuai tahap '.$step->label().' yang sedang aktif.');
        }
    }

    /** Pimpinan Redaksi → terbit (Buffer bila tersedia, otherwise tandai manual). */
    private function finalizePublish(Content $content, User $actor, string $note): void
    {
        $service = app(ContentPublishService::class);
        $from = $content->status->value;

        $content->update([
            'status' => ContentStatus::Approved,
            'approved_by' => $actor->id,
            'stage_due_at' => null,
        ]);

        try {
            $service->publishViaBuffer($content, $actor);
        } catch (BufferException $e) {
            // Buffer belum terhubung/menolak: tetap terbit (manual) supaya janji
            // "10 menit tanpa respon → publish otomatis" benar-benar terpenuhi.
            $content->refresh();
            $service->markPublishedManually($content, $actor);
        }

        $content->logActivity(
            'published_after_approval',
            $from,
            ContentStatus::Published->value,
            $note,
        );
    }

    private function autoPublishActor(): ?User
    {
        foreach (['pimpinan-redaksi', 'administrator', 'publisher'] as $slug) {
            $user = User::query()
                ->whereHas('role', fn ($query) => $query->where('slug', $slug))
                ->first();
            if ($user !== null) {
                return $user;
            }
        }

        return null;
    }
}
