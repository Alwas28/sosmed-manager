<?php

use App\Enums\ContentStatus;
use App\Enums\Platform;
use App\Models\Content;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.admin-layout', ['title' => 'Konten', 'subtitle' => 'Kelola konten social media sebelum dijadwalkan ke Buffer.'])] class extends Component
{
    use WithPagination;

    #[Url]
    public string $q = '';

    public ?ContentStatus $status = null;

    public function mount(): void
    {
        $this->status = ContentStatus::fromRouteName(request()->route()->getName());
    }

    public function submit(int $id): void
    {
        Gate::authorize('content.submit');
        $content = Content::findOrFail($id);
        abort_unless($content->status->canSubmit(), 422);

        $from = $content->status->value;
        $content->update(['status' => ContentStatus::PendingApproval]);
        $content->logActivity('submitted', $from, ContentStatus::PendingApproval->value);
        session()->flash('status', 'Konten dikirim untuk approval.');
    }

    public function withdraw(int $id): void
    {
        Gate::authorize('content.submit');
        $content = Content::findOrFail($id);
        abort_unless($content->status === ContentStatus::PendingApproval, 422);

        $content->update(['status' => ContentStatus::Draft]);
        $content->logActivity('withdrawn', ContentStatus::PendingApproval->value, ContentStatus::Draft->value);
        session()->flash('status', 'Konten ditarik kembali ke draft.');
    }

    public function delete(int $id): void
    {
        Gate::authorize('content.delete');
        $content = Content::findOrFail($id);
        abort_unless($content->status->isEditable(), 422, 'Konten yang sudah diproses tidak dapat dihapus.');

        $content->logActivity('deleted', $content->status->value);
        $content->delete();
        session()->flash('status', 'Konten dihapus.');
    }

    public function search(): void
    {
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->q = '';
        $this->resetPage();
    }

    public function updatedQ(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'contents' => Content::query()
                ->with(['creator', 'platforms', 'media'])
                ->withCount('media')
                ->when($this->status, fn ($query) => $query->where('status', $this->status->value))
                ->when($this->q !== '', fn ($query) => $query->where('title', 'like', '%'.$this->q.'%'))
                ->latest()
                ->paginate(15),
            'tabs' => ContentStatus::cases(),
        ];
    }
}; ?>

