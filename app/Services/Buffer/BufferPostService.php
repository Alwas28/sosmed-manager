<?php

namespace App\Services\Buffer;

use App\Models\BufferConnection;
use App\Models\Media;
use App\Models\SocialChannel;
use Illuminate\Support\Carbon;

/**
 * Creates / schedules posts on Buffer (Blueprint §10) via the `createPost`
 * GraphQL mutation. Schema verified against Buffer's own docs (2026-09,
 * developers.buffer.com/reference.html + /examples/create-text-post.html +
 * /examples/create-image-post.html + /guides/error-handling.html) — this is
 * the one part of the Buffer integration nobody has been able to exercise
 * against a real, live connection yet, so it's built from documentation
 * rather than reverse-engineered like BufferService::account()/channels().
 *
 * `createPost` takes exactly one channelId per call — there's no bulk/
 * multi-channel mutation — so posting to several selected platforms means
 * one mutation call per channel. Partial failure (some channels succeed,
 * others don't) is treated as an overall failure so a Content's status
 * never claims "Published" when it wasn't published everywhere selected;
 * any posts that DID go out on Buffer are still returned in `postIds`.
 */
class BufferPostService
{
    public function __construct(private BufferService $buffer) {}

    /**
     * @param  iterable<SocialChannel>  $channels
     * @param  array{now?: bool, scheduled_at?: \DateTimeInterface|string|null, media?: iterable<Media>}  $options
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

        $assets = $this->buildAssets($options['media'] ?? []);
        $mutation = $this->createPostMutation();
        $client = $this->buffer->withToken($connection->access_token);

        $postIds = [];
        $errors = [];

        foreach ($channels as $channel) {
            $label = $channel->display_name ?: $channel->service;

            if (! $channel->buffer_profile_id) {
                $errors[] = "{$label}: channel tidak punya Buffer profile id.";

                continue;
            }

            $input = array_filter([
                'text' => $text,
                'channelId' => $channel->buffer_profile_id,
                'schedulingType' => 'automatic',
                'mode' => $mode,
                'dueAt' => $dueAt,
                'assets' => $assets,
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
