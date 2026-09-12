<?php

namespace Tests\Feature;

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Role;
use App\Models\User;
use App\Services\Content\ContentPublishService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The step after the Approval Workflow (Blueprint §8/§10): Disetujui →
 * Terjadwal → Dipublikasikan. Buffer's real publish (`createPost`) isn't
 * implemented yet — no user has connected a real Buffer account to test
 * against — so publishViaBuffer() is expected to fail honestly (Buffer
 * Exception "Buffer belum terhubung.") and move the content to Gagal with
 * the reason logged, rather than silently doing nothing. That's exactly
 * what's asserted here; markPublishedManually() is the practical fallback.
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

    public function test_publish_without_buffer_connection_fails_honestly_and_logs_the_reason(): void
    {
        $publisher = $this->userWithRole('publisher');
        $content = $this->approvedContent();

        $this->actingAs($publisher);

        Volt::test('pages.contents.show', ['content' => $content])
            ->call('publishNow')
            ->assertSee('Buffer belum terhubung', false);

        $content->refresh();
        $this->assertSame(ContentStatus::Failed, $content->status);
        $this->assertDatabaseHas('content_logs', [
            'content_id' => $content->id,
            'action' => 'publish_failed',
            'note' => 'Buffer belum terhubung.',
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
