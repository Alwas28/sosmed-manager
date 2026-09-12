<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Covers the shared `partials.toast-flash` include (used by every admin page
 * that has a create/update/delete action) rather than re-testing every page
 * individually — Roles exercises both a success and a blocked/error path,
 * and is a representative stand-in for the rest.
 */
class ToastNotificationTest extends TestCase
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

    public function test_fresh_page_load_dispatches_no_toast(): void
    {
        $this->actingAs($this->userWithRole('administrator'));

        Volt::test('pages.roles.index')->assertDontSee("dispatch('toast'", false);
    }

    public function test_successful_save_dispatches_a_success_toast(): void
    {
        $this->actingAs($this->userWithRole('administrator'));

        Volt::test('pages.roles.index')
            ->call('newRole')
            ->set('name', 'Toast Test Role')
            ->call('save')
            ->assertSee("dispatch('toast'", false)
            ->assertSee('Role berhasil disimpan.');

        $this->assertDatabaseHas('roles', ['name' => 'Toast Test Role']);
    }

    public function test_blocked_delete_dispatches_an_error_toast(): void
    {
        $this->actingAs($this->userWithRole('administrator'));
        $locked = Role::where('slug', 'administrator')->first();

        Volt::test('pages.roles.index')
            ->call('delete', $locked->id)
            ->assertSee("dispatch('toast'", false)
            ->assertSee('Role bawaan tidak dapat dihapus.');

        // The blocked delete must not have actually removed anything.
        $this->assertDatabaseHas('roles', ['id' => $locked->id]);
    }
}
