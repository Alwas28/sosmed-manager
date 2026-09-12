<?php

namespace App\Services\Buffer;

use App\Models\BufferConnection;
use App\Models\SocialChannel;

/**
 * Creates / schedules posts on Buffer (Blueprint §10).
 *
 * The Buffer API is now GraphQL — publishing is a `createPost` mutation.
 * Wiring that up belongs to the upcoming Modul Konten (Content -> pilih
 * channel -> jadwal -> publish_jobs.buffer_post_id -> sinkronisasi status),
 * so this only sketches the entry point for now.
 */
class BufferPostService
{
    public function __construct(private BufferService $buffer, private BufferAuthService $auth) {}

    /**
     * @param  iterable<SocialChannel>  $channels
     * @param  array{scheduled_at?: \DateTimeInterface|string|null, now?: bool, media?: array<string,mixed>}  $options
     * @return array<string, mixed>
     */
    public function schedule(iterable $channels, string $text, array $options = []): array
    {
        $connection = BufferConnection::current();

        if (! $connection) {
            throw new BufferException('Buffer belum terhubung.');
        }

        $channelIds = collect($channels)->pluck('buffer_profile_id')->filter()->values();

        if ($channelIds->isEmpty()) {
            throw new BufferException('Tidak ada channel Buffer yang dipilih.');
        }

        throw new BufferException(
            'Publikasi via Buffer belum diimplementasikan — akan dikerjakan bersama Modul Konten '
            .'memakai mutation GraphQL createPost.'
        );
    }
}
