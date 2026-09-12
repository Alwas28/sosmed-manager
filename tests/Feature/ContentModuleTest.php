<?php

namespace Tests\Feature;

use App\Enums\ContentCategory;
use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Media;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ContentModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('public');
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create([
            'role_id' => Role::where('slug', $slug)->value('id'),
        ]);
    }

    public function test_roleless_user_cannot_view_content(): void
    {
        $this->actingAs(User::factory()->create())->get(route('konten.index'))->assertForbidden();
    }

    public function test_creator_sees_list_and_status_tabs(): void
    {
        $this->actingAs($this->userWithRole('content-creator'))->get(route('konten.draft'))
            ->assertOk()
            ->assertSee('Menunggu Approval')
            ->assertSee('Konten Baru');
    }

    public function test_reviewer_cannot_open_create_form(): void
    {
        $this->actingAs($this->userWithRole('reviewer'))->get(route('konten.create'))->assertForbidden();
    }

    public function test_creator_can_save_a_draft_with_platforms_and_media(): void
    {
        $creator = $this->userWithRole('content-creator');
        $media = Media::create([
            'disk' => 'public', 'path' => 'media/x.jpg', 'original_name' => 'x.jpg', 'type' => 'image',
        ]);

        $this->actingAs($creator);

        Volt::test('pages.contents.form')
            ->set('title', 'PMB 2026')
            ->set('caption', 'Pendaftaran dibuka')
            ->set('category', 'pengumuman')
            ->set('platforms', ['facebook', 'instagram'])
            ->set('mediaIds', [$media->id])
            ->call('save', false)
            ->assertRedirect(route('konten.draft'));

        $content = Content::first();
        $this->assertSame('PMB 2026', $content->title);
        $this->assertSame('Pendaftaran dibuka', $content->caption);
        $this->assertSame(ContentCategory::Pengumuman, $content->category);
        $this->assertSame(ContentStatus::Draft, $content->status);
        $this->assertSame($creator->id, $content->created_by);
        $this->assertEqualsCanonicalizing(['facebook', 'instagram'], $content->platforms->pluck('platform')->all());
        $this->assertSame([$media->id], $content->media->pluck('id')->all());
        $this->assertDatabaseHas('content_logs', ['content_id' => $content->id, 'action' => 'created']);
    }

    public function test_save_requires_a_platform(): void
    {
        $this->actingAs($this->userWithRole('content-creator'));

        Volt::test('pages.contents.form')
            ->set('title', 'No platform')
            ->set('platforms', [])
            ->call('save', false)
            ->assertHasErrors('platforms');

        $this->assertDatabaseCount('contents', 0);
    }

    public function test_submit_moves_draft_to_pending_approval(): void
    {
        $creator = $this->userWithRole('content-creator');
        $content = Content::create(['title' => 'D', 'status' => ContentStatus::Draft, 'created_by' => $creator->id]);

        $this->actingAs($creator);

        Volt::test('pages.contents.index')->call('submit', $content->id);

        $this->assertSame(ContentStatus::PendingApproval, $content->fresh()->status);
        $this->assertDatabaseHas('content_logs', ['content_id' => $content->id, 'action' => 'submitted']);
    }

    public function test_processed_content_cannot_be_edited(): void
    {
        $creator = $this->userWithRole('content-creator');
        $content = Content::create(['title' => 'P', 'status' => ContentStatus::Published, 'created_by' => $creator->id]);

        $this->actingAs($creator)->get(route('konten.edit', $content))->assertForbidden();
    }

    public function test_media_upload_stores_file_and_row(): void
    {
        $this->actingAs($this->userWithRole('content-creator'));

        Volt::test('pages.media.index')
            ->set('uploads', [UploadedFile::fake()->image('poster.jpg')]);

        $this->assertSame(1, Media::count());
        $media = Media::first();
        $this->assertSame('image', $media->type);
        Storage::disk('public')->assertExists($media->path);
    }

    public function test_media_in_use_cannot_be_deleted(): void
    {
        $creator = $this->userWithRole('content-creator');
        $media = Media::create(['disk' => 'public', 'path' => 'media/y.jpg', 'original_name' => 'y.jpg', 'type' => 'image']);
        $content = Content::create(['title' => 'C', 'status' => ContentStatus::Draft, 'created_by' => $creator->id]);
        $content->media()->attach($media->id, ['position' => 0]);

        $this->actingAs($creator);
        Volt::test('pages.media.index')->call('delete', $media->id);

        $this->assertDatabaseHas('media', ['id' => $media->id]);
    }

    public function test_any_status_content_is_viewable_via_show_page(): void
    {
        $creator = $this->userWithRole('content-creator');
        $content = Content::create([
            'title' => 'Published item', 'caption' => 'Halo dunia',
            'status' => ContentStatus::Published, 'created_by' => $creator->id,
        ]);

        $this->actingAs($creator)->get(route('konten.show', $content))
            ->assertOk()
            ->assertSee('Published item')
            ->assertSee('Halo dunia');
    }

    public function test_show_route_does_not_shadow_literal_konten_routes(): void
    {
        $creator = $this->userWithRole('content-creator');

        $this->actingAs($creator)->get(route('konten.draft'))->assertOk();
        $this->actingAs($creator)->get(route('konten.create'))->assertOk();
    }

    public function test_search_form_filters_by_title(): void
    {
        $creator = $this->userWithRole('content-creator');
        Content::create(['title' => 'Beasiswa Unggulan', 'status' => ContentStatus::Draft, 'created_by' => $creator->id]);
        Content::create(['title' => 'Wisuda Periode II', 'status' => ContentStatus::Draft, 'created_by' => $creator->id]);

        $this->actingAs($creator);

        Volt::test('pages.contents.index')
            ->set('q', 'beasiswa')
            ->call('search')
            ->assertSee('Beasiswa Unggulan')
            ->assertDontSee('Wisuda Periode II');
    }

    public function test_status_route_filters_the_list(): void
    {
        $creator = $this->userWithRole('content-creator');
        Content::create(['title' => 'Draft one', 'status' => ContentStatus::Draft, 'created_by' => $creator->id]);
        Content::create(['title' => 'Failed one', 'status' => ContentStatus::Failed, 'created_by' => $creator->id]);

        $this->actingAs($creator)->get(route('konten.failed'))
            ->assertSee('Failed one')
            ->assertDontSee('Draft one');
    }
}
