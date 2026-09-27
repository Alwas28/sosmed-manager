<?php

namespace Tests\Feature;

use App\Enums\ContentCategory;
use App\Enums\ContentStatus;
use App\Enums\Platform;
use App\Models\Content;
use App\Models\ContentPlatform;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ReportsTest extends TestCase
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

    public function test_content_creator_cannot_view_reports(): void
    {
        $this->actingAs($this->userWithRole('content-creator'))->get(route('reports'))->assertForbidden();
    }

    public function test_reviewer_can_view_reports(): void
    {
        $this->actingAs($this->userWithRole('reviewer'))->get(route('reports'))
            ->assertOk()
            ->assertSee('Laporan');
    }

    public function test_default_range_counts_only_content_created_within_it(): void
    {
        $creator = $this->userWithRole('reviewer');

        Content::create(['title' => 'Dalam Rentang', 'status' => ContentStatus::Published, 'created_by' => $creator->id]);
        $old = Content::create(['title' => 'Di Luar Rentang', 'status' => ContentStatus::Draft, 'created_by' => $creator->id]);
        $old->forceFill(['created_at' => now()->subDays(90)])->save();

        $this->actingAs($creator);

        Volt::test('pages.reports.index')
            ->assertSee('Dalam Rentang')
            ->assertDontSee('Di Luar Rentang');
    }

    public function test_status_platform_and_category_breakdowns_reflect_real_content(): void
    {
        $creator = $this->userWithRole('reviewer');

        $a = Content::create([
            'title' => 'Konten Berita', 'status' => ContentStatus::Published,
            'category' => ContentCategory::Berita, 'created_by' => $creator->id,
        ]);
        ContentPlatform::create(['content_id' => $a->id, 'platform' => Platform::Facebook->value]);
        ContentPlatform::create(['content_id' => $a->id, 'platform' => Platform::Instagram->value]);

        Content::create([
            'title' => 'Konten Gagal', 'status' => ContentStatus::Failed,
            'category' => ContentCategory::Iklan, 'created_by' => $creator->id,
        ]);

        $this->actingAs($creator);

        Volt::test('pages.reports.index')
            ->assertSeeInOrder(['Total Konten', '2'])
            ->assertSee('Berita')
            ->assertSee('Iklan / Promosi')
            ->assertSee('Facebook')
            ->assertSee('Instagram')
            ->assertSee('Konten Berita')
            ->assertSee('Konten Gagal');
    }

    public function test_success_rate_is_based_on_published_vs_failed(): void
    {
        $creator = $this->userWithRole('reviewer');
        Content::create(['title' => 'Sukses 1', 'status' => ContentStatus::Published, 'created_by' => $creator->id]);
        Content::create(['title' => 'Sukses 2', 'status' => ContentStatus::Published, 'created_by' => $creator->id]);
        Content::create(['title' => 'Gagal 1', 'status' => ContentStatus::Failed, 'created_by' => $creator->id]);

        $this->actingAs($creator);

        // 2 published / (2 published + 1 failed) = 67%.
        Volt::test('pages.reports.index')->assertSee('67%');
    }

    public function test_top_contributor_is_ranked_by_content_count(): void
    {
        $prolific = $this->userWithRole('content-creator');
        $prolific->forceFill(['name' => 'Penulis Rajin'])->save();
        $other = $this->userWithRole('content-creator');
        $other->forceFill(['name' => 'Penulis Santai'])->save();

        Content::create(['title' => 'A', 'status' => ContentStatus::Draft, 'created_by' => $prolific->id]);
        Content::create(['title' => 'B', 'status' => ContentStatus::Draft, 'created_by' => $prolific->id]);
        Content::create(['title' => 'C', 'status' => ContentStatus::Draft, 'created_by' => $other->id]);

        $this->actingAs($this->userWithRole('reviewer'));

        Volt::test('pages.reports.index')->assertSeeInOrder(['Penulis Rajin', '2 konten', 'Penulis Santai', '1 konten']);
    }

    public function test_preset_switches_the_active_range(): void
    {
        $creator = $this->userWithRole('reviewer');
        $this->actingAs($creator);

        $component = Volt::test('pages.reports.index');
        $this->assertSame(now()->subDays(29)->format('Y-m-d'), $component->get('from'));

        $component->call('applyPreset', 'this_year');
        $this->assertSame(now()->startOfYear()->format('Y-m-d'), $component->get('from'));
        $this->assertSame(now()->endOfYear()->format('Y-m-d'), $component->get('to'));
    }

    public function test_custom_range_rejects_an_end_date_before_the_start_date(): void
    {
        $this->actingAs($this->userWithRole('reviewer'));

        Volt::test('pages.reports.index')
            ->set('from', now()->format('Y-m-d'))
            ->set('to', now()->subDay()->format('Y-m-d'))
            ->call('applyCustomRange')
            ->assertHasErrors(['to']);
    }

    public function test_export_streams_a_csv_of_content_in_range(): void
    {
        $creator = $this->userWithRole('reviewer');
        Content::create(['title' => 'Konten Ekspor', 'status' => ContentStatus::Published, 'created_by' => $creator->id]);

        $response = $this->actingAs($creator)->get(route('reports.export', [
            'from' => now()->subDays(7)->format('Y-m-d'),
            'to' => now()->format('Y-m-d'),
        ]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Konten Ekspor', $response->streamedContent());
    }

    public function test_content_creator_cannot_export(): void
    {
        $this->actingAs($this->userWithRole('content-creator'))
            ->get(route('reports.export'))
            ->assertForbidden();
    }
}
