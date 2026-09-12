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
