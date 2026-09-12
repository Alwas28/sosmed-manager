<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AdminMenu;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AdminShellTest extends TestCase
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

    public function test_guest_root_redirects_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_register_route_is_gone(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_admin_can_open_access_screens(): void
    {
        $admin = $this->userWithRole('administrator');

        $this->actingAs($admin)->get(route('dashboard'))->assertOk();
        $this->actingAs($admin)->get(route('roles.index'))->assertOk()->assertSee('Role Akses');
        $this->actingAs($admin)->get(route('access-control.index'))->assertOk()->assertSee('Akses Kontrol');
        $this->actingAs($admin)->get(route('users.index'))->assertOk();
        $this->actingAs($admin)->get(route('konten.draft'))->assertOk()->assertSee('Draft');
    }

    public function test_content_creator_cannot_open_role_screens(): void
    {
        $creator = $this->userWithRole('content-creator');

        $this->actingAs($creator)->get(route('roles.index'))->assertForbidden();
        $this->actingAs($creator)->get(route('access-control.index'))->assertForbidden();
        $this->actingAs($creator)->get(route('dashboard'))->assertOk();
    }

    public function test_module_routes_are_permission_guarded(): void
    {
        $creator = $this->userWithRole('content-creator');
        $reviewer = $this->userWithRole('reviewer');

        // Content Creator: konten + media yes, approval + laporan no.
        $this->actingAs($creator)->get(route('konten.index'))->assertOk();
        $this->actingAs($creator)->get(route('media'))->assertOk();
        $this->actingAs($creator)->get(route('approval.queue'))->assertForbidden();
        $this->actingAs($creator)->get(route('reports'))->assertForbidden();

        // Reviewer: approval + laporan yes, media + buffer no.
        $this->actingAs($reviewer)->get(route('approval.queue'))->assertOk();
        $this->actingAs($reviewer)->get(route('reports'))->assertOk();
        $this->actingAs($reviewer)->get(route('media'))->assertForbidden();
        $this->actingAs($reviewer)->get(route('buffer'))->assertForbidden();
    }

    public function test_admin_menu_visibility_follows_permissions(): void
    {
        $reviewer = $this->userWithRole('reviewer');

        $sections = AdminMenu::visibleSections($reviewer);
        $labels = collect($sections)->flatten(1)->pluck('label');

        $this->assertContains('Dashboard', $labels);          // permission null — selalu tampil
        $this->assertContains('Antrian Approval', $labels);   // approval.view
        $this->assertContains('Laporan', $labels);            // report.view
        $this->assertNotContains('Pustaka Media', $labels);   // tidak punya media.view
        $this->assertNotContains('Role Akses', $labels);      // tidak punya role.view
        $this->assertArrayNotHasKey('Pengaturan', $sections);

        // Administrator melihat semua.
        $adminLabels = collect(AdminMenu::visibleSections($this->userWithRole('administrator')))
            ->flatten(1)->pluck('label');
        $this->assertContains('Akses Kontrol', $adminLabels);
        $this->assertContains('Log Aktivitas', $adminLabels);
    }

    public function test_access_control_sync_updates_role_permissions(): void
    {
        $admin = $this->userWithRole('administrator');
        $reviewer = Role::where('slug', 'reviewer')->first();
        $this->actingAs($admin);

        Volt::test('pages.access-control.index', ['role' => $reviewer->id])
            ->set('selected', [])
            ->call('save');

        $this->assertSame(0, $reviewer->fresh()->permissions()->count());
    }

    public function test_administrator_permissions_cannot_be_emptied(): void
    {
        $admin = $this->userWithRole('administrator');
        $adminRole = Role::where('slug', 'administrator')->first();
        $this->actingAs($admin);

        Volt::test('pages.access-control.index', ['role' => $adminRole->id])
            ->set('selected', [])
            ->call('save');

        $this->assertGreaterThan(0, $adminRole->fresh()->permissions()->count());
    }
}
