<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('components.admin-layout', ['title' => 'Akses Kontrol', 'subtitle' => 'Atur hak akses (permission) untuk masing-masing role.'])] class extends Component
{
    #[Url]
    public ?int $role = null;

    /** @var array<int, int|string> */
    public array $selected = [];

    public function mount(): void
    {
        $current = $this->role ? Role::find($this->role) : null;
        $current ??= Role::orderByDesc('is_locked')->orderBy('name')->first();

        $this->role = $current?->id;
        $this->loadSelected();
    }

    public function currentRole(): ?Role
    {
        return $this->role ? Role::with('permissions')->find($this->role) : null;
    }

    public function loadSelected(): void
    {
        $role = $this->currentRole();
        $this->selected = $role ? $role->permissions->pluck('id')->all() : [];
    }

    public function switchRole(int $id): void
    {
        $this->role = $id;
        $this->loadSelected();
    }

    public function toggleGroup(string $group): void
    {
        $ids = Permission::where('group', $group)->pluck('id')->all();
        $selected = array_map('intval', $this->selected);
        $missing = array_diff($ids, $selected);

        $this->selected = $missing === []
            ? array_values(array_diff($selected, $ids))
            : array_values(array_unique(array_merge($selected, $ids)));
    }

    public function save(): void
    {
        Gate::authorize('access.manage');

        $role = $this->currentRole();
        abort_unless($role !== null, 404);

        if ($role->isAdministrator()) {
            session()->flash('error', 'Administrator selalu memiliki akses penuh.');

            return;
        }

        $role->permissions()->sync(array_map('intval', $this->selected));
        session()->flash('status', 'Akses untuk role '.$role->name.' berhasil diperbarui.');
    }

    public function with(): array
    {
        return [
            'roles' => Role::orderByDesc('is_locked')->orderBy('name')->get(),
            'groups' => Permission::orderBy('id')->get()->groupBy('group'),
            'activeRole' => $this->currentRole(),
        ];
    }
}; ?>

<div>
    @include('partials.toast-flash')

    <div class="role-tabs">
        @foreach ($roles as $r)
            <button type="button"
                    class="role-tab {{ $activeRole && $activeRole->id === $r->id ? 'active' : '' }}"
                    wire:click="switchRole({{ $r->id }})">
                {{ $r->name }}
            </button>
        @endforeach
    </div>

    @if (! $activeRole)
        <div class="panel">
            <p class="panel-sub" style="margin:0;">Belum ada role. Buat role terlebih dahulu di menu Role Akses.</p>
        </div>
    @elseif ($activeRole->isAdministrator())
        <div class="panel">
            <h2 class="panel-title">{{ $activeRole->name }}</h2>
            <p class="panel-sub" style="margin:0;">
                Role Administrator otomatis memiliki seluruh akses sistem dan tidak dapat diubah.
            </p>
        </div>
    @else
        <div class="toolbar">
            <p class="panel-sub" style="margin:0;">
                {{ count($selected) }} permission aktif untuk <strong>{{ $activeRole->name }}</strong>.
            </p>
            @can('access.manage')
                <button class="btn btn-primary btn-sm" type="button" wire:click="save">
                    <i class="fa-solid fa-floppy-disk"></i> Simpan Perubahan
                </button>
            @endcan
        </div>

        @foreach ($groups as $group => $perms)
            <div class="perm-group">
                <div class="perm-group-head">
                    <span>{{ $group }}</span>
                    @can('access.manage')
                        <button class="btn btn-sm btn-ghost" type="button" wire:click="toggleGroup(@js($group))">
                            <i class="fa-solid fa-list-check"></i> Pilih / hapus semua
                        </button>
                    @endcan
                </div>
                <div class="perm-list">
                    @foreach ($perms as $perm)
                        <label class="perm-item">
                            <input type="checkbox" value="{{ $perm->id }}" wire:model="selected"
                                   @cannot('access.manage') disabled @endcannot>
                            <span>
                                <span class="p-name">{{ $perm->name }}</span><br>
                                <span class="p-desc">{{ $perm->slug }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </div>
        @endforeach
    @endif
</div>
