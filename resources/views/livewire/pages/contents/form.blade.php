<?php

use App\Enums\ContentCategory;
use App\Enums\ContentStatus;
use App\Enums\Platform;
use App\Enums\PostType;
use App\Models\ActivityLog;
use App\Models\Content;
use App\Models\Media;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.admin-layout', ['title' => 'Form Konten', 'subtitle' => 'Judul → Media → Caption → Platform & jenis postingan → Preview → Simpan.'])] class extends Component
{
    use WithFileUploads;

    public ?Content $content = null;

    public string $title = '';

    public string $caption = '';

    public string $category = 'umum';

    /** @var array<int, string> */
    public array $platforms = [];

    /** @var array<string, string> platform => post/reel/story */
    public array $postTypes = [];

    /** @var array<int, int> ordered media ids */
    public array $mediaIds = [];

    public array $uploads = [];

    public function mount(?Content $content = null): void
    {
        if ($content?->exists) {
            Gate::authorize('content.edit');
            abort_unless($content->status->isEditable(), 403, 'Konten yang sudah diproses tidak dapat diubah.');

            $this->content = $content->load(['platforms', 'media']);
            $this->title = $content->title;
            $this->caption = (string) $content->caption;
            $this->category = ($content->category ?? ContentCategory::Umum)->value;
            $this->platforms = $content->platforms->pluck('platform')->all();
            $this->postTypes = $content->platforms->pluck('post_type', 'platform')->all();
            $this->mediaIds = $content->media->pluck('id')->all();
        } else {
            Gate::authorize('content.create');
        }
    }

    /**
     * Jenis postingan yang $user boleh pakai di $platform LEWAT FORM INI —
     * "Bagikan Link" sengaja dikecualikan karena punya form khusus sendiri
     * (Form Posting Link) yang menangani field link_url/link_title/dst;
     * form konten biasa ini tidak punya field itu sama sekali.
     *
     * @return array<int, PostType>
     */
    public function selectablePostTypes(Platform $platform): array
    {
        return array_values(array_filter(
            auth()->user()->allowedPostTypes($platform),
            fn (PostType $t) => $t !== PostType::Link,
        ));
    }

    public function updatedPlatforms(): void
    {
        $next = [];

        foreach ($this->platforms as $value) {
            $platform = Platform::tryFrom($value);

            if ($platform) {
                $allowedTypes = array_map(fn (PostType $t) => $t->value, $this->selectablePostTypes($platform));
                $current = $this->postTypes[$value] ?? null;
                $next[$value] = in_array($current, $allowedTypes, true) ? $current : ($allowedTypes[0] ?? $platform->defaultPostType()->value);
            }
        }

        $this->postTypes = $next;
    }

    public function updatedUploads(): void
    {
        Gate::authorize('media.upload');

        $this->validate([
            'uploads.*' => ['file', 'max:20480', 'mimes:jpg,jpeg,png,gif,webp,mp4,mov,webm'],
        ]);

        foreach ($this->uploads as $file) {
            $isVideo = str_starts_with((string) $file->getMimeType(), 'video');

            $media = Media::create([
                'disk' => 'public',
                'path' => $file->store('media/'.now()->format('Y/m'), 'public'),
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'type' => $isVideo ? 'video' : 'image',
                'size' => $file->getSize(),
                'uploaded_by' => auth()->id(),
            ]);

            $this->mediaIds[] = $media->id;
            ActivityLog::record('media_uploaded', $media->original_name, $media);
        }

        $this->uploads = [];
    }

    public function toggleMedia(int $id): void
    {
        if (in_array($id, $this->mediaIds, true)) {
            $this->mediaIds = array_values(array_diff($this->mediaIds, [$id]));
        } else {
            $this->mediaIds[] = $id;
        }
    }

    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'caption' => ['nullable', 'string', 'max:5000'],
            'category' => ['required', Rule::enum(ContentCategory::class)],
            'platforms' => ['required', 'array', 'min:1'],
            'platforms.*' => [Rule::in(auth()->user()->postablePlatforms())],
            'mediaIds' => ['array'],
            'mediaIds.*' => [Rule::exists('media', 'id')],
        ];
    }

    /**
     * Cross-field checks that mirror what Buffer / the social networks
     * reject, so a bad combination fails here instead of at publish time.
     *
     * @return array<int, string>
     */
    protected function postTypeProblems(): array
    {
        $media = Media::whereIn('id', $this->mediaIds)->get();
        $hasVideo = $media->contains(fn ($m) => $m->isVideo());
        $problems = [];

        foreach (array_unique($this->platforms) as $value) {
            $platform = Platform::from($value);
            $type = PostType::tryFrom($this->postTypes[$value] ?? '') ?? $platform->defaultPostType();
            $label = $platform->label().' ('.$type->label($platform).')';

            if (! in_array($type, $this->selectablePostTypes($platform), true)) {
                $problems[] = "{$label}: jenis postingan tidak tersedia — untuk link, pakai Form Posting Link.";

                continue;
            }

            if (! auth()->user()->canPostTo($value, $type->value)) {
                $problems[] = "{$label}: Anda tidak punya izin memposting dengan jenis ini.";

                continue;
            }

            if ($media->isEmpty() && ($platform->requiresMedia() || $type->needsMedia())) {
                $problems[] = "{$label}: wajib menyertakan media.";

                continue;
            }

            if ($type === PostType::Reel && ! $hasVideo) {
                $problems[] = "{$label}: wajib memakai video.";
            }

            if ($type->needsMedia() && $media->count() > 1) {
                $problems[] = "{$label}: hanya boleh satu media (sekarang {$media->count()}).";
            }
        }

        return $problems;
    }

    public function save(bool $submit = false): void
    {
        $editing = (bool) $this->content?->exists;
        Gate::authorize($editing ? 'content.edit' : 'content.create');

        if ($submit) {
            Gate::authorize('content.submit');
        }

        $data = $this->validate();

        if ($problems = $this->postTypeProblems()) {
            foreach ($problems as $problem) {
                $this->addError('postType'.md5($problem), $problem);
            }

            return;
        }

        $content = $this->content ?? new Content([
            'created_by' => auth()->id(),
            'status' => ContentStatus::Draft,
        ]);

        $content->fill([
            'title' => $data['title'],
            'caption' => $data['caption'] ?: null,
            'category' => $data['category'],
        ]);

        if ($submit) {
            $content->status = ContentStatus::PendingApproval;
        } elseif ($content->status === ContentStatus::Revision) {
            $content->status = ContentStatus::Draft;
        }

        $content->save();

        $content->platforms()->delete();
        foreach (array_unique($this->platforms) as $value) {
            $content->platforms()->create([
                'platform' => $value,
                'post_type' => $this->postTypes[$value] ?? PostType::Post->value,
            ]);
        }

        $sync = [];
        foreach (array_values($this->mediaIds) as $position => $mediaId) {
            $sync[$mediaId] = ['position' => $position];
        }
        $content->media()->sync($sync);

        $content->logActivity(
            $editing ? 'updated' : 'created',
            null,
            $content->status->value,
            $submit ? 'Dikirim untuk approval' : null,
        );

        session()->flash('status', $submit
            ? 'Konten dikirim untuk approval.'
            : 'Konten disimpan sebagai draft.');

        $this->redirectRoute($submit ? 'konten.approval' : 'konten.draft', navigate: true);
    }

    public function with(): array
    {
        return [
            'allPlatforms' => Platform::cases(),
            'postable' => auth()->user()->postablePlatforms(),
            'allCategories' => ContentCategory::cases(),
            'selected' => Media::whereIn('id', $this->mediaIds)->get()
                ->sortBy(fn ($m) => array_search($m->id, $this->mediaIds, true))
                ->values(),
            'canSubmit' => Gate::allows('content.submit'),
        ];
    }
}; ?>

