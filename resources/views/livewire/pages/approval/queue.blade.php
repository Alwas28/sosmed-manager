<?php

use App\Enums\ContentStatus;
use App\Enums\Platform;
use App\Models\Content;
use App\Services\Content\ContentApprovalService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.admin-layout', ['title' => 'Antrian Approval', 'subtitle' => 'Tinjau konten yang menunggu persetujuan.'])] class extends Component
{
    use WithPagination;

    public ?int $revisingId = null;

    public string $note = '';

    public function approve(int $id): void
    {
        Gate::authorize('approval.approve');

        $content = Content::findOrFail($id);
        app(ContentApprovalService::class)->approve($content, auth()->user());

        session()->flash('status', 'Konten “'.$content->title.'” disetujui.');
    }

    public function startRevision(int $id): void
    {
        Gate::authorize('approval.reject');
        $this->revisingId = $id;
        $this->note = '';
        $this->resetValidation();
    }

    public function cancelRevision(): void
    {
        $this->reset('revisingId', 'note');
    }

    public function requestRevision(): void
    {
        Gate::authorize('approval.reject');

        $this->validate([
            'note' => ['required', 'string', 'min:3', 'max:2000'],
        ], attributes: ['note' => 'catatan revisi']);

        $content = Content::findOrFail($this->revisingId);
        app(ContentApprovalService::class)->requestRevision($content, auth()->user(), $this->note);

        session()->flash('status', 'Revisi diminta untuk “'.$content->title.'”.');
        $this->reset('revisingId', 'note');
    }

    public function with(): array
    {
        return [
            'items' => Content::query()
                ->with(['creator', 'platforms'])
                ->withCount('media')
                ->where('status', ContentStatus::PendingApproval->value)
                ->oldest('updated_at')
                ->paginate(15),
        ];
    }
}; ?>

<div>
    @include('partials.toast-flash')

    @if ($items->isEmpty())
        <div class="panel" style="text-align:center;padding:48px;color:var(--text-muted);">
            <div style="font-size:28px;margin-bottom:10px;"><i class="fa-solid fa-mug-hot"></i></div>
            Tidak ada konten yang menunggu approval.
        </div>
    @else
        <p class="panel-sub">{{ $items->total() }} konten menunggu ditinjau.</p>

        @foreach ($items as $content)
            <div class="panel" style="margin-bottom:14px;">
                <div class="toolbar" style="margin-bottom:10px;">
                    <div>
                        <a class="panel-title" style="color:var(--accent);text-decoration:none;display:block;"
                           href="{{ route('konten.show', $content) }}" wire:navigate>{{ $content->title }}</a>
                        <p class="panel-sub" style="margin:2px 0 0;">
                            oleh {{ $content->creator?->name ?? '—' }} ·
                            dikirim {{ $content->updated_at->diffForHumans() }} ·
                            {{ $content->media_count }} media
                        </p>
                    </div>
                    <div class="chip-row">
                        @foreach ($content->platforms as $p)
                            <span class="chip"><i class="{{ \App\Enums\Platform::tryIcon($p->platform) }}"></i> {{ \App\Enums\Platform::tryLabel($p->platform) }}</span>
                        @endforeach
                    </div>
                </div>

                @if ($content->caption)
                    <div class="preview-body preview-caption" style="background:var(--surface-alt);border-radius:8px;padding:10px 12px;font-size:12.5px;">{{ \Illuminate\Support\Str::limit($content->caption, 240) }}</div>
                @endif

                @if ($revisingId === $content->id)
                    <div style="margin-top:12px;">
                        <label class="field-label">Catatan revisi untuk pembuat</label>
                        <textarea class="input" rows="3" wire:model="note" placeholder="Jelaskan yang perlu diperbaiki…"></textarea>
                        @error('note')<div class="field-error">{{ $message }}</div>@enderror
                        <div style="display:flex;gap:8px;margin-top:8px;">
                            <button class="btn btn-sm btn-danger" wire:click="requestRevision"><i class="fa-solid fa-paper-plane"></i> Kirim Permintaan Revisi</button>
                            <button class="btn btn-sm btn-ghost" wire:click="cancelRevision"><i class="fa-solid fa-xmark"></i> Batal</button>
                        </div>
                    </div>
                @else
                    <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap;">
                        <a class="btn btn-sm" href="{{ route('konten.show', $content) }}" wire:navigate><i class="fa-solid fa-eye"></i> Lihat Detail</a>
                        @can('approval.approve')
                            <x-confirm-button
                                message="Setujui konten “{{ $content->title }}”?"
                                action="$wire.approve({{ $content->id }})"
                                title="Setujui Konten"
                                confirmLabel="Ya, Setujui"
                                confirmClass="btn-primary"
                                icon="fa-solid fa-circle-check"
                                iconColor="var(--status-posted-text)"
                            >
                                <button type="button" class="btn btn-sm btn-primary">
                                    <i class="fa-solid fa-circle-check"></i> Setujui
                                </button>
                            </x-confirm-button>
                        @endcan
                        @can('approval.reject')
                            <button class="btn btn-sm" wire:click="startRevision({{ $content->id }})">
                                <i class="fa-solid fa-rotate-left"></i> Minta Revisi
                            </button>
                        @endcan
                    </div>
                @endif
            </div>
        @endforeach

        <div style="margin-top:16px;">{{ $items->links() }}</div>
    @endif
</div>
