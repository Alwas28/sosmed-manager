<?php

use App\Models\Media;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('components.admin-layout', ['title' => 'Pustaka Media', 'subtitle' => 'Foto & video yang dipakai konten.'])] class extends Component
{
    use WithFileUploads;
    use WithPagination;

    public array $uploads = [];

    public function updatedUploads(): void
    {
        Gate::authorize('media.upload');

        $this->validate([
            'uploads.*' => ['file', 'max:20480', 'mimes:jpg,jpeg,png,gif,webp,mp4,mov,webm'],
        ]);

        foreach ($this->uploads as $file) {
            Media::create([
                'disk' => 'public',
                'path' => $file->store('media/'.now()->format('Y/m'), 'public'),
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'type' => str_starts_with((string) $file->getMimeType(), 'video') ? 'video' : 'image',
                'size' => $file->getSize(),
                'uploaded_by' => auth()->id(),
            ]);
        }

        $this->uploads = [];
        session()->flash('status', 'Media diunggah.');
    }

    public function delete(int $id): void
    {
        Gate::authorize('media.delete');

        $media = Media::withCount('contents')->findOrFail($id);

        if ($media->contents_count > 0) {
            session()->flash('error', 'Media ini dipakai '.$media->contents_count.' konten dan tidak bisa dihapus.');

            return;
        }

        $media->delete();
        session()->flash('status', 'Media dihapus.');
    }

    public function with(): array
    {
        return [
            'items' => Media::with('uploadedBy')->withCount('contents')->latest()->paginate(24),
        ];
    }
}; ?>

<div>
    @include('partials.toast-flash')

    @can('media.upload')
        <div class="panel" style="margin-bottom:16px;">
            <label class="field-label">Unggah media</label>
            <input type="file" wire:model="uploads" multiple accept="image/*,video/*" class="input" style="padding:7px;">
            <div wire:loading wire:target="uploads" class="field-hint">Mengunggah…</div>
            <p class="field-hint" style="margin-bottom:0;">JPG, PNG, GIF, WEBP, MP4, MOV, WEBM · maks 20 MB.</p>
        </div>
    @endcan

    @if ($items->isEmpty())
        <div class="panel" style="text-align:center;padding:48px;color:var(--text-muted);">Belum ada media.</div>
    @else
        <div class="media-grid">
            @foreach ($items as $media)
                <div class="media-tile">
                    @if ($media->isVideo())
                        <video class="media-thumb" src="{{ $media->url() }}" muted></video><span class="media-badge">VIDEO</span>
                    @elseif ($media->isAiMade())
                        <img class="media-thumb" src="{{ $media->url() }}" alt=""><span class="media-badge" title="{{ $media->ai_model }}"><i class="fa-solid fa-wand-magic-sparkles"></i> AI</span>
                    @elseif ($media->isTemplateComposed())
                        <img class="media-thumb" src="{{ $media->url() }}" alt=""><span class="media-badge"><i class="fa-solid fa-stamp"></i> Template</span>
                    @else
                        <img class="media-thumb" src="{{ $media->url() }}" alt="">
                    @endif
                    @can('media.delete')
                        <x-confirm-button message="Hapus “{{ $media->original_name }}”? Tindakan ini tidak dapat dibatalkan." action="$wire.delete({{ $media->id }})">
                            <button type="button" class="media-x" title="Hapus">&times;</button>
                        </x-confirm-button>
                    @endcan
                    <div class="media-name" title="{{ $media->original_name }}">
                        {{ $media->original_name }}
                        @if ($media->contents_count)<span class="badge" style="font-size:9px;">{{ $media->contents_count }}×</span>@endif
                    </div>
                </div>
            @endforeach
        </div>

        <div style="margin-top:16px;">{{ $items->links() }}</div>
    @endif
</div>
