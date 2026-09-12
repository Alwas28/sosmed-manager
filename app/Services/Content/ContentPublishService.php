<?php

namespace App\Services\Content;

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\SocialChannel;
use App\Models\User;
use App\Services\Buffer\BufferException;
use App\Services\Buffer\BufferPostService;
use Illuminate\Support\Carbon;

/**
 * The step after the Approval Workflow (Blueprint §8/§10): Disetujui →
 * Terjadwal → Dipublikasikan, via Buffer.
 *
 * BufferPostService::schedule() still deliberately throws — the real
 * `createPost` GraphQL mutation isn't implemented yet, and no user has
 * walked the Buffer OAuth connect flow to test against. publishViaBuffer()
 * attempts it honestly and records the *real* failure reason (status →
 * Gagal, logged) instead of the button silently doing nothing.
 * markPublishedManually() is the practical fallback for "I published this
 * myself outside the system" until Buffer publish actually works.
 */
class ContentPublishService
{
    public function schedule(Content $content, User $user, Carbon $scheduledAt): void
    {
        abort_unless($content->status === ContentStatus::Approved, 422, 'Hanya konten yang sudah disetujui yang bisa dijadwalkan.');

        $content->update([
            'status' => ContentStatus::Scheduled,
            'scheduled_at' => $scheduledAt,
        ]);

        $content->logActivity(
            'scheduled',
            ContentStatus::Approved->value,
            ContentStatus::Scheduled->value,
            'Dijadwalkan untuk '.$scheduledAt->format('d M Y H:i').' oleh '.$user->name.'.',
        );
    }

    public function cancelSchedule(Content $content, User $user): void
    {
        abort_unless($content->status === ContentStatus::Scheduled, 422, 'Konten ini tidak sedang terjadwal.');

        $content->update(['status' => ContentStatus::Approved, 'scheduled_at' => null]);
        $content->logActivity(
            'schedule_cancelled',
            ContentStatus::Scheduled->value,
            ContentStatus::Approved->value,
            'Jadwal dibatalkan oleh '.$user->name.'.',
        );
    }

    /**
     * Attempts a real Buffer publish. On failure the content moves to Gagal
     * with the actual error recorded (never silently no-ops) and the
     * exception is re-thrown so the caller can surface it too.
     *
     * @throws BufferException
     */
    public function publishViaBuffer(Content $content, User $user): void
    {
        abort_unless(
            in_array($content->status, [ContentStatus::Approved, ContentStatus::Scheduled, ContentStatus::Failed], true),
            422,
            'Konten ini belum siap untuk dipublikasikan.',
        );

        $from = $content->status->value;
        $channelIds = $content->platforms->pluck('social_channel_id')->filter()->values();
        $channels = SocialChannel::whereIn('id', $channelIds)->get();

        try {
            $result = app(BufferPostService::class)->schedule($channels, (string) $content->caption, ['now' => true]);

            $content->update([
                'status' => ContentStatus::Published,
                'published_at' => now(),
                'buffer_post_ids' => $result,
            ]);
            $content->logActivity('published', $from, ContentStatus::Published->value, 'Dipublikasikan via Buffer oleh '.$user->name.'.');
        } catch (BufferException $e) {
            $content->update(['status' => ContentStatus::Failed]);
            $content->logActivity('publish_failed', $from, ContentStatus::Failed->value, $e->getMessage());

            throw $e;
        }
    }

    public function markPublishedManually(Content $content, User $user): void
    {
        abort_unless(
            in_array($content->status, [ContentStatus::Approved, ContentStatus::Scheduled, ContentStatus::Failed], true),
            422,
            'Konten ini belum siap untuk dipublikasikan.',
        );

        $from = $content->status->value;
        $content->update(['status' => ContentStatus::Published, 'published_at' => now()]);
        $content->logActivity(
            'published_manually',
            $from,
            ContentStatus::Published->value,
            'Ditandai dipublikasikan manual (di luar Buffer) oleh '.$user->name.'.',
        );
    }
}