<div>
    @if ($errors->isNotEmpty())
        <div class="flash flash-error">
            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    @if ($content && ($revNote = $content->pendingRevisionNote()))
        <div class="flash flash-error"><strong>Catatan revisi dari reviewer:</strong> {{ $revNote }}</div>
    @endif

    <div class="form-steps">
        <div class="form-step">
            <div class="form-step-title">Kategori Konten</div>
            <select class="select" style="max-width:280px;" wire:model.live="category">
                @foreach ($allCategories as $cat)
                    <option value="{{ $cat->value }}">{{ $cat->label() }}</option>
                @endforeach
            </select>
        </div>

        <div class="form-step">
            <div class="form-step-title">Judul Konten</div>
            <input type="text" class="input" wire:model.blur="title" placeholder="mis. Penerimaan Mahasiswa Baru 2026">
        </div>

        <div class="form-step">
            <div class="form-step-title">Media (foto / video)</div>
            <input type="file" wire:model="uploads" multiple accept="image/*,video/*" class="input" style="padding:7px;">
            <div wire:loading wire:target="uploads" class="field-hint">Mengunggah…</div>
            <p class="field-hint">Maks 20 MB per berkas. Story &amp; Reel butuh tepat satu media.</p>

            @if ($selected->isNotEmpty())
                <p class="field-label" style="margin-top:12px;">Dipakai di konten ini ({{ $selected->count() }})</p>
                <div class="media-grid" style="margin-bottom:14px;">
                    @foreach ($selected as $m)
                        <div class="media-tile">
                            @if ($m->isVideo())
                                <video class="media-thumb" src="{{ $m->url() }}" muted></video><span class="media-badge">VIDEO</span>
                            @else
                                <img class="media-thumb" src="{{ $m->url() }}" alt="">
                            @endif
                            <button type="button" class="media-x" wire:click="toggleMedia({{ $m->id }})" title="Lepas">&times;</button>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="form-step">
            <div class="form-step-title">Caption</div>
            @php($capLimit = collect($platforms)->map(fn ($p) => Platform::tryFrom($p)?->captionLimit())->filter()->sort()->first())
            @php($capLimitLabel = $capLimit ? Platform::tryLabel(collect($platforms)->first(fn ($p) => Platform::tryFrom($p)?->captionLimit() === $capLimit)) : null)
            <div class="caption-editor" x-data="captionEditor({{ $capLimit ?? 'null' }}, @js($capLimitLabel))">
                <div class="caption-toolbar">
                    <button type="button" class="cap-btn" @click="applyStyle('bold')" title="Bold — pilih teks dulu"><i class="fa-solid fa-bold"></i></button>
                    <button type="button" class="cap-btn" @click="applyStyle('italic')" title="Italic — pilih teks dulu"><i class="fa-solid fa-italic"></i></button>
                    <div class="cap-emoji-wrap" @click.outside="emojiOpen = false">
                        <button type="button" class="cap-btn" @click="emojiOpen = !emojiOpen" title="Sisipkan emoji"><i class="fa-regular fa-face-smile"></i></button>
                        <div class="cap-emoji-panel" x-show="emojiOpen" x-cloak style="display:none;">
                            <template x-for="group in emojiGroups" :key="group.label">
                                <div>
                                    <div class="cap-emoji-group-label" x-text="group.label"></div>
                                    <div class="cap-emoji-grid">
                                        <template x-for="e in group.items" :key="e">
                                            <button type="button" @click="insertEmoji(e)" x-text="e"></button>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                    <span class="cap-counter" :class="{ over: overLimit }" x-text="counterLabel"></span>
                </div>
                <textarea x-ref="captionInput" class="input" rows="5" wire:model.blur="caption" placeholder="Tulis caption…"></textarea>
            </div>
            <p class="field-hint">Bold/Italic tampil sebagai karakter bergaya di semua platform (bukan HTML) — pilih teks lalu klik tombolnya.</p>
        </div>

        <div class="form-step">
            <div class="form-step-title">Platform &amp; Jenis Postingan</div>
            <div class="platform-list">
                @foreach ($allPlatforms as $platform)
                    @php($checked = in_array($platform->value, $platforms, true))
                    @php($options = $this->selectablePostTypes($platform))
                    @php($chosen = \App\Enums\PostType::tryFrom($postTypes[$platform->value] ?? '') ?? $platform->defaultPostType())
                    @php($allowed = in_array($platform->value, $postable, true))
                    <div class="platform-option {{ $checked ? 'active' : '' }}" @unless ($allowed) style="opacity:.55;" @endunless>
                        <label class="platform-option-head" @unless ($allowed) style="cursor:not-allowed;" @endunless>
                            <input type="checkbox" value="{{ $platform->value }}" wire:model.live="platforms" @disabled(! $allowed && ! $checked)>
                            <i class="{{ $platform->icon() }}"></i> {{ $platform->label() }}
                            @unless ($allowed)
                                <span class="badge badge-lock" style="margin-left:auto;"><i class="fa-solid fa-lock"></i> tanpa akses</span>
                            @endunless
                        </label>

                        @if ($checked)
                            @if (count($options) > 1)
                                <div class="type-choices">
                                    @foreach ($options as $option)
                                        <label class="type-choice {{ $chosen === $option ? 'on' : '' }}">
                                            <input type="radio" name="type-{{ $platform->value }}" value="{{ $option->value }}" wire:model.live="postTypes.{{ $platform->value }}">
                                            <i class="{{ $option->icon() }}"></i> {{ $option->label($platform) }}
                                        </label>
                                    @endforeach
                                </div>
                            @endif
                            <p class="field-hint" style="margin:6px 0 0;">{{ $chosen->hint($platform) }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
            <p class="field-hint">Channel Buffer dipetakan otomatis dari platform saat publikasi.</p>
        </div>

        <div class="form-step">
            <div class="form-step-title">Preview</div>
            <div class="preview-card">
                @if ($selected->first())
                    @if ($selected->first()->isVideo())
                        <video class="preview-media" src="{{ $selected->first()->url() }}" controls muted></video>
                    @else
                        <img class="preview-media" src="{{ $selected->first()->url() }}" alt="">
                    @endif
                @endif
                <div class="preview-body">
                    <span class="chip"><i class="{{ \App\Enums\ContentCategory::from($category)->icon() }}"></i> {{ \App\Enums\ContentCategory::from($category)->label() }}</span>
                    <strong style="display:block;margin-top:8px;">{{ trim($title) ?: 'Judul konten' }}</strong>
                    <div class="preview-caption" style="margin-top:6px;">{{ trim($caption) ?: 'Caption akan tampil di sini…' }}</div>
                    <div class="chip-row" style="margin-top:10px;">
                        @foreach ($platforms as $p)
                            @php($pf = \App\Enums\Platform::tryFrom($p))
                            @if ($pf)
                                @php($pt = \App\Enums\PostType::tryFrom($postTypes[$p] ?? '') ?? $pf->defaultPostType())
                                <span class="chip"><i class="{{ $pf->icon() }}"></i> {{ $pf->label() }} · {{ $pt->label($pf) }}</span>
                            @endif
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <button class="btn btn-primary" wire:click="save(false)">
                <i class="fa-solid fa-floppy-disk"></i> Simpan Draft
            </button>
            @if ($canSubmit)
                <button class="btn" wire:click="save(true)">
                    <i class="fa-solid fa-paper-plane"></i> Simpan &amp; Kirim Approval
                </button>
            @endif
            <a class="btn btn-ghost" href="{{ route('konten.index') }}" wire:navigate><i class="fa-solid fa-xmark"></i> Batal</a>
        </div>

        @if ($content && $content->logs->isNotEmpty())
            <div class="panel" style="margin-top:24px;">
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
</div>
