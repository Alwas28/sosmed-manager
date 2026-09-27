<?php

use App\Enums\Platform;
use App\Models\ActivityLog;
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
        $previous = $user->role?->name ?? 'tanpa role';
        $user->role_id = $roleId !== null && $roleId !== '' ? (int) $roleId : null;
        $user->save();
        ActivityLog::record('user_role_changed', "{$user->name}: {$previous} → ".($user->fresh()->role?->name ?? 'tanpa role'), $user);

        session()->flash('status', 'Role untuk '.$user->name.' diperbarui.');
    }

    public function togglePlatform(int $userId, string $platform): void
    {
        Gate::authorize('user.platform');
        abort_unless(in_array($platform, Platform::values(), true), 422);

        $user = User::findOrFail($userId);
        abort_if($user->isAdministrator(), 422, 'Administrator selalu bisa memposting ke semua platform.');

        $allowed = $user->allowed_platforms ?? Platform::values();
        $allowed = in_array($platform, $allowed, true)
            ? array_values(array_diff($allowed, [$platform]))
            : [...$allowed, $platform];

        // Semua platform terpilih = tanpa pembatasan (ikut otomatis kalau ada platform baru).
        $user->allowed_platforms = count($allowed) === count(Platform::values()) ? null : array_values($allowed);
        $user->save();
        $summary = $user->allowed_platforms === null
            ? 'semua platform'
            : (implode(', ', array_map(fn ($p) => Platform::tryLabel($p), $user->allowed_platforms)) ?: 'tidak ada platform');
        ActivityLog::record('user_platform_changed', "{$user->name}: {$summary}", $user);

        session()->flash('status', 'Akses posting '.$user->name.' diperbarui.');
    }

    public function with(): array
    {
        return [
            'users' => User::with('role')->orderBy('name')->paginate(15),
            'roles' => Role::orderByDesc('is_locked')->orderBy('name')->get(),
            'platforms' => Platform::cases(),
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
                    <th style="min-width:260px;">Boleh Posting ke</th>
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
                        <td>
                            @if ($user->isAdministrator())
                                <span class="badge badge-accent">Semua platform</span>
                            @else
                                <div class="chip-row">
                                    @foreach ($platforms as $platform)
                                        @php($on = in_array($platform->value, $user->allowed_platforms ?? \App\Enums\Platform::values(), true))
                                        @can('user.platform')
                                            <button type="button" class="type-choice {{ $on ? 'on' : '' }}" style="background:none;"
                                                    wire:click="togglePlatform({{ $user->id }}, '{{ $platform->value }}')"
                                                    title="{{ $platform->label() }}: {{ $on ? 'boleh' : 'tidak boleh' }} posting">
                                                <i class="{{ $platform->icon() }}"></i> {{ $platform->label() }}
                                            </button>
                                        @else
                                            <span class="type-choice {{ $on ? 'on' : '' }}" style="cursor:default;">
                                                <i class="{{ $platform->icon() }}"></i> {{ $platform->label() }}
                                            </span>
                                        @endcan
                                    @endforeach
                                </div>
                            @endif
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
