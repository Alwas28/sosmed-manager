<?php

namespace App\Http\Controllers\Api;

use App\Models\ActivityLog;
use App\Models\BufferConnection;
use App\Models\SocialChannel;
use App\Services\Buffer\BufferAuthService;
use App\Services\Buffer\BufferException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class IntegrationController extends ApiController
{
    public function social(): JsonResponse
    {
        $connection = BufferConnection::current();
        $groups = SocialChannel::query()
            ->orderBy('service')
            ->orderBy('username')
            ->get()
            ->groupBy('service')
            ->map(fn ($channels, $service) => [
                'service' => $service,
                'label' => $channels->first()?->serviceLabel() ?? ucfirst((string) $service),
                'channels' => $channels->map(fn (SocialChannel $channel) => $this->channelPayload($channel))->values()->all(),
            ])
            ->values()
            ->all();

        return $this->success([
            'connected' => $connection !== null,
            'connection' => $this->connectionPayload($connection),
            'groups' => $groups,
        ]);
    }

    public function bufferStatus(): JsonResponse
    {
        $auth = app(BufferAuthService::class);
        $connection = BufferConnection::current();

        return $this->success([
            'configured' => $auth->configured(),
            'supports_oauth' => $auth->supportsOAuth(),
            'supports_static_token' => $auth->supportsStaticToken(),
            'redirect_uri' => $auth->redirectUri(),
            'connected' => $connection !== null,
            'connection' => $this->connectionPayload($connection),
            'channels' => $connection
                ? $connection->channels()->orderBy('service')->orderBy('username')->get()
                    ->map(fn (SocialChannel $channel) => $this->channelPayload($channel))->values()->all()
                : [],
        ]);
    }

    public function bufferConnectUrl(Request $request): JsonResponse
    {
        $auth = app(BufferAuthService::class);
        if (! $auth->supportsOAuth()) {
            return $this->error('Client ID / Secret Buffer belum diatur di .env.', 503);
        }

        $state = Str::random(40);
        $verifier = $auth->generateCodeVerifier();
        $request->session()->put('buffer_oauth_state', $state);
        $request->session()->put('buffer_oauth_verifier', $verifier);

        return $this->success([
            'url' => $auth->authorizeUrl($state, $verifier),
        ]);
    }

    public function bufferConnectToken(Request $request): JsonResponse
    {
        $auth = app(BufferAuthService::class);
        if (! $auth->supportsStaticToken()) {
            return $this->error('BUFFER_ACCESS_TOKEN belum diatur di .env.', 503);
        }

        try {
            $connection = $auth->connectWithStaticToken($request->user());
        } catch (BufferException $e) {
            return $this->error($e->getMessage(), 422);
        }

        ActivityLog::record('buffer_connected', 'Terhubung memakai access token.');

        return $this->success($this->connectionPayload($connection), 'Buffer terhubung memakai access token.');
    }

    public function bufferSync(Request $request): JsonResponse
    {
        $connection = BufferConnection::current();
        if ($connection === null) {
            return $this->error('Buffer belum terhubung.', 404);
        }

        try {
            $count = app(BufferAuthService::class)->syncChannels($connection);
        } catch (BufferException $e) {
            return $this->error($e->getMessage(), 422);
        }

        ActivityLog::record('buffer_synced', "{$count} channel diperbarui.");

        return $this->success([
            'count' => $count,
            'channels' => $connection->channels()->orderBy('service')->orderBy('username')->get()
                ->map(fn (SocialChannel $channel) => $this->channelPayload($channel))->values()->all(),
        ], "Sinkronisasi selesai — {$count} channel diperbarui.");
    }

    public function bufferDisconnect(): JsonResponse
    {
        app(BufferAuthService::class)->disconnect();
        ActivityLog::record('buffer_disconnected', 'Koneksi Buffer diputus.');

        return $this->success(null, 'Koneksi Buffer telah diputus.');
    }

    /** @return array<string, mixed>|null */
    private function connectionPayload(?BufferConnection $connection): ?array
    {
        if ($connection === null) {
            return null;
        }

        return [
            'id' => $connection->id,
            'name' => $connection->name,
            'buffer_user_id' => $connection->buffer_user_id,
            'last_synced_at' => $connection->last_synced_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function channelPayload(SocialChannel $channel): array
    {
        return [
            'id' => $channel->id,
            'service' => $channel->service,
            'label' => $channel->serviceLabel(),
            'username' => $channel->username,
            'display_name' => $channel->display_name,
            'avatar' => $channel->avatar,
            'is_active' => $channel->is_active,
            'last_synced_at' => $channel->last_synced_at?->toISOString(),
        ];
    }
}
