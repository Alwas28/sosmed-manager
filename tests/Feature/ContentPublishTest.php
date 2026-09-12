<?php

namespace Tests\Feature;

use App\Enums\ContentStatus;
use App\Models\BufferConnection;
use App\Models\Content;
use App\Models\ContentPlatform;
use App\Models\Role;
use App\Models\SocialChannel;
use App\Models\User;
use App\Services\Content\ContentPublishService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The step after the Approval Workflow (Blueprint §8/§10): Disetujui →
 * Terjadwal → Dipublikasikan. A content's platform never has a Buffer
 * channel picked for it (no picker UI exists), so publishViaBuffer() must
 * resolve one automatically by matching platform → SocialChannel::service —
 * and fail with a clear, actionable message (not a generic one) when no
 * matching channel is connected yet. Buffer's real publish (`createPost`)
 * itself also isn't implemented yet, so even with a channel resolved the
 * flow still fails honestly one layer further in — every failure moves the
 * content to Gagal with the real reason logged, never silently no-ops.
 * markPublishedManually() is the practical fallback.
 */
class ContentPublishTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $slug)->value('id')]);
    }

    private function approvedContent(): Content
    {
        return Content::create([
            'title' => 'Konten Disetujui',
            'status' => ContentStatus::Approved,
            'created_by' => $this->userWithRole('content-creator')->id,
        ]);
    }

    public function test_publisher_can_schedule_approved_content(): void
    {
        $publisher = $this->userWithRole('publisher');
        $content = $this->approvedContent();

        $this->actingAs($publisher);

        Volt::test('pages.contents.show', ['content' => $content])
            ->call('startScheduling')
            ->set('scheduleAt', now()->addDays(3)->format('Y-m-d\TH:i'))
            ->call('schedule')
            ->assertHasNoErrors();

        $content->refresh();
        $this->assertSame(ContentStatus::Scheduled, $content->status);
        $this->assertNotNull($content->scheduled_at);
        $this->assertDatabaseHas('content_logs', ['content_id' => $content->id, 'action' => 'scheduled']);
    }

    public function test_content_creator_cannot_schedule(): void
    {
        $creator = $this->userWithRole('content-creator');
        $content = $this->approvedContent();

        $this->actingAs($creator);

        Volt::test('pages.contents.show', ['content' => $content])->call('startScheduling');

        // Never left Disetujui — the gate denied it before anything changed.
        $this->assertSame(ContentStatus::Approved, $content->fresh()->status);
    }

    public function test_scheduling_a_non_approved_content_is_rejected(): void
    {
        $publisher = $this->userWithRole('publisher');
        $content = Content::create([
            'title' => 'Masih Draft',
            'status' => ContentStatus::Draft,
            'created_by' => $publisher->id,
        ]);

        $this->actingAs($publisher);

        app(ContentPublishService::class);
        $this->expectException(HttpException::class);
        app(ContentPublishService::class)->schedule($content, $publisher, now()->addDay());
    }

    public function test_cancel_schedule_reverts_to_approved(): void
    {
        $publisher = $this->userWithRole('publisher');
        $content = $this->approvedContent();
        app(ContentPublishService::class)->schedule($content, $publisher, now()->addDay());

        $this->actingAs($publisher);
        Volt::test('pages.contents.show', ['content' => $content])->call('cancelSchedule');

        $content->refresh();
        $this->assertSame(ContentStatus::Approved, $content->status);
        $this->assertNull($content->scheduled_at);
    }

    public function test_publish_without_any_connected_channel_fails_with_an_actionable_message(): void
    {
        // The real gap the user hit: content_platforms.social_channel_id is
        // never set by any UI yet, and no SocialChannel exists at all here —
        // this must fail with a clear "go connect a channel" message, not a
        // generic/unrelated one, and never silently succeed.
        $publisher = $this->userWithRole('publisher');
        $content = $this->approvedContent();
        ContentPlatform::create(['content_id' => $content->id, 'platform' => 'facebook']);

        $this->actingAs($publisher);

        Volt::test('pages.contents.show', ['content' => $content])
            ->call('publishNow')
            ->assertSee('Belum ada channel Buffer yang terhubung', false)
            ->assertSee('Facebook', false);

        $content->refresh();
        $this->assertSame(ContentStatus::Failed, $content->status);
        $this->assertDatabaseHas('content_logs', ['content_id' => $content->id, 'action' => 'publish_failed']);
    }

    /** Sets up an Approved content targeting Facebook with a real matching channel connected. */
    private function contentWithResolvableChannel(): Content
    {
        $content = $this->approvedContent();
        ContentPlatform::create(['content_id' => $content->id, 'platform' => 'facebook']);
        $connection = BufferConnection::create(['access_token' => 'fake-token-for-test']);
        SocialChannel::create([
            'buffer_connection_id' => $connection->id,
            'buffer_profile_id' => 'abc123',
            'service' => 'facebook',
            'display_name' => 'Halaman Test',
            'is_active' => true,
        ]);

        return $content;
    }

    public function test_publish_succeeds_with_a_real_createpost_response(): void
    {
        // The channel resolves automatically (no manual "pick a channel"
        // step exists yet) purely by matching platform -> SocialChannel
        // service, and a successful `createPost` response is stored.
        config()->set('services.buffer.api_base', 'https://api.buffer.test');
        Http::fake([
            'https://api.buffer.test*' => Http::response(['data' => ['createPost' => [
                '__typename' => 'PostActionSuccess',
                'post' => ['id' => 'post-123', 'text' => 'hi', 'dueAt' => null],
            ]]]),
        ]);

        $publisher = $this->userWithRole('publisher');
        $content = $this->contentWithResolvableChannel();

        $this->actingAs($publisher);

        Volt::test('pages.contents.show', ['content' => $content])
            ->call('publishNow')
            ->assertSee('Dipublikasikan');

        $content->refresh();
        $this->assertSame(ContentStatus::Published, $content->status);
        $this->assertNotNull($content->published_at);
        $this->assertSame(['post-123'], $content->buffer_post_ids);

        Http::assertSent(function ($request) {
            $input = $request->data()['variables']['input'] ?? [];

            return $input['channelId'] === 'abc123'
                && $input['mode'] === 'shareNow'
                && $input['schedulingType'] === 'automatic';
        });
    }

    public function test_publish_refreshes_an_expiring_access_token_before_calling_buffer(): void
    {
        // Regression test: an earlier version of BufferPostService used
        // BufferConnection::access_token straight, un-refreshed — worked
        // right after connecting, broke for real once the token actually
        // expired ("Access token is not valid" on the deployed server).
        config()->set('services.buffer.client_id', 'test-client');
        config()->set('services.buffer.client_secret', 'test-secret');
        config()->set('services.buffer.token_url', 'https://auth.buffer.test/token');
        config()->set('services.buffer.api_base', 'https://api.buffer.test');

        Http::fake([
            'https://auth.buffer.test/token' => Http::response([
                'access_token' => 'brand-new-token',
                'refresh_token' => 'new-refresh',
                'expires_in' => 3600,
            ]),
            'https://api.buffer.test*' => Http::response(['data' => ['createPost' => [
                '__typename' => 'PostActionSuccess',
                'post' => ['id' => 'post-999', 'text' => 'hi', 'dueAt' => null],
            ]]]),
        ]);

        $publisher = $this->userWithRole('publisher');
        $content = $this->approvedContent();
        ContentPlatform::create(['content_id' => $content->id, 'platform' => 'facebook']);
        $connection = BufferConnection::create([
            'access_token' => 'stale-expired-token',
            'refresh_token' => 'old-refresh',
            'token_expires_at' => now()->subMinutes(10),
        ]);
        SocialChannel::create([
            'buffer_connection_id' => $connection->id,
            'buffer_profile_id' => 'abc123',
            'service' => 'facebook',
            'display_name' => 'Halaman Test',
            'is_active' => true,
        ]);

        $this->actingAs($publisher);

        Volt::test('pages.contents.show', ['content' => $content])->call('publishNow');

        $content->refresh();
        $this->assertSame(ContentStatus::Published, $content->status);

        // The refreshed token — not the stale one — must be what actually
        // reached Buffer's createPost call.
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.buffer.test')
            && $request->hasHeader('Authorization', 'Bearer brand-new-token'));
        $this->assertSame('brand-new-token', $connection->fresh()->access_token);
    }

    public function test_publish_surfaces_a_mutation_error_from_buffer(): void
    {
        // A real Buffer-side rejection (MutationError, e.g. InvalidInputError)
        // must fail the content honestly with Buffer's own message, not a
        // generic one.
        config()->set('services.buffer.api_base', 'https://api.buffer.test');
        Http::fake([
            'https://api.buffer.test*' => Http::response(['data' => ['createPost' => [
                '__typename' => 'InvalidInputError',
                'message' => 'Caption melebihi batas karakter platform ini.',
            ]]]),
        ]);

        $publisher = $this->userWithRole('publisher');
        $content = $this->contentWithResolvableChannel();

        $this->actingAs($publisher);

        Volt::test('pages.contents.show', ['content' => $content])
            ->call('publishNow')
            ->assertSee('Caption melebihi batas karakter platform ini.', false);

        $content->refresh();
        $this->assertSame(ContentStatus::Failed, $content->status);
        $this->assertDatabaseHas('content_logs', [
            'content_id' => $content->id,
            'action' => 'publish_failed',
        ]);
    }

    public function test_mark_published_manually_moves_to_published(): void
    {
        $publisher = $this->userWithRole('publisher');
        $content = $this->approvedContent();

        $this->actingAs($publisher);

        Volt::test('pages.contents.show', ['content' => $content])->call('markPublishedManually');

        $content->refresh();
        $this->assertSame(ContentStatus::Published, $content->status);
        $this->assertNotNull($content->published_at);
        $this->assertDatabaseHas('content_logs', ['content_id' => $content->id, 'action' => 'published_manually']);
    }

    public function test_reviewer_cannot_publish(): void
    {
        $reviewer = $this->userWithRole('reviewer');
        $content = $this->approvedContent();

        $this->actingAs($reviewer);
        Volt::test('pages.contents.show', ['content' => $content])->call('markPublishedManually');

        $this->assertSame(ContentStatus::Approved, $content->fresh()->status);
    }
}
