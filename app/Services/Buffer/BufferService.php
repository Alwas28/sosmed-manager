<?php

namespace App\Services\Buffer;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Low-level client for the Buffer API (GraphQL, https://api.buffer.com).
 *
 * Controllers / Livewire components never talk to Buffer directly — they go
 * through BufferAuthService / BufferPostService which use this class
 * (Blueprint §13). Swapping Buffer for another provider later means
 * reimplementing only this layer.
 */
class BufferService
{
    public function __construct(private ?string $token = null) {}

    public function withToken(string $token): self
    {
        return new self($token);
    }

    /**
     * Authenticated Buffer account, including its organizations.
     *
     * @return array<string, mixed>
     */
    public function account(): array
    {
        $data = $this->query(<<<'GQL'
            query {
              account {
                id
                email
                name
                organizations { id name }
              }
            }
        GQL);

        return $data['account'] ?? [];
    }

    /**
     * Connected channels (formerly "profiles") for an organization.
     *
     * @return array<int, array<string, mixed>>
     */
    public function channels(string $organizationId): array
    {
        $org = json_encode($organizationId);

        $data = $this->query(<<<GQL
            query {
              channels(input: { organizationId: {$org} }) {
                id
                name
                service
                avatar
                displayName
                isQueuePaused
              }
            }
        GQL);

        return $data['channels'] ?? [];
    }

    /**
     * Run a raw GraphQL query / mutation.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed> the `data` payload
     */
    public function query(string $query, array $variables = []): array
    {
        return $this->handle(
            $this->client()->post('', array_filter([
                'query' => $query,
                'variables' => $variables ?: null,
            ]))
        );
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.buffer.api_base'), '/'))
            ->withToken($this->requireToken())
            ->acceptJson()
            ->timeout(20);
    }

    private function requireToken(): string
    {
        if (blank($this->token)) {
            throw new BufferException('Token akses Buffer tidak tersedia.');
        }

        return $this->token;
    }

    private function handle(Response $response): array
    {
        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if ($response->failed()) {
            throw new BufferException('Permintaan ke Buffer gagal (HTTP '.$response->status().'): '.$response->body());
        }

        if (! empty($body['errors'])) {
            throw new BufferException('Buffer API: '.($body['errors'][0]['message'] ?? 'kesalahan tidak diketahui'));
        }

        return $body['data'] ?? [];
    }
}
