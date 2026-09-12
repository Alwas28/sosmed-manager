<?php

use App\Models\Role;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.admin-layout', ['title' => 'Role Akses', 'subtitle' => 'Kelola daftar role pengguna sistem.'])] class extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $description = '';

    public function with(): array
    {
        return [
            'roles' => Role::withCount(['permissions', 'users'])
                ->orderByDesc('is_locked')
                ->orderBy('name')
                ->get(),
        ];
    }

    public function newRole(): void
    {
        Gate::authorize('role.manage');
        $this->reset(['editingId', 'name', 'description']);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        Gate::authorize('role.manage');
        $role = Role::findOrFail($id);
        $this->editingId = $role->id;
        $this->name = $role->name;
        $this->description = (string) $role->description;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function save(): void
    {
        Gate::authorize('role.manage');

        $data = $this->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('roles', 'name')->ignore($this->editingId)],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        if ($this->editingId) {
            $role = Role::findOrFail($this->editingId);
            $role->update([
                'name' => $data['name'],
                'description' => $data['description'] ?: null,
                'slug' => $role->is_locked ? $role->slug : Str::slug($data['name']),
            ]);
        } else {
            Role::create([
                'name' => $data['name'],
                'slug' => Str::slug($data['name']),
                'description' => $data['description'] ?: null,
            ]);
        }

        $this->reset(['showForm', 'editingId', 'name', 'description']);
        session()->flash('status', 'Role berhasil disimpan.');
    }

    public function delete(int $id): void
    {
        Gate::authorize('role.manage');
        $role = Role::withCount('users')->findOrFail($id);

        if ($role->is_locked) {
            session()->flash('error', 'Role bawaan tidak dapat dihapus.');

            return;
        }

        if ($role->users_count > 0) {
            session()->flash('error', 'Role masih dipakai oleh '.$role->users_count.' pengguna.');

            return;
        }

        $role->delete();
        session()->flash('status', 'Role dihapus.');
    }
}; ?>

<div>
    @include('partials.toast-flash')

    <div class="toolbar">
        <p class="panel-sub" style="margin:0;">{{ $roles->count() }} role terdaftar.</p>
        @can('role.manage')
            <button class="btn btn-primary btn-sm" type="button" wire:click="newRole">
                <i class="fa-solid fa-plus"></i> Role Baru
            </button>
        @endcan
    </div>

    @if ($showForm)
        <div class="panel" style="margin-bottom:16px;">
            <h2 class="panel-title">{{ $editingId ? 'Ubah Role' : 'Role Baru' }}</h2>
            <div class="field">
                <label class="field-label">Nama Role</label>
                <input type="text" class="input" wire:model="name" placeholder="mis. Content Manager">
                @error('name')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label class="field-label">Deskripsi</label>
                <textarea class="input" rows="2" wire:model="description" placeholder="Ringkasan tugas role ini"></textarea>
                @error('description')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            <div style="display:flex;gap:8px;">
                <button class="btn btn-primary btn-sm" type="button" wire:click="save"><i class="fa-solid fa-floppy-disk"></i> Simpan</button>
                <button class="btn btn-sm" type="button" wire:click="$set('showForm', false)"><i class="fa-solid fa-xmark"></i> Batal</button>
            </div>
        </div>
    @endif

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Role</th>
                    <th>Slug</th>
                    <th>Permission</th>
                    <th>Pengguna</th>
                    <th style="text-align:right;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($roles as $role)
                    <tr>
                        <td>
                            <div class="title-text">{{ $role->name }}</div>
                            @if ($role->description)
                                <div class="p-desc">{{ $role->description }}</div>
                            @endif
                            @if ($role->is_locked)
                                <span class="badge badge-lock" style="margin-top:6px;">bawaan</span>
                            @endif
                        </td>
                        <td class="date-cell">{{ $role->slug }}</td>
                        <td>
                            <span class="badge badge-accent">
                                {{ $role->slug === 'administrator' ? 'semua akses' : $role->permissions_count.' permission' }}
                            </span>
                        </td>
                        <td class="date-cell">{{ $role->users_count }}</td>
                        <td class="action-cell">
                            @can('access.manage')
                                <a class="btn btn-sm" href="{{ route('access-control.index', ['role' => $role->id]) }}" wire:navigate><i class="fa-solid fa-key"></i> Atur Akses</a>
                            @endcan
                            @can('role.manage')
                                <button class="btn btn-sm" type="button" wire:click="edit({{ $role->id }})"><i class="fa-solid fa-pen"></i> Ubah</button>
                                @unless ($role->is_locked)
                                    <x-confirm-button message="Hapus role “{{ $role->name }}”? Tindakan ini tidak dapat dibatalkan." action="$wire.delete({{ $role->id }})">
                                        <button type="button" class="btn btn-sm btn-danger"><i class="fa-solid fa-trash"></i> Hapus</button>
                                    </x-confirm-button>
                                @endunless
                            @endcan
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
