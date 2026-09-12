<?php

use App\Enums\ContentCategory;
use App\Enums\ContentStatus;
use App\Enums\Platform;
use App\Models\Content;
use App\Models\ImageAiProfile;
use App\Models\Media;
use App\Services\AI\AiException;
use App\Services\AI\AiService;
use App\Services\AI\Image\ImageGenerationService;
use App\Services\Image\PostTemplateCompositor;
use App\Services\Image\TemplateException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.admin-layout', ['title' => 'Form Konten', 'subtitle' => 'Judul → Media → Caption → Platform → Preview → Simpan.'])] class extends Component
{
    use WithFileUploads;

    public ?Content $content = null;

    public string $title = '';

    public string $caption = '';

    public string $category = 'umum';

    /** @var array<int, string> */
    public array $platforms = [];

    /** @var array<int, int> ordered media ids */
    public array $mediaIds = [];

    public array $uploads = [];

    // "Pustaka Media" picker: search/filter + reveal-more instead of dumping
    // every uploaded file — the library only grows over time.
    public string $mediaSearch = '';

    public string $mediaTypeFilter = '';

    public int $mediaLimit = 24;

    // "Generate Gambar dengan AI" modal
    public bool $showImageModal = false;

    public string $imageMode = 'generate'; // generate | edit | template

    public string $imagePrompt = '';

    public ?int $imageProfileId = null;

    public $imageEditUpload = null;

    // "Terapkan Template Postingan" tab — deterministic banner/logo overlay, no AI image model involved.
    public string $templateHeadline = '';

    public $templateUpload = null;

    public string $templateMediaKind = 'foto'; // foto | video — controls the "Foto:"/"Video:" wording

    /** @var array<int, array{media_id:int, url:string, label:string, mode:string}> this-session gallery, for comparing models */
    public array $imageGenerations = [];

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
            $this->mediaIds = $content->media->pluck('id')->all();
        } else {
            Gate::authorize('content.create');
        }
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

    public function updatedMediaSearch(): void
    {
        $this->mediaLimit = 24;
    }

    public function updatedMediaTypeFilter(): void
    {
        $this->mediaLimit = 24;
    }

    public function loadMoreMedia(): void
    {
        $this->mediaLimit += 24;
    }

    public function openImageModal(): void
    {
        Gate::authorize('ai.use');
        $this->imageMode = 'generate';
        $this->imagePrompt = '';
        $this->imageEditUpload = null;
        $this->resetErrorBag(['imagePrompt', 'imageEditUpload', 'imageProfileId', 'image']);

        if (! $this->imageProfileId) {
            $this->imageProfileId = app(ImageGenerationService::class)->activeProfiles()->first()?->id;
        }

        $this->showImageModal = true;
    }

    public function runImageGeneration(ImageGenerationService $svc): void
    {
        Gate::authorize('ai.use');

        $this->validate([
            'imageProfileId' => ['required', Rule::exists('image_ai_profiles', 'id')],
            'imagePrompt' => [Rule::requiredIf($this->imageMode === 'generate'), 'nullable', 'string', 'max:1000'],
            'imageEditUpload' => [Rule::requiredIf($this->imageMode === 'edit'), 'nullable', 'image', 'max:20480'],
        ], attributes: ['imageProfileId' => 'profil AI', 'imagePrompt' => 'prompt', 'imageEditUpload' => 'gambar']);

        $profile = ImageAiProfile::findOrFail($this->imageProfileId);

        try {
            if ($this->imageMode === 'edit') {
                $bytes = file_get_contents($this->imageEditUpload->getRealPath());
                $mime = $this->imageEditUpload->getMimeType() ?: 'image/png';
                $instruction = trim($this->imagePrompt) !== ''
                    ? $this->imagePrompt
                    : 'Tambahkan teks/caption yang sesuai konteks gambar ini, rapi dan mudah dibaca.';

                $result = $svc->edit($profile, $bytes, $mime, $instruction);
                $source = 'ai_edited';
            } else {
                $result = $svc->generate($profile, $this->imagePrompt);
                $source = 'ai_generated';
            }

            $extension = str_contains($result['mime'], 'png') ? 'png' : 'jpg';
            $path = 'media/'.now()->format('Y/m').'/'.Str::random(32).'.'.$extension;
            Storage::disk('public')->put($path, $result['bytes']);

            $media = Media::create([
                'disk' => 'public',
                'path' => $path,
                'original_name' => $profile->label.' — '.now()->format('d M H:i'),
                'mime_type' => $result['mime'],
                'type' => 'image',
                'source' => $source,
                'ai_model' => $profile->tag(),
                'size' => strlen($result['bytes']),
                'uploaded_by' => auth()->id(),
            ]);

            $this->mediaIds[] = $media->id;
            array_unshift($this->imageGenerations, [
                'media_id' => $media->id,
                'url' => $media->url(),
                'label' => $profile->label,
                'mode' => $this->imageMode,
            ]);
        } catch (AiException $e) {
            $this->addError('image', $e->getMessage());
        }
    }

    public function aiSuggestHeadline(AiService $ai): void
    {
        Gate::authorize('ai.use');

        try {
            $this->templateHeadline = $ai->suggestTitle($this->title ?: $this->templateHeadline, $this->platforms, $this->caption);
        } catch (AiException $e) {
            $this->addError('templateHeadline', $e->getMessage());
        }
    }

    public function runTemplateCompose(PostTemplateCompositor $compositor): void
    {
        Gate::authorize('ai.use');

        $this->validate([
            'templateUpload' => ['required', 'image', 'max:20480'],
            'templateHeadline' => ['required', 'string', 'max:200'],
        ], attributes: ['templateUpload' => 'gambar', 'templateHeadline' => 'judul pita']);

        try {
            $bytes = file_get_contents($this->templateUpload->getRealPath());
            $label = $this->templateMediaKind === 'video' ? 'Video' : 'Foto';
            $result = $compositor->compose($bytes, $this->templateHeadline, $label);

            $path = 'media/'.now()->format('Y/m').'/'.Str::random(32).'.jpg';
            Storage::disk('public')->put($path, $result['bytes']);

            $media = Media::create([
                'disk' => 'public',
                'path' => $path,
                'original_name' => 'Template Postingan — '.now()->format('d M H:i'),
                'mime_type' => $result['mime'],
                'type' => 'image',
                'source' => 'template',
                'size' => strlen($result['bytes']),
                'uploaded_by' => auth()->id(),
            ]);

            $this->mediaIds[] = $media->id;
            array_unshift($this->imageGenerations, [
                'media_id' => $media->id,
                'url' => $media->url(),
                'label' => 'Template Postingan',
                'mode' => 'template',
            ]);

            $this->templateUpload = null;
        } catch (TemplateException $e) {
            $this->addError('image', $e->getMessage());
        }
    }

    public bool $showAiModal = false;

    public string $aiBrief = '';

    public function openAiModal(): void
    {
        Gate::authorize('ai.use');
        $this->aiBrief = '';
        $this->resetErrorBag('aiBrief');
        $this->showAiModal = true;
    }

    public function generateDraft(AiService $ai): void
    {
        Gate::authorize('ai.use');

        $this->validate(
            ['aiBrief' => ['required', 'string', 'min:5', 'max:1000']],
            attributes: ['aiBrief' => 'brief']
        );

        try {
            $draft = $ai->draftFromBrief($this->aiBrief, $this->platforms, ContentCategory::tryFrom($this->category));
            $this->title = $draft['title'];
            if ($draft['caption'] !== '') {
                $this->caption = $draft['caption'];
            }
            $this->showAiModal = false;
        } catch (AiException $e) {
            $this->addError('aiBrief', $e->getMessage());
        }
    }

    public function aiImprove(AiService $ai): void
    {
        $this->runAi(function () use ($ai) {
            if (trim($this->caption) === '') {
                throw new AiException('Tulis caption dulu sebelum minta AI memperbaikinya.');
            }
            $this->caption = $ai->improveCaption($this->caption, $this->platforms, ContentCategory::tryFrom($this->category));
        });
    }

    private function runAi(callable $callback): void
    {
        Gate::authorize('ai.use');

        try {
            $callback();
        } catch (AiException $e) {
            $this->addError('ai', $e->getMessage());
        }
    }

    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'caption' => ['nullable', 'string', 'max:5000'],
            'category' => ['required', Rule::enum(ContentCategory::class)],
            'platforms' => ['required', 'array', 'min:1'],
            'platforms.*' => [Rule::in(Platform::values())],
            'mediaIds' => ['array'],
            'mediaIds.*' => [Rule::exists('media', 'id')],
        ];
    }

    public function save(bool $submit = false): void
    {
        $editing = (bool) $this->content?->exists;
        Gate::authorize($editing ? 'content.edit' : 'content.create');

        if ($submit) {
            Gate::authorize('content.submit');
        }

        $data = $this->validate();

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
            // Creator is working on a revision — move it back to Draft.
            $content->status = ContentStatus::Draft;
        }

        $content->save();

        $content->platforms()->delete();
        foreach (array_unique($this->platforms) as $platform) {
            $content->platforms()->create(['platform' => $platform]);
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

    /** Base query for the "Pustaka Media" picker — shared by the paged results and the total count. */
    protected function mediaLibraryQuery(): Builder
    {
        return Media::query()
            ->when($this->mediaSearch !== '', fn ($q) => $q->where('original_name', 'like', '%'.$this->mediaSearch.'%'))
            ->when($this->mediaTypeFilter !== '', fn ($q) => $q->where('type', $this->mediaTypeFilter));
    }

    public function with(): array
    {
        $libraryTotal = $this->mediaLibraryQuery()->count();

        return [
            'allPlatforms' => Platform::cases(),
            'allCategories' => ContentCategory::cases(),
            // Search + type filter + reveal-more instead of dumping every
            // uploaded file — the library only grows, so a flat cap alone
            // isn't enough once there are more than a screenful of items.
            'library' => $this->mediaLibraryQuery()->latest()->take($this->mediaLimit)->get(),
            'libraryTotal' => $libraryTotal,
            'selected' => Media::whereIn('id', $this->mediaIds)->get()
                ->sortBy(fn ($m) => array_search($m->id, $this->mediaIds, true))
                ->values(),
            'canSubmit' => Gate::allows('content.submit'),
            'aiEnabled' => Gate::allows('ai.use') && app(AiService::class)->enabled(),
            'imageAiEnabled' => Gate::allows('ai.use'),
            'imageProfiles' => app(ImageGenerationService::class)->activeProfiles(),
        ];
    }
}; ?>

