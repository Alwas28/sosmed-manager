<?php

use App\Enums\ContentStatus;
use App\Enums\Platform;
use App\Models\Content;
use App\Services\Content\ContentApprovalService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.admin-layout', ['title' => 'Detail Konten', 'subtitle' => 'Rincian konten social media.'])] class extends Component
{
    public Content $content;

    public bool $revising = false;

    public string $note = '';

    public function mount(Content $content): void
    {
        $this->content = $content->load(['creator', 'approver', 'media', 'platforms', 'logs.user', 'approvals.reviewer']);
    }

    public function approve(): void
    {
        Gate::authorize('approval.approve');
        app(ContentApprovalService::class)->approve($this->content, auth()->user());
        $this->content->refresh()->load('approvals.reviewer', 'logs.user');
        session()->flash('status', 'Konten disetujui.');
    }

    public function requestRevision(): void
    {
        Gate::authorize('approval.reject');
        $this->validate(['note' => ['required', 'string', 'min:3', 'max:2000']], attributes: ['note' => 'catatan revisi']);

        app(ContentApprovalService::class)->requestRevision($this->content, auth()->user(), $this->note);
        $this->reset('revising', 'note');
        $this->content->refresh()->load('approvals.reviewer', 'logs.user');
        session()->flash('status', 'Permintaan revisi terkirim.');
    }

    public function submit(): void
    {
        Gate::authorize('content.submit');
        abort_unless($this->content->status->canSubmit(), 422);

        $from = $this->content->status->value;
        $this->content->update(['status' => ContentStatus::PendingApproval]);
        $this->content->logActivity('submitted', $from, ContentStatus::PendingApproval->value);
        $this->content->refresh();
        session()->flash('status', 'Konten dikirim untuk approval.');
    }

    public function withdraw(): void
    {
        Gate::authorize('content.submit');
        abort_unless($this->content->status === ContentStatus::PendingApproval, 422);

        $this->content->update(['status' => ContentStatus::Draft]);
        $this->content->logActivity('withdrawn', ContentStatus::PendingApproval->value, ContentStatus::Draft->value);
        $this->content->refresh();
        session()->flash('status', 'Konten ditarik kembali ke draft.');
    }

    public function delete(): void
    {
        Gate::authorize('content.delete');
        abort_unless($this->content->status->isEditable(), 422);

        $this->content->logActivity('deleted', $this->content->status->value);
        $this->content->delete();

        session()->flash('status', 'Konten dihapus.');
        $this->redirectRoute('konten.index', navigate: true);
    }
}; ?>

