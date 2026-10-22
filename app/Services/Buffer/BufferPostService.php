<?php

namespace App\Services\Buffer;

use App\Enums\PostType;
use App\Models\BufferConnection;
use App\Models\Media;
use App\Models\SocialChannel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Creates / schedules posts on Buffer (Blueprint §10) via the `createPost`
 * GraphQL mutation. Schema built from Buffer's own docs (2026-09,
 * developers.buffer.com/reference.html + /examples/create-text-post.html +
 * /examples/create-image-post.html + /guides/error-handling.html) rather
 * than reverse-engineered live like BufferService::account()/channels(),
 * since nobody had exercised this mutation against a real connection when
 * it was first written.
 *
 * `createPost` takes exactly one channelId per call — there's no bulk/
 * multi-channel mutation — so posting to several selected platforms means
 * one mutation call per channel. Partial failure (some channels succeed,
 * others don't) is treated as an overall failure so a Content's status
 * never claims "Published" when it wasn't published everywhere selected;
 * any posts that DID go out on Buffer are still returned in `postIds`.
 *
 * Always fetches its token via BufferAuthService::freshAccessToken()
 * (refreshes an expiring OAuth token first) rather than the raw
 * BufferConnection::access_token — using the raw column directly was a
 * real bug in an earlier version of this class: it worked right after
 * connecting (token still fresh) but broke on the user's real deployed
 * server once the access token had actually expired, surfacing as Buffer's
 * own "Access token is not valid" (401 UNAUTHENTICATED).
 */
class BufferPostService
{
    public function __construct(private BufferService $buffer, private BufferAuthService $auth) {}

    /**
     * @param  iterable<SocialChannel>  $channels
     * @param  array{now?: bool, scheduled_at?: \DateTimeInterface|string|null, media?: iterable<Media>, post_types?: array<int, string>, headline?: ?string, link?: array{url: string, title?: string, description?: string}|null}  $options  `post_types` maps SocialChannel id → post/reel/story/link
     * @return array{postIds: array<int, string>, errors: array<int, string>}
     *
     * @throws BufferException
     */
    public function schedule(iterable $channels, string $text, array $options = []): array
    {
        $connection = BufferConnection::current();

        if (! $connection) {
            throw new BufferException('Buffer belum terhubung.');
        }

        $channels = collect($channels);

        if ($channels->isEmpty()) {
            throw new BufferException('Tidak ada channel Buffer yang dipilih.');
        }

        $mode = ($options['now'] ?? false) ? 'shareNow' : 'customScheduled';
        $dueAt = null;

        if ($mode === 'customScheduled') {
            $scheduledAt = $options['scheduled_at'] ?? null;

            if (! $scheduledAt) {
                throw new BufferException('Waktu jadwal wajib diisi untuk mode terjadwal.');
            }

            $dueAt = ($scheduledAt instanceof \DateTimeInterface ? Carbon::instance($scheduledAt) : Carbon::parse((string) $scheduledAt))
                ->toIso8601String();
        }

        $media = collect($options['media'] ?? []);
        $assets = $this->buildAssets($media);
        $hasVideo = $media->contains(fn (Media $m) => $m->isVideo());
        $headline = $options['headline'] ?? null;
        $mutation = $this->createPostMutation();
        // Reuse the same refresh-if-needed accessor BufferAuthService's own
        // account()/channels() calls rely on, instead of the possibly-
        // expired token stored on the row — this is what silently broke
        // real publishing the first time around ("Access token is not
        // valid": the raw stored token was used straight, un-refreshed).
        $client = $this->buffer->withToken($this->auth->freshAccessToken($connection));

        $postIds = [];
        $errors = [];

        foreach ($channels as $channel) {
            $label = $channel->display_name ?: $channel->service;

            if (! $channel->buffer_profile_id) {
                $errors[] = "{$label}: channel tidak punya Buffer profile id.";

                continue;
            }

            $postType = $options['post_types'][$channel->id] ?? PostType::Post->value;
            $isLink = $postType === PostType::Link->value;
            $link = $options['link'] ?? null;

            $channelText = match (true) {
                $postType === PostType::Story->value => null,
                $isLink => PostType::composeLinkText($text, $link['url'] ?? null),
                default => $text,
            };

            if ($channelText !== null && $channel->service === 'tiktok') {
                $channelText = $this->preserveLineBreaksForTikTok($channelText);
            }

            $input = array_filter([
                // Stories carry no caption on Instagram/Facebook. Link posts need the
                // URL inside the text too — Twitter has no linkAttachment metadata,
                // it only unfurls a link that's actually in the tweet body.
                'text' => $channelText,
                'channelId' => $channel->buffer_profile_id,
                'schedulingType' => 'automatic',
                'mode' => $mode,
                'dueAt' => $dueAt,
                // linkAttachment is mutually exclusive with a non-empty assets array.
                'assets' => $isLink ? [] : $assets,
                'metadata' => $this->buildMetadata($channel, $postType, $link, $headline, $hasVideo),
            ], static fn ($value) => $value !== null);

            try {
                $data = $client->query($mutation, ['input' => $input]);
                $result = $data['createPost'] ?? [];

                if (($result['__typename'] ?? null) === 'PostActionSuccess') {
                    $postIds[] = (string) ($result['post']['id'] ?? '');
                } else {
                    $errors[] = $label.': '.($result['message'] ?? 'Gagal membuat post di Buffer.');
                }
            } catch (BufferException $e) {
                $errors[] = $label.': '.$e->getMessage();
            }
        }

        if ($errors !== []) {
            $prefix = $postIds !== []
                ? count($postIds).' dari '.$channels->count().' channel berhasil, sisanya gagal — '
                : '';

            throw new BufferException($prefix.implode(' | ', $errors));
        }

        return ['postIds' => $postIds, 'errors' => $errors];
    }

    /**
     * Buffer rejects Instagram and Facebook posts that carry no `type`
     * (and Instagram also requires `shouldShareToFeed`); other networks
     * need no metadata for a plain post.
     *
     * TikTok is a separate case: its *photo*-mode posts (no video attached)
     * use TikTok's own `post_info.title` as a short (≤90 char) headline —
     * distinct from the main caption/description, which is what the
     * top-level `text` input carries. Without it, a TikTok photo/slide post
     * can go out with no visible caption even though `text` was set
     * correctly — this mirrors our own "Judul Konten" field, so that's what
     * fills it. Video posts don't use this field (TikTok's docs mark it
     * "for photo posts"), so it's only sent when there's no video.
     *
     * @param  array{url: string, title?: string, description?: string}|null  $link
     * @return array<string, array<string, mixed>>|null
     */
    /**
     * TikTok's own platform drops truly-blank lines from post text (third-party
     * TikTok API docs confirm this: "TikTok does not currently support line
     * breaks in the post text. Included line breaks will be ignored.") — a
     * caption with several paragraphs separated by a blank line comes out as
     * one run-on block, even though every word did go through. The common,
     * widely-documented workaround: a blank line gets discarded, but a line
     * holding an invisible character reads as "has content" and survives —
     * so each blank line between paragraphs gets two U+2800 (Braille Pattern
     * Blank) lines instead of being truly empty. One invisible line alone
     * only buys a sliver of a gap (TikTok still renders it as a single thin
     * line); two gives separation closer to Instagram's full blank-line look.
     */
    private function preserveLineBreaksForTikTok(string $text): string
    {
        // Normalize Windows/old-Mac line endings first — text pasted from Word,
        // WhatsApp, or Google Docs often carries \r\n rather than a bare \n, which
        // the blank-line pattern below wouldn't otherwise recognize as a break.
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // A "blank" line can still hold a non-breaking space (U+00A0) left over
        // from a paste, not just plain spaces/tabs — match that too.
        return preg_replace('/\n[ \t\x{00A0}]*\n/u', "\n\u{2800}\n\u{2800}\n", $text) ?? $text;
    }

    private function buildMetadata(SocialChannel $channel, string $postType, ?array $link, ?string $headline = null, bool $hasVideo = false): ?array
    {
        if ($postType === PostType::Link->value) {
            // Twitter has no linkAttachment field — the URL in the text is enough for it to unfurl.
            return match ($channel->service) {
                'facebook' => ['facebook' => ['type' => PostType::Post->value, 'linkAttachment' => $link]],
                'linkedin' => ['linkedin' => ['linkAttachment' => $link]],
                'threads' => ['threads' => ['linkAttachment' => $link]],
                default => null,
            };
        }

        return match ($channel->service) {
            'instagram' => ['instagram' => [
                'type' => $postType,
                'shouldShareToFeed' => $postType !== PostType::Story->value,
            ]],
            'facebook' => ['facebook' => ['type' => $postType]],
            'tiktok' => (! $hasVideo && trim((string) $headline) !== '')
                ? ['tiktok' => ['title' => Str::limit(trim($headline), 90, '', preserveWords: true)]]
                : null,
            default => null,
        };
    }

    /**
     * @param  iterable<Media>  $media
     * @return array<int, array<string, mixed>>
     */
    private function buildAssets(iterable $media): array
    {
        $assets = [];

        foreach ($media as $item) {
            $assets[] = $item->isVideo()
                ? ['video' => ['url' => $item->url()]]
                : ['image' => ['url' => $item->url()]];
        }

        return $assets;
    }

    private function createPostMutation(): string
    {
        return <<<'GQL'
            mutation CreatePost($input: CreatePostInput!) {
              createPost(input: $input) {
                __typename
                ... on PostActionSuccess {
                  post {
                    id
                    text
                    dueAt
                  }
                }
                ... on MutationError {
                  message
                }
              }
            }
        GQL;
    }
}
