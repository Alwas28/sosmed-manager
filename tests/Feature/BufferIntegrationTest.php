<?php

namespace Tests\Feature;

use App\Models\BufferConnection;
use App\Models\Role;
use App\Models\SocialChannel;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BufferIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', 'administrator')->value('id'),
        ]);
    }

    private function configureBuffer(): void
    {
        config()->set('services.buffer.client_id', 'test-client');
        config()->set('services.buffer.client_secret', 'test-secret');
        config()->set('services.buffer.access_token', null);
        config()->set('services.buffer.redirect', 'http://localhost/buffer/callback');
        config()->set('services.buffer.scope', 'account:read posts:read offline_access');
        config()->set('services.buffer.authorize_url', 'https://auth.buffer.test/auth');
        config()->set('services.buffer.token_url', 'https://auth.buffer.test/token');
        config()->set('services.buffer.api_base', 'https://api.buffer.test');
    }

    /** Fake the GraphQL API: branch on the query text. */
    private function fakeBufferApi(): void
    {
        Http::fake([
            'https://auth.buffer.test/token' => Http::response([
                'access_token' => 'ya29-token',
                'refresh_token' => 'refresh-1',
                'expires_in' => 3600,
                'token_type' => 'bearer',
                'scope' => 'account:read posts:read offline_access',
            ]),
            'https://api.buffer.test*' => function ($request) {
                $query = $request->data()['query'] ?? '';

                if (str_contains($query, 'account')) {
                    return Http::response(['data' => ['account' => [
                        'id' => 'acc-1',
                        'email' => 'ops@umkendari.ac.id',
                        'name' => 'UM Kendari',
                        'organizations' => [['id' => 'org-1', 'name' => 'UMK']],
                    ]]]);
                }

                return Http::response(['data' => ['channels' => [
                    ['id' => 'ch1', 'service' => 'facebook', 'name' => 'UM Kendari', 'displayName' => 'UM Kendari', 'isQueuePaused' => false],
                    ['id' => 'ch2', 'service' => 'instagram', 'name' => 'umkendari', 'displayName' => '@umkendari', 'isQueuePaused' => true],
                ]]]);
            },
        ]);
    }

    public function test_page_warns_when_not_configured(): void
    {
        config()->set('services.buffer.client_id', null);
        config()->set('services.buffer.client_secret', null);
        config()->set('services.buffer.access_token', null);

        $this->actingAs($this->admin())->get(route('buffer'))
            ->assertOk()
            ->assertSee('Kredensial belum diatur');
    }

    public function test_connect_is_blocked_without_oauth_credentials(): void
    {
        config()->set('services.buffer.client_id', null);

        $this->actingAs($this->admin())->get(route('buffer.connect'))->assertStatus(503);
    }

    public function test_connect_redirects_to_buffer_with_pkce(): void
    {
        $this->configureBuffer();

        $response = $this->actingAs($this->admin())->get(route('buffer.connect'));

        $response->assertRedirect();
        $location = $response->headers->get('Location');

        $this->assertStringStartsWith('https://auth.buffer.test/auth?', $location);
        $this->assertStringContainsString('code_challenge_method=S256', $location);
        $this->assertStringContainsString('code_challenge=', $location);
        $this->assertNotNull(session('buffer_oauth_state'));
        $this->assertNotNull(session('buffer_oauth_verifier'));
    }

    public function test_non_manager_cannot_reach_buffer(): void
    {
        $creator = User::factory()->create([
            'role_id' => Role::where('slug', 'content-creator')->value('id'),
        ]);

        $this->actingAs($creator)->get(route('buffer'))->assertForbidden();
        $this->actingAs($creator)->get(route('buffer.connect'))->assertForbidden();
    }

    public function test_callback_exchanges_code_and_syncs_channels(): void
    {
        $this->configureBuffer();
        $this->fakeBufferApi();

        $admin = $this->admin();
        $this->withSession([
            'buffer_oauth_state' => 'state-123',
            'buffer_oauth_verifier' => 'verifier-abc',
        ]);

        $this->actingAs($admin)
            ->get(route('buffer.callback', ['code' => 'auth-code', 'state' => 'state-123']))
            ->assertRedirect(route('buffer'))
            ->assertSessionHas('status');

        $connection = BufferConnection::current();
        $this->assertNotNull($connection);
        $this->assertSame('ya29-token', $connection->access_token);
        $this->assertSame('refresh-1', $connection->refresh_token);
        $this->assertSame('acc-1', $connection->buffer_user_id);
        $this->assertSame('org-1', $connection->organization_id);
        $this->assertSame($admin->id, $connection->connected_by);
        $this->assertNotNull($connection->token_expires_at);
        $this->assertSame(2, $connection->channels()->count());
        $this->assertDatabaseHas('social_channels', ['service' => 'facebook', 'username' => 'UM Kendari', 'is_active' => true]);
        $this->assertDatabaseHas('social_channels', ['service' => 'instagram', 'is_active' => false]);

        Http::assertSent(fn ($request) => $request->url() === 'https://auth.buffer.test/token'
            && $request['grant_type'] === 'authorization_code'
            && $request['code_verifier'] === 'verifier-abc');
    }

    public function test_callback_rejects_state_mismatch(): void
    {
        $this->configureBuffer();
        $this->withSession(['buffer_oauth_state' => 'real', 'buffer_oauth_verifier' => 'v']);

        $this->actingAs($this->admin())
            ->get(route('buffer.callback', ['code' => 'x', 'state' => 'forged']))
            ->assertStatus(419);

        $this->assertNull(BufferConnection::current());
    }

    public function test_callback_surfaces_invalid_client_error(): void
    {
        $this->configureBuffer();
        Http::fake([
            'https://auth.buffer.test/token' => Http::response(['error' => 'invalid_client'], 401),
        ]);

        $this->withSession(['buffer_oauth_state' => 's', 'buffer_oauth_verifier' => 'v']);

        $this->actingAs($this->admin())
            ->get(route('buffer.callback', ['code' => 'c', 'state' => 's']))
            ->assertRedirect(route('buffer'))
            ->assertSessionHas('error');

        $this->assertNull(BufferConnection::current());
    }

    public function test_connect_with_static_access_token(): void
    {
        config()->set('services.buffer.client_id', null);
        config()->set('services.buffer.client_secret', null);
        config()->set('services.buffer.access_token', 'static-token-123');
        config()->set('services.buffer.api_base', 'https://api.buffer.test');

        Http::fake([
            'https://api.buffer.test*' => function ($request) {
                $query = $request->data()['query'] ?? '';
                if (str_contains($query, 'account')) {
                    return Http::response(['data' => ['account' => [
                        'id' => 'buf-9', 'email' => 'me@umkendari.ac.id',
                        'organizations' => [['id' => 'org-9']],
                    ]]]);
                }

                return Http::response(['data' => ['channels' => [
                    ['id' => 'p9', 'service' => 'linkedin', 'name' => 'UM Kendari'],
                ]]]);
            },
        ]);

        $this->actingAs($this->admin())->post(route('buffer.connect-token'))
            ->assertRedirect(route('buffer'))
            ->assertSessionHas('status');

        $connection = BufferConnection::current();
        $this->assertSame('static-token-123', $connection->access_token);
        $this->assertSame('org-9', $connection->organization_id);
        $this->assertSame(1, $connection->channels()->count());
    }

    public function test_access_token_is_encrypted_at_rest(): void
    {
        $connection = BufferConnection::create([
            'access_token' => 'super-secret-token',
            'connected_by' => $this->admin()->id,
        ]);

        $raw = DB::table('buffer_connections')->where('id', $connection->id)->value('access_token');

        $this->assertNotSame('super-secret-token', $raw);
        $this->assertSame('super-secret-token', $connection->fresh()->access_token);
    }

    public function test_disconnect_removes_connection_and_channels(): void
    {
        $this->configureBuffer();

        $connection = BufferConnection::create([
            'access_token' => 'tok',
            'connected_by' => $this->admin()->id,
        ]);
        SocialChannel::create([
            'buffer_connection_id' => $connection->id,
            'buffer_profile_id' => 'p1',
            'service' => 'facebook',
        ]);

        $this->actingAs($this->admin())
            ->delete(route('buffer.disconnect'))
            ->assertRedirect(route('buffer'));

        $this->assertDatabaseCount('buffer_connections', 0);
        $this->assertDatabaseCount('social_channels', 0);
    }
}
