<?php

namespace App\Services\Buffer;

use App\Models\BufferConnection;
use App\Models\SocialChannel;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Buffer OAuth2 (Authorization Code + PKCE) connect flow, plus keeping the
 * local copy of the connection + its channels in sync (Blueprint §8, §9, §11).
 *
 * Only the Buffer token is stored (encrypted) — never the credentials of the
 * underlying Facebook / Instagram / LinkedIn accounts.
 */
class BufferAuthService
{
    public function __construct(private BufferService $buffer) {}

    public function configured(): bool
    {
        return $this->supportsOAuth() || $this->supportsStaticToken();
    }

    public function supportsOAuth(): bool
    {
        return filled(config('services.buffer.client_id'))
            && filled(config('services.buffer.client_secret'));
    }

    public function supportsStaticToken(): bool
    {
        return filled(config('services.buffer.access_token'));
    }

    public function redirectUri(): string
    {
        return (string) config('services.buffer.redirect');
    }

    /**
     * A fresh PKCE code verifier — store this in the session and hand the
     * matching challenge to authorizeUrl().
     */
    public function generateCodeVerifier(): string
    {
        return Str::random(96);
    }

    public function authorizeUrl(string $state, string $codeVerifier): string
    {
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        return rtrim((string) config('services.buffer.authorize_url'), '?').'?'.http_build_query([
            'client_id' => config('services.buffer.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => config('services.buffer.scope'),
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'prompt' => 'consent',
        ]);
    }

    /**
     * Exchange the authorization code for a token and persist the connection.
     */
    public function handleCallback(string $code, string $codeVerifier, User $user): BufferConnection
    {
        $response = Http::asForm()->acceptJson()->post((string) config('services.buffer.token_url'), [
            'grant_type' => 'authorization_code',
            'client_id' => config('services.buffer.client_id'),
            'client_secret' => config('services.buffer.client_secret'),
            'redirect_uri' => $this->redirectUri(),
            'code' => $code,
            'code_verifier' => $codeVerifier,
        ]);

        return $this->storeToken($response, $user);
    }

    /**
     * Connect using the personal access token in .env (no OAuth redirect).
     */
    public function connectWithStaticToken(User $user): BufferConnection
    {
        $token = (string) config('services.buffer.access_token');

        if ($token === '') {
            throw new BufferException('BUFFER_ACCESS_TOKEN belum diatur.');
        }

        $connection = BufferConnection::current() ?? new BufferConnection;
        $connection->fill([
            'access_token' => $token,
            'refresh_token' => null,
            'token_expires_at' => null,
            'connected_by' => $user->id,
            'meta' => ['source' => 'static_token'],
        ])->save();

        $this->hydrateAccount($connection);
        $this->syncChannels($connection);

        return $connection->refresh();
    }

    /**
     * Return a non-expired access token, refreshing it first if we can.
     */
    public function freshAccessToken(BufferConnection $connection): string
    {
        $expiresAt = $connection->token_expires_at;

        if (! $expiresAt || $expiresAt->isAfter(now()->addMinutes(2))) {
            return $connection->access_token;
        }

        if (blank($connection->refresh_token) || ! $this->supportsOAuth()) {
            return $connection->access_token;
        }

        $response = Http::asForm()->acceptJson()->post((string) config('services.buffer.token_url'), [
            'grant_type' => 'refresh_token',
            'client_id' => config('services.buffer.client_id'),
            'client_secret' => config('services.buffer.client_secret'),
            'refresh_token' => $connection->refresh_token,
        ]);

        if ($response->failed() || blank($response->json('access_token'))) {
            throw new BufferException('Token Buffer kedaluwarsa dan gagal di-refresh: '.$response->body());
        }

        $this->applyTokenResponse($connection, $response->json());
        $connection->save();

        return $connection->access_token;
    }

    public function hydrateAccount(BufferConnection $connection): void
    {
        try {
            $account = $this->buffer->withToken($connection->access_token)->account();
        } catch (BufferException) {
            return;
        }

        $connection->forceFill([
            'buffer_user_id' => $account['id'] ?? null,
            'name' => $account['email'] ?? ($account['name'] ?? null),
            'organization_id' => data_get($account, 'organizations.0.id'),
        ])->save();
    }

    /**
     * Pull the connected channels from Buffer into social_channels.
     *
     * @return int number of channels after sync
     */
    public function syncChannels(BufferConnection $connection): int
    {
        $token = $this->freshAccessToken($connection);

        if (blank($connection->organization_id)) {
            $this->hydrateAccount($connection);
        }

        if (blank($connection->organization_id)) {
            throw new BufferException('Organisasi Buffer tidak ditemukan untuk akun ini.');
        }

        $channels = $this->buffer->withToken($token)->channels($connection->organization_id);

        $seen = [];

        foreach ($channels as $channel) {
            if (! is_array($channel) || blank($channel['id'] ?? null)) {
                continue;
            }

            $seen[] = $channel['id'];

            SocialChannel::updateOrCreate(
                ['buffer_profile_id' => $channel['id']],
                [
                    'buffer_connection_id' => $connection->id,
                    'service' => strtolower((string) ($channel['service'] ?? 'unknown')),
                    'service_type' => null,
                    'username' => $channel['name'] ?? ($channel['displayName'] ?? null),
                    'display_name' => $channel['displayName'] ?? ($channel['name'] ?? null),
                    'avatar' => $channel['avatar'] ?? null,
                    'timezone' => null,
                    'is_active' => ! ($channel['isQueuePaused'] ?? false),
                    'meta' => $channel,
                    'last_synced_at' => now(),
                ],
            );
        }

        SocialChannel::where('buffer_connection_id', $connection->id)
            ->when($seen !== [], fn ($query) => $query->whereNotIn('buffer_profile_id', $seen))
            ->delete();

        $connection->forceFill(['last_synced_at' => now()])->save();

        return count($seen);
    }

    public function disconnect(): void
    {
        // social_channels rows are removed via the cascading foreign key.
        BufferConnection::current()?->delete();
    }

    private function storeToken(Response $response, User $user): BufferConnection
    {
        if ($response->failed() || blank($response->json('access_token'))) {
            throw new BufferException('Gagal menukar kode otorisasi Buffer: '.$response->body());
        }

        $connection = BufferConnection::current() ?? new BufferConnection;
        $connection->connected_by = $user->id;
        $this->applyTokenResponse($connection, $response->json());
        $connection->save();

        $this->hydrateAccount($connection);
        $this->syncChannels($connection);

        return $connection->refresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyTokenResponse(BufferConnection $connection, array $payload): void
    {
        $connection->fill([
            'access_token' => $payload['access_token'],
            'refresh_token' => $payload['refresh_token'] ?? $connection->refresh_token,
            'token_expires_at' => isset($payload['expires_in'])
                ? now()->addSeconds((int) $payload['expires_in'])
                : null,
            'scopes' => isset($payload['scope']) ? explode(' ', (string) $payload['scope']) : $connection->scopes,
            'meta' => array_merge((array) $connection->meta, [
                'token_type' => $payload['token_type'] ?? null,
            ]),
        ]);
    }
}
