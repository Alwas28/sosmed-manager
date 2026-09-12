<?php

namespace Tests\Feature;

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Covers the shared <x-confirm-button> component (replaces the plain
 * `wire:confirm` native browser popup on every delete/approve button) —
 * Konten's list page stands in for the rest since they all use the same
 * component with the same markup contract.
 */
class ConfirmModalTest extends TestCase
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

    public function test_delete_button_uses_the_styled_modal_not_the_native_confirm(): void
    {
        $creator = $this->userWithRole('content-creator');
        $content = Content::create([
            'title' => 'Konten Untuk Dihapus',
            'status' => ContentStatus::Draft,
            'created_by' => $creator->id,
        ]);

        $this->actingAs($creator);

        Volt::test('pages.contents.index')
            ->assertDontSee('wire:confirm', false)
            ->assertSee('x-teleport', false)
            ->assertSee('Hapus konten “Konten Untuk Dihapus”?')
            ->assertSee('$wire.delete('.$content->id.')', false);
    }

    public function test_confirmed_delete_still_removes_the_content(): void
    {
        $creator = $this->userWithRole('content-creator');
        $content = Content::create([
            'title' => 'Konten Sementara',
            'status' => ContentStatus::Draft,
            'created_by' => $creator->id,
        ]);

        $this->actingAs($creator);

        // The modal is a client-side (Alpine) affordance only — the actual
        // delete still goes through the same Livewire method the old
        // wire:confirm button called, so calling it directly here exercises
        // exactly what the modal's "Ya, Lanjutkan" button triggers.
        Volt::test('pages.contents.index')->call('delete', $content->id);

        $this->assertSoftDeleted('contents', ['id' => $content->id]);
    }
}
