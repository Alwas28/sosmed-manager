<?php

namespace Tests\Feature;

use App\Enums\ApprovalDecision;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $slug)->value('id'),
        ]);
    }

    private function pendingContent(User $creator): Content
    {
        return Content::create([
            'title' => 'PMB 2026',
            'caption' => 'Draft caption',
            'status' => ContentStatus::PendingApproval,
            'created_by' => $creator->id,
        ]);
    }

    public function test_reviewer_sees_queue_creator_does_not(): void
    {
        $creator = $this->userWithRole('content-creator');
        $this->pendingContent($creator);

        $this->actingAs($this->userWithRole('reviewer'))->get(route('approval.queue'))
            ->assertOk()
            ->assertSee('PMB 2026');

        $this->actingAs($creator)->get(route('approval.queue'))->assertForbidden();
    }

    public function test_reviewer_approves_content(): void
    {
        $creator = $this->userWithRole('content-creator');
        $reviewer = $this->userWithRole('reviewer');
        $content = $this->pendingContent($creator);

        $this->actingAs($reviewer);
        Volt::test('pages.approval.queue')->call('approve', $content->id);

        $content->refresh();
        $this->assertSame(ContentStatus::Approved, $content->status);
        $this->assertSame($reviewer->id, $content->approved_by);
        $this->assertDatabaseHas('content_approvals', [
            'content_id' => $content->id,
            'reviewer_id' => $reviewer->id,
            'decision' => ApprovalDecision::Approved->value,
        ]);
        $this->assertDatabaseHas('content_logs', ['content_id' => $content->id, 'action' => 'approved']);
    }

    public function test_revision_requires_a_note(): void
    {
        $reviewer = $this->userWithRole('reviewer');
        $content = $this->pendingContent($this->userWithRole('content-creator'));

        $this->actingAs($reviewer);
        Volt::test('pages.approval.queue')
            ->call('startRevision', $content->id)
            ->set('note', '')
            ->call('requestRevision')
            ->assertHasErrors('note');

        $this->assertSame(ContentStatus::PendingApproval, $content->fresh()->status);
    }

    public function test_revision_moves_content_to_revision_status_with_note(): void
    {
        $creator = $this->userWithRole('content-creator');
        $reviewer = $this->userWithRole('reviewer');
        $content = $this->pendingContent($creator);

        $this->actingAs($reviewer);
        Volt::test('pages.approval.queue')
            ->call('startRevision', $content->id)
            ->set('note', 'Tolong perbaiki tanggal pendaftaran.')
            ->call('requestRevision');

        $content->refresh();
        $this->assertSame(ContentStatus::Revision, $content->status);
        $this->assertNull($content->approved_by);
        $this->assertSame('Tolong perbaiki tanggal pendaftaran.', $content->pendingRevisionNote());

        // The dedicated "Perlu Revisi" list shows it.
        $this->actingAs($creator)->get(route('konten.revision'))
            ->assertOk()
            ->assertSee('PMB 2026')
            ->assertSee('Perlu Revisi');

        // Creator sees the note on the edit form and the detail page.
        $this->actingAs($creator)->get(route('konten.edit', $content))
            ->assertSee('Tolong perbaiki tanggal pendaftaran.');
        $this->actingAs($creator)->get(route('konten.show', $content))
            ->assertSee('Perlu revisi');
    }

    public function test_saving_a_revision_returns_it_to_draft(): void
    {
        $creator = $this->userWithRole('content-creator');
        $content = Content::create([
            'title' => 'Perlu diperbaiki', 'status' => ContentStatus::Revision, 'created_by' => $creator->id,
        ]);

        $this->actingAs($creator);
        Volt::test('pages.contents.form', ['content' => $content])
            ->set('title', 'Sudah diperbaiki')
            ->set('platforms', ['facebook'])
            ->call('save', false);

        $this->assertSame(ContentStatus::Draft, $content->fresh()->status);
    }

    public function test_revision_content_can_be_resubmitted(): void
    {
        $creator = $this->userWithRole('content-creator');
        $content = Content::create([
            'title' => 'X', 'status' => ContentStatus::Revision, 'created_by' => $creator->id,
        ]);

        $this->actingAs($creator);
        Volt::test('pages.contents.index')->call('submit', $content->id);

        $this->assertSame(ContentStatus::PendingApproval, $content->fresh()->status);
    }

    public function test_cannot_approve_content_that_is_not_pending(): void
    {
        $reviewer = $this->userWithRole('reviewer');
        $content = Content::create([
            'title' => 'Draft item', 'status' => ContentStatus::Draft,
            'created_by' => $this->userWithRole('content-creator')->id,
        ]);

        $this->actingAs($reviewer);
        Volt::test('pages.approval.queue')->call('approve', $content->id)->assertStatus(422);

        $this->assertSame(ContentStatus::Draft, $content->fresh()->status);
    }

    public function test_pending_count_badge_shows_for_reviewer(): void
    {
        $creator = $this->userWithRole('content-creator');
        $this->pendingContent($creator);
        $this->pendingContent($creator);

        $this->actingAs($this->userWithRole('reviewer'))->get(route('dashboard'))
            ->assertSee('Antrian Approval');

        // creator has no approval.view → no badge query, menu item hidden entirely
        $this->actingAs($creator)->get(route('dashboard'))->assertDontSee('Antrian Approval');
    }
}