<div>
    @php($hiddenErrorKeys = ['ai', 'aiBrief', 'image', 'imagePrompt', 'imageEditUpload', 'imageProfileId', 'templateHeadline', 'templateUpload'])
    @php($topErrors = collect($errors->keys())->reject(fn ($k) => in_array($k, $hiddenErrorKeys))->flatMap(fn ($k) => $errors->get($k)))
    @if ($topErrors->isNotEmpty())
        <div class="flash flash-error">
            @foreach ($topErrors as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    @if ($content && ($revNote = $content->pendingRevisionNote()))
        <div class="flash flash-error"><strong>Catatan revisi dari reviewer:</strong> {{ $revNote }}</div>
    @endif

    @error('ai')<div class="flash flash-error">{{ $message }}</div>@enderror

    @if ($aiEnabled)
        <div class="panel" style="margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;">
            <div>
                <strong style="font-size:13px;">Belum tahu mau menulis apa?</strong>
                <p class="field-hint" style="margin:2px 0 0;">Tulis brief singkat — AI akan menyusun judul &amp; caption otomatis.</p>
            </div>
            <button type="button" class="btn btn-primary btn-sm" wire:click="openAiModal">
                <i class="fa-solid fa-wand-magic-sparkles"></i> Buat dengan AI
            </button>
        </div>
    @endif

    @if ($showAiModal)
        <div class="modal-overlay" wire:click.self="$set('showAiModal', false)">
            <div class="modal">
                <div class="modal-head">
                    <h3><i class="fa-solid fa-wand-magic-sparkles"></i> Buat Judul &amp; Caption dengan AI</h3>
                    <button type="button" class="modal-x" wire:click="$set('showAiModal', false)">&times;</button>
                </div>
                <p class="field-hint" style="margin-bottom:8px;">Jelaskan konten yang ingin dibuat. Semakin detail (topik, target, penawaran, lokasi), semakin baik hasilnya.</p>
                <textarea class="input" rows="4" wire:model="aiBrief"
                          placeholder="mis. Promosi rental mobil lepas kunci untuk mudik Lebaran, harga mulai 300rb/hari, area Kendari, bonus antar-jemput bandara"></textarea>
                @error('aiBrief')<div class="field-error">{{ $message }}</div>@enderror
                <p class="field-hint" style="margin-top:6px;">
                    Kategori: <strong>{{ \App\Enums\ContentCategory::from($category)->label() }}</strong> ·
                    Platform: <strong>{{ empty($platforms) ? 'belum ada' : implode(', ', array_map(fn ($p) => \App\Enums\Platform::tryLabel($p), $platforms)) }}</strong>
                </p>
                <div class="modal-foot">
                    <button type="button" class="btn btn-primary btn-sm" wire:click="generateDraft" wire:loading.attr="disabled" wire:target="generateDraft">
                        <i class="fa-solid fa-wand-magic-sparkles"></i>
                        <span wire:loading.remove wire:target="generateDraft">Generate</span>
                        <span wire:loading wire:target="generateDraft">Membuat…</span>
                    </button>
                    <button type="button" class="btn btn-sm btn-ghost" wire:click="$set('showAiModal', false)"><i class="fa-solid fa-xmark"></i> Batal</button>
                </div>
            </div>
        </div>
    @endif

    @if ($showImageModal)
        <div class="modal-overlay" wire:click.self="$set('showImageModal', false)">
            <div class="modal" style="max-width:600px;">
                <div class="modal-head">
                    <h3><i class="fa-solid fa-image"></i> Generate Gambar dengan AI</h3>
                    <button type="button" class="modal-x" wire:click="$set('showImageModal', false)">&times;</button>
                </div>

                <div class="role-tabs" style="margin-bottom:14px;">
                    <button type="button" class="role-tab {{ $imageMode === 'generate' ? 'active' : '' }}" wire:click="$set('imageMode', 'generate')">
                        <i class="fa-solid fa-wand-magic-sparkles"></i> Buat Baru dari Prompt
                    </button>
                    <button type="button" class="role-tab {{ $imageMode === 'edit' ? 'active' : '' }}" wire:click="$set('imageMode', 'edit')">
                        <i class="fa-solid fa-file-image"></i> Edit Gambar yang Diunggah
                    </button>
                    <button type="button" class="role-tab {{ $imageMode === 'template' ? 'active' : '' }}" wire:click="$set('imageMode', 'template')">
                        <i class="fa-solid fa-stamp"></i> Terapkan Template Postingan
                    </button>
                </div>

                @if ($imageMode === 'template')
                    {{-- Deterministic house-style overlay (logo + banner + judul). No AI image model needed here —
                         only the headline text is optionally AI-written. See Pengaturan → Template Postingan. --}}
                    <div class="field">
                        <label class="field-label">Unggah Foto</label>
                        <input type="file" wire:model="templateUpload" accept="image/*" class="input" style="padding:7px;">
                        <div wire:loading wire:target="templateUpload" class="field-hint">Mengunggah…</div>
                        @error('templateUpload')<div class="field-error">{{ $message }}</div>@enderror
                    </div>

                    <div class="field">
                        <label class="field-label">Jenis Media</label>
                        <select class="select" style="max-width:200px;" wire:model="templateMediaKind">
                            <option value="foto">Foto</option>
                            <option value="video">Video (thumbnail)</option>
                        </select>
                        <p class="field-hint">Menentukan label "Foto:"/"Video:" di pita.</p>
                    </div>

                    <div class="field">
                        <label class="field-label">Judul di Pita</label>
                        <textarea class="input" rows="2" wire:model="templateHeadline"
                                  placeholder="mis. Revitalisasi Tanggul Sungai Wanggu di Kendari Segera Dikerjakan"></textarea>
                        @error('templateHeadline')<div class="field-error">{{ $message }}</div>@enderror
                        @if ($aiEnabled)
                            <div class="ai-bar">
                                <button type="button" class="ai-btn" wire:click="aiSuggestHeadline" wire:loading.attr="disabled" wire:target="aiSuggestHeadline">
                                    <i class="fa-solid fa-wand-magic-sparkles"></i>
                                    <span wire:loading.remove wire:target="aiSuggestHeadline">Buatkan judul dengan AI</span>
                                    <span wire:loading wire:target="aiSuggestHeadline">Membuat…</span>
                                </button>
                            </div>
                        @endif
                    </div>
                @elseif ($imageProfiles->isEmpty())
                    <p class="field-hint" style="margin-bottom:0;">
                        Belum ada profil AI gambar yang aktif.
                        @can('ai.manage')
                            <a class="auth-link" href="{{ route('image-ai.settings') }}" wire:navigate>Atur di Pengaturan → Generate Gambar AI</a>.
                        @else
                            Minta admin mengaturnya di Pengaturan → Generate Gambar AI.
                        @endcan
                    </p>
                @else
                    <div class="field">
                        <label class="field-label">Model AI</label>
                        <select class="select" wire:model="imageProfileId">
                            @foreach ($imageProfiles as $profile)
                                <option value="{{ $profile->id }}">{{ $profile->label }} ({{ $profile->provider->label() }})</option>
                            @endforeach
                        </select>
                        @error('imageProfileId')<div class="field-error">{{ $message }}</div>@enderror
                        <p class="field-hint">Ingin membandingkan? Generate dengan satu model, ganti pilihan di atas, lalu generate lagi — hasilnya tersimpan di galeri percobaan di bawah.</p>
                    </div>

                    @if ($imageMode === 'edit')
                        <div class="field">
                            <label class="field-label">Unggah Gambar Sumber</label>
                            <input type="file" wire:model="imageEditUpload" accept="image/*" class="input" style="padding:7px;">
                            <div wire:loading wire:target="imageEditUpload" class="field-hint">Mengunggah…</div>
                            @error('imageEditUpload')<div class="field-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="field">
                            <label class="field-label">Instruksi (opsional)</label>
                            <textarea class="input" rows="3" wire:model="imagePrompt"
                                      placeholder="mis. Tambahkan teks 'DISKON 50%' besar berwarna merah di bagian atas gambar"></textarea>
                            <p class="field-hint">Kosongkan untuk membiarkan AI menambahkan teks/caption yang sesuai secara otomatis.</p>
                        </div>
                    @else
                        <div class="field">
                            <label class="field-label">Prompt Gambar</label>
                            <textarea class="input" rows="3" wire:model="imagePrompt"
                                      placeholder="mis. Foto mobil MPV putih terparkir di depan bandara, gaya fotografi profesional, pencahayaan siang hari"></textarea>
                            @error('imagePrompt')<div class="field-error">{{ $message }}</div>@enderror
                        </div>
                    @endif
                @endif

                @error('image')<div class="flash flash-error">{{ $message }}</div>@enderror

                @if ($imageMode !== 'template' && $imageProfiles->isEmpty())
                    {{-- no submit button — nothing to run without a configured image model --}}
                @else
                    <div class="modal-foot">
                        <button type="button" class="btn btn-primary btn-sm"
                                wire:click="{{ $imageMode === 'template' ? 'runTemplateCompose' : 'runImageGeneration' }}"
                                wire:loading.attr="disabled" wire:target="runImageGeneration,runTemplateCompose">
                            <i class="fa-solid fa-wand-magic-sparkles"></i>
                            <span wire:loading.remove wire:target="runImageGeneration,runTemplateCompose">{{ $imageMode === 'template' ? 'Terapkan Template' : 'Generate Gambar' }}</span>
                            <span wire:loading wire:target="runImageGeneration,runTemplateCompose">{{ $imageMode === 'template' ? 'Menerapkan template…' : 'Membuat gambar… (bisa 10–30 detik)' }}</span>
                        </button>
                        <button type="button" class="btn btn-sm btn-ghost" wire:click="$set('showImageModal', false)"><i class="fa-solid fa-xmark"></i> Tutup</button>
                    </div>

                    @if (! empty($imageGenerations))
                        <div style="margin-top:18px;border-top:1px solid var(--border);padding-top:14px;">
                            <p class="field-label">Galeri percobaan sesi ini — bandingkan hasil antar model</p>
                            <div class="media-grid">
                                @foreach ($imageGenerations as $gen)
                                    <div class="media-tile">
                                        <img class="media-thumb" src="{{ $gen['url'] }}" alt="">
                                        <span class="media-badge">{{ $gen['label'] }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endif
            </div>
        </div>
    @endif

    <div class="form-steps">
        <div class="form-step">
            <div class="form-step-title">Kategori Konten</div>
            <select class="select" style="max-width:280px;" wire:model.live="category">
                @foreach ($allCategories as $cat)
                    <option value="{{ $cat->value }}">{{ $cat->label() }}</option>
                @endforeach
            </select>
            <p class="field-hint">Membantu AI menyesuaikan gaya tulisan (berita = faktual, iklan = persuasif, dst).</p>
            @error('category')<div class="field-error">{{ $message }}</div>@enderror
        </div>

        <div class="form-step">
            <div class="form-step-title">Judul Konten</div>
            <input type="text" class="input" wire:model.blur="title" placeholder="mis. Penerimaan Mahasiswa Baru 2026">
        </div>

        <div class="form-step">
            <div class="form-step-title">Media (foto / video)</div>
            <input type="file" wire:model="uploads" multiple accept="image/*,video/*" class="input" style="padding:7px;">
            <div wire:loading wire:target="uploads" class="field-hint">Mengunggah…</div>
            <p class="field-hint">Maks 20 MB per berkas. Klik media pustaka untuk memilih/melepas.</p>

            @if ($imageAiEnabled)
                <div class="ai-bar">
                    <button type="button" class="ai-btn" wire:click="openImageModal">
                        <i class="fa-solid fa-image"></i> Generate Gambar dengan AI
                    </button>
                </div>
            @endif

            @if ($selected->isNotEmpty())
                <p class="field-label" style="margin-top:12px;">Dipakai di konten ini ({{ $selected->count() }})</p>
                <div class="media-grid" style="margin-bottom:14px;">
                    @foreach ($selected as $m)
                        <div class="media-tile">
                            @if ($m->isVideo())
                                <video class="media-thumb" src="{{ $m->url() }}" muted></video><span class="media-badge">VIDEO</span>
                            @elseif ($m->isAiMade())
                                <img class="media-thumb" src="{{ $m->url() }}" alt=""><span class="media-badge" title="{{ $m->ai_model }}"><i class="fa-solid fa-wand-magic-sparkles"></i> AI</span>
                            @elseif ($m->isTemplateComposed())
                                <img class="media-thumb" src="{{ $m->url() }}" alt=""><span class="media-badge"><i class="fa-solid fa-stamp"></i> Template</span>
                            @else
                                <img class="media-thumb" src="{{ $m->url() }}" alt="">
                            @endif
                            <button type="button" class="media-x" wire:click="toggleMedia({{ $m->id }})" title="Lepas">&times;</button>
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($libraryTotal > 0 || $mediaSearch !== '' || $mediaTypeFilter !== '')
                <div style="margin-top:12px;">
                    <p class="field-label" style="margin-bottom:6px;">Pustaka Media ({{ number_format($libraryTotal) }})</p>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;">
                        <input type="text" class="input" style="flex:1;min-width:160px;" wire:model.live.debounce.400ms="mediaSearch" placeholder="Cari nama berkas…">
                        <select class="input" style="max-width:140px;" wire:model.live="mediaTypeFilter">
                            <option value="">Semua Tipe</option>
                            <option value="image">Foto</option>
                            <option value="video">Video</option>
                        </select>
                    </div>

                    @if ($library->isNotEmpty())
                        <div class="media-grid">
                            @foreach ($library as $m)
                                <div class="media-tile selectable {{ in_array($m->id, $mediaIds, true) ? 'selected' : '' }}" wire:click="toggleMedia({{ $m->id }})">
                                    @if ($m->isVideo())
                                        <video class="media-thumb" src="{{ $m->url() }}" muted></video><span class="media-badge">VIDEO</span>
                                    @elseif ($m->isAiMade())
                                        <img class="media-thumb" src="{{ $m->url() }}" alt=""><span class="media-badge" title="{{ $m->ai_model }}"><i class="fa-solid fa-wand-magic-sparkles"></i> AI</span>
                                    @elseif ($m->isTemplateComposed())
                                        <img class="media-thumb" src="{{ $m->url() }}" alt=""><span class="media-badge"><i class="fa-solid fa-stamp"></i> Template</span>
                                    @else
                                        <img class="media-thumb" src="{{ $m->url() }}" alt="">
                                    @endif
                                    <div class="media-name">{{ $m->original_name }}</div>
                                </div>
                            @endforeach
                        </div>

                        @if ($library->count() < $libraryTotal)
                            <div style="text-align:center;margin-top:10px;">
                                <button type="button" class="btn btn-sm" wire:click="loadMoreMedia" wire:loading.attr="disabled" wire:target="loadMoreMedia">
                                    <i class="fa-solid fa-rotate"></i>
                                    <span wire:loading.remove wire:target="loadMoreMedia">Muat Lebih Banyak ({{ $library->count() }} dari {{ $libraryTotal }})</span>
                                    <span wire:loading wire:target="loadMoreMedia">Memuat…</span>
                                </button>
                            </div>
                        @endif
                    @else
                        <p class="field-hint">Tidak ada media yang cocok dengan pencarian/filter.</p>
                    @endif
                </div>
            @endif
        </div>

        <div class="form-step">
            <div class="form-step-title">Caption</div>
            <textarea class="input" rows="5" wire:model.blur="caption" placeholder="Tulis caption…"
                      wire:loading.attr="readonly" wire:target="aiImprove,generateDraft"></textarea>
            @if ($aiEnabled)
                <div class="ai-bar">
                    <button type="button" class="ai-btn" wire:click="aiImprove" wire:loading.attr="disabled" wire:target="aiImprove">
                        <i class="fa-solid fa-arrows-rotate"></i>
                        <span wire:loading.remove wire:target="aiImprove">Perbaiki caption dengan AI</span>
                        <span wire:loading wire:target="aiImprove">Memproses…</span>
                    </button>
                </div>
            @endif
        </div>

        <div class="form-step">
            <div class="form-step-title">Pilih Platform</div>
            <div class="checkbox-list">
                @foreach ($allPlatforms as $platform)
                    <label>
                        <input type="checkbox" value="{{ $platform->value }}" wire:model.live="platforms">
                        <i class="{{ $platform->icon() }}"></i> {{ $platform->label() }}
                    </label>
                @endforeach
            </div>
            <p class="field-hint">Channel Buffer akan dipetakan otomatis dari platform ini saat publikasi.</p>
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
                            <span class="chip"><i class="{{ \App\Enums\Platform::tryIcon($p) }}"></i> {{ \App\Enums\Platform::tryLabel($p) }}</span>
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
