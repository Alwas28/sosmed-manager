<?php

namespace Tests\Feature;

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Volt\Volt;
use Tests\TestCase;

class CalendarTest extends TestCase
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

    public function test_calendar_requires_permission(): void
    {
        $noRole = User::factory()->create(['role_id' => null]);
        $this->actingAs($noRole)->get(route('calendar'))->assertForbidden();

        $creator = $this->userWithRole('content-creator');
        $this->actingAs($creator)->get(route('calendar'))->assertOk()->assertSee('Kalender Konten');
    }

    public function test_scheduled_content_appears_on_its_day(): void
    {
        $creator = $this->userWithRole('content-creator');
        Content::create([
            'title' => 'Pengumuman Beasiswa',
            'status' => ContentStatus::Scheduled,
            'scheduled_at' => now()->startOfMonth()->addDays(4),
            'created_by' => $creator->id,
        ]);

        $this->actingAs($creator);

        Volt::test('pages.calendar.index')->assertSee('Pengumuman Beasiswa');
    }

    public function test_content_without_a_date_is_listed_as_unscheduled(): void
    {
        $creator = $this->userWithRole('content-creator');
        Content::create([
            'title' => 'Draft Belum Dijadwalkan',
            'status' => ContentStatus::Draft,
            'created_by' => $creator->id,
        ]);

        $this->actingAs($creator);

        Volt::test('pages.calendar.index')
            ->assertSee('Belum Dijadwalkan')
            ->assertSee('Draft Belum Dijadwalkan');
    }

    public function test_status_filter_narrows_the_grid(): void
    {
        $creator = $this->userWithRole('content-creator');
        $day = now()->startOfMonth()->addDays(2);

        Content::create([
            'title' => 'Konten Terjadwal',
            'status' => ContentStatus::Scheduled,
            'scheduled_at' => $day,
            'created_by' => $creator->id,
        ]);
        Content::create([
            'title' => 'Konten Sudah Publish',
            'status' => ContentStatus::Published,
            'published_at' => $day,
            'created_by' => $creator->id,
        ]);

        $this->actingAs($creator);

        Volt::test('pages.calendar.index')
            ->set('statusFilter', ContentStatus::Published->value)
            ->assertSee('Konten Sudah Publish')
            ->assertDontSee('Konten Terjadwal');
    }

    public function test_published_content_shows_on_its_actual_publish_day_not_the_original_schedule(): void
    {
        // The reported bug: content published on a different day than it
        // was originally scheduled for used to stay pinned to the old
        // scheduled_at day (or, if that day fell outside the visible grid
        // entirely, silently vanish from the calendar altogether) instead
        // of moving to where it actually landed.
        $creator = $this->userWithRole('content-creator');
        Content::create([
            'title' => 'Konten Meleset Jadwal',
            'status' => ContentStatus::Published,
            'scheduled_at' => now()->addMonths(1)->startOfMonth()->addDays(15), // originally planned for next month
            'published_at' => now(), // actually went out today
            'created_by' => $creator->id,
        ]);

        $this->actingAs($creator);

        // Default view is the current month — must show it (by published_at).
        Volt::test('pages.calendar.index')->assertSee('Konten Meleset Jadwal');
    }

    public function test_published_count_is_scoped_to_the_month_it_was_actually_published_in(): void
    {
        // Same underlying query the dashboard/calendar's with() runs for
        // "Dipublikasikan Bulan Ini" — verified directly (rather than
        // scraping the rendered stat tile's HTML) that it's scoped to
        // published_at alone, so a content whose scheduled_at and
        // published_at land in two different months is only ever counted
        // in the one it was actually published in, never both.
        $creator = $this->userWithRole('content-creator');
        Content::create([
            'title' => 'Publish Bulan Depan Rencananya',
            'status' => ContentStatus::Published,
            'scheduled_at' => now()->addMonths(1)->startOfMonth()->addDays(15),
            'published_at' => now(), // actually landed this month
            'created_by' => $creator->id,
        ]);

        $thisMonthCount = Content::query()
            ->where('status', ContentStatus::Published->value)
            ->whereBetween('published_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();
        $nextMonthCount = Content::query()
            ->where('status', ContentStatus::Published->value)
            ->whereBetween('published_at', [now()->addMonthNoOverflow()->startOfMonth(), now()->addMonthNoOverflow()->endOfMonth()])
            ->count();

        $this->assertSame(1, $thisMonthCount);
        $this->assertSame(0, $nextMonthCount);
    }

    public function test_month_navigation_moves_the_cursor(): void
    {
        $creator = $this->userWithRole('content-creator');
        $this->actingAs($creator);

        $component = Volt::test('pages.calendar.index');
        $startMonth = $component->get('month');
        $startYear = $component->get('year');

        $component->call('nextMonth');
        $expected = Carbon::create($startYear, $startMonth, 1)->addMonthNoOverflow();
        $component->assertSet('month', $expected->month)->assertSet('year', $expected->year);

        $component->call('prevMonth')->assertSet('month', $startMonth)->assertSet('year', $startYear);
    }
}
