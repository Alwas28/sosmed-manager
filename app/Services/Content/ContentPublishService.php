<?php

namespace App\Services\Content;

use App\Enums\ContentStatus;
use App\Enums\Platform;
use App\Models\Content;
use App\Models\SocialChannel;
use App\Models\User;
use App\Services\Buffer\BufferException;
use App\Services\Buffer\BufferPostService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The step after the Approval Workflow (Blueprint §8/§10): Disetujui →
 * Terjadwal → Dipublikasikan, via Buffer's real `createPost` GraphQL
 * mutation (see BufferPostService).
 *
 * A content's platform never has a Buffer channel picked for it (no picker
 * UI exists yet — the content form only stores plain platform strings), so
 * publishViaBuffer() resolves one automatically by matching platform →
 * SocialChannel::service, failing with a clear, actionable message when
 * none is connected rather than a generic one. Every failure — no matching
 * channel, or a real Buffer-side error — records the *real* reason
 * (status → Gagal, logged) instead of the button silently doing nothing.
 * markPublishedManually() is the practical fallback for "I published this
 * myself outside the system" for whenever Buffer publish still can't be
 * used (not connected yet, a platform has no channel, Buffer itself
 * rejects the post, …).
 */
class ContentPublishService
{
    /**
     * A content's platform is stored as a plain string (`content_platforms.platform`,
     * one of the Platform enum values) — there's no "pick a channel" step in the
     * content form yet, so `social_channel_id` is never set at creation time.
     * Resolve one automatically by matching platform → SocialChannel::service
     * (falling back to an explicit social_channel_id if one is ever set by a
     * future picker), so publishing doesn't unconditionally fail with
     * "Tidak ada channel Buffer yang dipilih" the moment Buffer *is* connected.
     */
    private const array PLATFORM_SERVICE_ALIASES = [
        'facebook' => ['facebook'],
        'instagram' => ['instagram'],
        'twitter' => ['twitter', 'x'],
        'linkedin' => ['linkedin'],
        'tiktok' => ['tiktok'],
    ];

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
        $resolved = $this->resolveChannels($content);

        if ($resolved['missing'] !== []) {
            $message = $resolved['channels']->isEmpty()
                ? 'Belum ada channel Buffer yang terhubung untuk platform: '.implode(', ', $resolved['missing']).'. Hubungkan channel-nya dulu di menu Integrasi Buffer.'
                : 'Channel Buffer belum terhubung untuk platform: '.implode(', ', $resolved['missing']).'.';

            $content->update(['status' => ContentStatus::Failed]);
            $content->logActivity('publish_failed', $from, ContentStatus::Failed->value, $message);

            throw new BufferException($message);
        }

        try {
            $result = app(BufferPostService::class)->schedule($resolved['channels'], (string) $content->caption, [
                'now' => true,
                'media' => $content->media,
            ]);

            $content->update([
                'status' => ContentStatus::Published,
                'published_at' => now(),
                'buffer_post_ids' => $result['postIds'],
            ]);
            $content->logActivity('published', $from, ContentStatus::Published->value, 'Dipublikasikan via Buffer oleh '.$user->name.'.');
        } catch (BufferException $e) {
            $content->update(['status' => ContentStatus::Failed]);
            $content->logActivity('publish_failed', $from, ContentStatus::Failed->value, $e->getMessage());

            throw $e;
        }
    }

    /**
     * @return array{channels: Collection<int, SocialChannel>, missing: array<int, string>}
     */
    private function resolveChannels(Content $content): array
    {
        $channels = collect();
        $missing = [];

        if ($content->platforms->isEmpty()) {
            return ['channels' => $channels, 'missing' => ['(belum ada platform dipilih)']];
        }

        foreach ($content->platforms as $cp) {
            $channel = $cp->social_channel_id
                ? SocialChannel::find($cp->social_channel_id)
                : SocialChannel::whereIn('service', self::PLATFORM_SERVICE_ALIASES[$cp->platform] ?? [$cp->platform])->first();

            if ($channel) {
                $channels->push($channel);
            } else {
                $missing[] = Platform::tryLabel($cp->platform);
            }
        }

        return ['channels' => $channels, 'missing' => $missing];
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