<div>
    @include('partials.toast-flash')

    <div class="toolbar">
        <form wire:submit.prevent="search" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <input type="search" class="input" style="width:260px;max-width:60vw;"
                   placeholder="Cari judul konten…" wire:model="q"
                   wire:keydown.enter.prevent="search">
            <button type="submit" class="btn btn-sm">
                <i class="fa-solid fa-magnifying-glass"></i> Cari
            </button>
            @if ($q !== '')
                <button type="button" class="btn btn-sm btn-ghost" wire:click="clearSearch">
                    <i class="fa-solid fa-xmark"></i> Reset
                </button>
            @endif
        </form>
        @can('content.create')
            <a class="btn btn-primary btn-sm" href="{{ route('konten.create') }}" wire:navigate>
                <i class="fa-solid fa-plus"></i> Konten Baru
            </a>
        @endcan
    </div>

    @if ($q !== '')
        <p class="panel-sub" style="margin:-4px 0 12px;">Hasil pencarian untuk “{{ $q }}” — {{ $contents->total() }} konten.</p>
    @endif

    <div class="role-tabs">
        <a class="role-tab {{ request()->routeIs('konten.index') ? 'active' : '' }}" href="{{ route('konten.index') }}" wire:navigate>Semua</a>
        @foreach ($tabs as $tab)
            <a class="role-tab {{ $status === $tab ? 'active' : '' }}" href="{{ route($tab->route()) }}" wire:navigate>{{ $tab->label() }}</a>
        @endforeach
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Judul</th>
                    <th>Platform</th>
                    <th>Status</th>
                    <th>Jadwal</th>
                    <th>Dibuat oleh</th>
                    <th style="text-align:right;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($contents as $content)
                    @php($pill = $content->status->pill())
                    <tr>
                        <td>
                            <a class="title-text" href="{{ route('konten.show', $content) }}" wire:navigate
                               style="color:var(--accent);text-decoration:none;">{{ $content->title }}</a>
                            <div class="date-cell" style="font-size:11px;">
                                @php($cat = $content->category ?? \App\Enums\ContentCategory::Umum)
                                <i class="{{ $cat->icon() }}"></i> {{ $cat->label() }} ·
                                @if ($content->media_count)<i class="fa-solid fa-paperclip"></i> {{ $content->media_count }} media · @endif
                                {{ \Illuminate\Support\Str::limit(strip_tags((string) $content->caption), 50) ?: 'Tanpa caption' }}
                            </div>
                        </td>
                        <td>
                            <div class="chip-row">
                                @foreach ($content->platforms as $p)
                                    <span class="chip"><i class="{{ \App\Enums\Platform::tryIcon($p->platform) }}"></i> {{ \App\Enums\Platform::tryLabel($p->platform) }}</span>
                                @endforeach
                            </div>
                        </td>
                        <td><span class="pill" style="background:var({{ $pill[0] }});color:var({{ $pill[1] }});">{{ $content->status->label() }}</span></td>
                        <td class="date-cell">{{ $content->scheduled_at?->format('d M Y H:i') ?? '—' }}</td>
                        <td class="date-cell">{{ $content->creator?->name ?? '—' }}</td>
                        <td class="action-cell">
                            <a class="btn btn-sm" href="{{ route('konten.show', $content) }}" wire:navigate><i class="fa-solid fa-eye"></i> Lihat</a>
                            @if ($content->status->isEditable())
                                @can('content.edit')
                                    <a class="btn btn-sm" href="{{ route('konten.edit', $content) }}" wire:navigate><i class="fa-solid fa-pen"></i> Ubah</a>
                                @endcan
                                @can('content.submit')
                                    @if ($content->status->canSubmit())
                                        <button class="btn btn-sm" wire:click="submit({{ $content->id }})"><i class="fa-solid fa-paper-plane"></i> Kirim Approval</button>
                                    @endif
                                @endcan
                                @can('content.delete')
                                    <x-confirm-button
                                        message="Hapus konten “{{ $content->title }}”? Tindakan ini tidak dapat dibatalkan."
                                        action="$wire.delete({{ $content->id }})"
                                    >
                                        <button type="button" class="btn btn-sm btn-danger"><i class="fa-solid fa-trash"></i> Hapus</button>
                                    </x-confirm-button>
                                @endcan
                            @elseif ($content->status === \App\Enums\ContentStatus::PendingApproval)
                                @can('content.submit')
                                    <button class="btn btn-sm" wire:click="withdraw({{ $content->id }})"><i class="fa-solid fa-rotate-left"></i> Tarik ke Draft</button>
                                @endcan
                            @elseif ($content->status === \App\Enums\ContentStatus::Approved)
                                @can('schedule.manage')
                                    <a class="btn btn-sm btn-primary" href="{{ route('konten.show', $content) }}" wire:navigate><i class="fa-solid fa-calendar-plus"></i> Jadwalkan</a>
                                @endcan
                            @elseif ($content->status === \App\Enums\ContentStatus::Scheduled)
                                @can('publish.manage')
                                    <a class="btn btn-sm btn-primary" href="{{ route('konten.show', $content) }}" wire:navigate><i class="fa-solid fa-paper-plane"></i> Publikasikan</a>
                                @endcan
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="text-align:center;padding:40px;color:var(--text-muted);">Belum ada konten{{ $status ? ' berstatus '.$status->label() : '' }}.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div style="margin-top:16px;">{{ $contents->links() }}</div>
</div>
