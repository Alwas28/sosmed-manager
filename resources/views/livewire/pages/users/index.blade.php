<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.admin-layout', ['title' => 'Pengguna', 'subtitle' => 'Kelola pengguna dan role yang melekat.'])] class extends Component
{
    use WithPagination;

    public function updateRole(int $userId, ?string $roleId): void
    {
        Gate::authorize('user.manage');

        $user = User::findOrFail($userId);
        $user->role_id = $roleId !== null && $roleId !== '' ? (int) $roleId : null;
        $user->save();

        session()->flash('status', 'Role untuk '.$user->name.' diperbarui.');
    }

    public function with(): array
    {
        return [
            'users' => User::with('role')->orderBy('name')->paginate(15),
            'roles' => Role::orderByDesc('is_locked')->orderBy('name')->get(),
        ];
    }
}; ?>

<div>
    @include('partials.toast-flash')

    <div class="toolbar">
        <p class="panel-sub" style="margin:0;">{{ $users->total() }} pengguna terdaftar.</p>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Nama</th>
                    <th>Email</th>
                    <th style="min-width:180px;">Role</th>
                    <th>Terverifikasi</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($users as $user)
                    <tr>
                        <td class="title-text">{{ $user->name }}</td>
                        <td class="date-cell">{{ $user->email }}</td>
                        <td>
                            @can('user.manage')
                                <select class="select"
                                        wire:change="updateRole({{ $user->id }}, $event.target.value)">
                                    <option value="">— Tanpa role —</option>
                                    @foreach ($roles as $r)
                                        <option value="{{ $r->id }}" @selected($user->role_id === $r->id)>{{ $r->name }}</option>
                                    @endforeach
                                </select>
                            @else
                                <span class="badge">{{ $user->role?->name ?? 'Tanpa role' }}</span>
                            @endcan
                        </td>
                        <td class="date-cell">{{ $user->email_verified_at ? 'Ya' : 'Belum' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div style="margin-top:16px;">
        {{ $users->links() }}
    </div>
</div>