<div>
    @include('partials.toast-flash')

    @php($pill = $content->status->pill())

    <div class="toolbar">
        <a class="btn btn-sm btn-ghost" href="{{ route('konten.index') }}" wire:navigate><i class="fa-solid fa-arrow-left"></i> Semua Konten</a>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            @if ($content->status->isEditable())
                @can('content.edit')
                    <a class="btn btn-sm" href="{{ route('konten.edit', $content) }}" wire:navigate><i class="fa-solid fa-pen"></i> Ubah</a>
                @endcan
                @can('content.submit')
                    @if ($content->status->canSubmit())
                        <button class="btn btn-sm btn-primary" wire:click="submit"><i class="fa-solid fa-paper-plane"></i> Kirim Approval</button>
                    @endif
                @endcan
                @can('content.delete')
                    <x-confirm-button message="Hapus konten ini? Tindakan ini tidak dapat dibatalkan." action="$wire.delete()">
                        <button type="button" class="btn btn-sm btn-danger"><i class="fa-solid fa-trash"></i> Hapus</button>
                    </x-confirm-button>
                @endcan
            @elseif ($content->status === \App\Enums\ContentStatus::PendingApproval)
                @can('approval.approve')
                    <x-confirm-button
                        message="Setujui konten ini?"
                        action="$wire.approve()"
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
                    <button class="btn btn-sm" wire:click="$set('revising', true)">
                        <i class="fa-solid fa-rotate-left"></i> Minta Revisi
                    </button>
                @endcan
                @can('content.submit')
                    <button class="btn btn-sm btn-ghost" wire:click="withdraw"><i class="fa-solid fa-rotate-left"></i> Tarik ke Draft</button>
                @endcan
            @endif
        </div>
    </div>

    @if ($revising)
        <div class="panel">
            <label class="field-label">Catatan revisi untuk pembuat</label>
            <textarea class="input" rows="3" wire:model="note" placeholder="Jelaskan yang perlu diperbaiki…"></textarea>
            @error('note')<div class="field-error">{{ $message }}</div>@enderror
            <div style="display:flex;gap:8px;margin-top:8px;">
                <button class="btn btn-sm btn-danger" wire:click="requestRevision"><i class="fa-solid fa-paper-plane"></i> Kirim Permintaan Revisi</button>
                <button class="btn btn-sm btn-ghost" wire:click="$set('revising', false)"><i class="fa-solid fa-xmark"></i> Batal</button>
            </div>
        </div>
    @endif

    @php($revNote = $content->pendingRevisionNote())
    @if ($revNote)
        <div class="flash flash-error"><strong>Perlu revisi:</strong> {{ $revNote }}</div>
    @endif

    <div class="panel">
        <span class="pill" style="background:var({{ $pill[0] }});color:var({{ $pill[1] }});">{{ $content->status->label() }}</span>
        <h2 class="panel-title" style="margin-top:10px;font-size:18px;">{{ $content->title }}</h2>

        <div class="chip-row" style="margin:10px 0 16px;">
            @php($cat = $content->category ?? \App\Enums\ContentCategory::Umum)
            <span class="chip" style="background:color-mix(in srgb,var(--accent) 14%,transparent);color:var(--accent);"><i class="{{ $cat->icon() }}"></i> {{ $cat->label() }}</span>
            @forelse ($content->platforms as $p)
                <span class="chip"><i class="{{ \App\Enums\Platform::tryIcon($p->platform) }}"></i> {{ \App\Enums\Platform::tryLabel($p->platform) }}</span>
            @empty
                <span class="chip">Tanpa platform</span>
            @endforelse
        </div>

        @if ($content->media->isNotEmpty())
            <div class="media-grid" style="margin-bottom:16px;">
                @foreach ($content->media as $m)
                    <div class="media-tile">
                        @if ($m->isVideo())
                            <video class="media-thumb" src="{{ $m->url() }}" controls muted></video><span class="media-badge">VIDEO</span>
                        @else
                            <img class="media-thumb" src="{{ $m->url() }}" alt="">
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        <div class="preview-body preview-caption" style="background:var(--surface-alt);border-radius:8px;padding:12px 14px;">{{ $content->caption ?: 'Tanpa caption.' }}</div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-top:16px;font-size:12.5px;">
            <div><div class="field-label">Jadwal posting</div>{{ $content->scheduled_at?->format('d M Y H:i') ?? '—' }}</div>
            <div><div class="field-label">Dibuat oleh</div>{{ $content->creator?->name ?? '—' }}</div>
            <div><div class="field-label">Disetujui oleh</div>{{ $content->approver?->name ?? '—' }}</div>
            <div><div class="field-label">Dipublikasikan</div>{{ $content->published_at?->format('d M Y H:i') ?? '—' }}</div>
        </div>
    </div>

    @if ($content->approvals->isNotEmpty())
        <div class="panel">
            <h2 class="panel-title">Riwayat Approval</h2>
            <div class="timeline">
                @foreach ($content->approvals as $approval)
                    @php($apill = $approval->decision->pill())
                    <div class="timeline-item">
                        <span class="pill" style="background:var({{ $apill[0] }});color:var({{ $apill[1] }});">{{ $approval->decision->label() }}</span>
                        @if ($approval->note) — {{ $approval->note }}@endif
                        <div class="t-when">{{ $approval->reviewer?->name ?? 'Reviewer' }} · {{ $approval->created_at->diffForHumans() }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($content->logs->isNotEmpty())
        <div class="panel">
            <h2 class="panel-title">Riwayat</h2>
            <div class="timeline">
                @foreach ($content->logs as $log)
                    <div class="timeline-item">
                        <strong>{{ \Illuminate\Support\Str::headline($log->action) }}</strong>
                        @if ($log->to_status) → {{ \App\Enums\ContentStatus::from($log->to_status)->label() }}@endif
                        @if ($log->note) · {{ $log->note }}@endif
                        <div class="t-when">{{ $log->user?->name ?? 'Sistem' }} · {{ $log->created_at->diffForHumans() }}</div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
