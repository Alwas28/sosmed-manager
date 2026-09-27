<?php

use App\Enums\ContentCategory;
use App\Enums\ContentStatus;
use App\Enums\Platform;
use App\Enums\PostType;
use App\Models\Content;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.admin-layout', ['title' => 'Posting Link', 'subtitle' => 'Bagikan URL website sebagai kartu link — tanpa foto/video.'])] class extends Component
{
    public ?Content $content = null;

    public string $title = '';

    public string $linkUrl = '';

    public string $linkTitle = '';

    public string $linkDescription = '';

    public string $caption = '';

    public string $category = 'umum';

    /** @var array<int, string> */
    public array $platforms = [];

    public function mount(?Content $content = null): void
    {
        if ($content?->exists) {
            Gate::authorize('content.edit');
            abort_unless($content->status->isEditable(), 403, 'Konten yang sudah diproses tidak dapat diubah.');
            abort_unless($content->isLinkPost(), 404);

            $this->content = $content->load('platforms');
            $this->title = $content->title;
            $this->linkUrl = (string) $content->link_url;
            $this->linkTitle = (string) $content->link_title;
            $this->linkDescription = (string) $content->link_description;
            $this->caption = (string) $content->caption;
            $this->category = ($content->category ?? ContentCategory::Umum)->value;
            $this->platforms = $content->platforms->pluck('platform')->all();
        } else {
            Gate::authorize('content.create');
        }
    }

    /** @return array<int, Platform> platform yang mendukung link DAN boleh dipakai user ini */
    public function linkablePlatforms(): array
    {
        return array_values(array_filter(
            Platform::cases(),
            fn (Platform $p) => $p->supportsLinkPost(),
        ));
    }

    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'linkUrl' => ['required', 'url', 'max:2048'],
            'linkTitle' => ['nullable', 'string', 'max:200'],
            'linkDescription' => ['nullable', 'string', 'max:1000'],
            'caption' => ['nullable', 'string', 'max:5000'],
            'category' => ['required', Rule::enum(ContentCategory::class)],
            'platforms' => ['required', 'array', 'min:1'],
            'platforms.*' => [
                Rule::in(array_map(
                    fn (Platform $p) => $p->value,
                    array_filter($this->linkablePlatforms(), fn (Platform $p) => auth()->user()->canPostTo($p->value, 'link')),
                )),
            ],
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
            'link_url' => $data['linkUrl'],
            'link_title' => $data['linkTitle'] ?: null,
            'link_description' => $data['linkDescription'] ?: null,
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
            $content->platforms()->create(['platform' => $value, 'post_type' => PostType::Link->value]);
        }

        $content->logActivity(
            $editing ? 'updated' : 'created',
            null,
            $content->status->value,
            $submit ? 'Dikirim untuk approval' : null,
        );

        session()->flash('status', $submit
            ? 'Konten link dikirim untuk approval.'
            : 'Konten link disimpan sebagai draft.');

        $this->redirectRoute($submit ? 'konten.approval' : 'konten.draft', navigate: true);
    }

    public function with(): array
    {
        return [
            'allCategories' => ContentCategory::cases(),
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

    <div class="panel" style="margin-bottom:16px;">
        <p class="field-hint" style="margin:0;">
            <i class="fa-solid fa-circle-info"></i>
            Instagram &amp; TikTok tidak tersedia di sini karena keduanya wajib pakai foto/video, tidak bisa posting link saja.
            Untuk itu, pakai <a class="auth-link" href="{{ route('konten.create') }}" wire:navigate>Form Konten</a> biasa.
        </p>
    </div>

    <div class="form-steps">
        <div class="form-step">
            <div class="form-step-title">Kategori Konten</div>
            <select class="select" style="max-width:280px;" wire:model="category">
                @foreach ($allCategories as $cat)
                    <option value="{{ $cat->value }}">{{ $cat->label() }}</option>
                @endforeach
            </select>
        </div>

        <div class="form-step">
            <div class="form-step-title">Judul Konten (internal)</div>
            <input type="text" class="input" wire:model.blur="title" placeholder="mis. Promo Pendaftaran Online">
            <p class="field-hint">Cuma label internal di sistem ini — bukan yang tampil di postingan.</p>
        </div>

        <div class="form-step">
            <div class="form-step-title">URL Website</div>
            <input type="url" class="input" wire:model.blur="linkUrl" placeholder="https://umkendari.ac.id/pendaftaran">
            @error('linkUrl')<div class="field-error">{{ $message }}</div>@enderror
        </div>

        <div class="form-step">
            <div class="form-step-title">Kartu Link (opsional)</div>
            <div class="field">
                <label class="field-label">Judul Kartu</label>
                <input type="text" class="input" wire:model.blur="linkTitle" placeholder="Kosongkan untuk pakai Judul Konten di atas">
                @error('linkTitle')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label class="field-label">Deskripsi Kartu</label>
                <textarea class="input" rows="2" wire:model.blur="linkDescription" placeholder="Ringkasan singkat halaman tujuan…"></textarea>
                @error('linkDescription')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            <p class="field-hint">Sebagian platform menampilkan preview link otomatis dari halamannya sendiri — kolom ini jadi cadangan kalau tidak.</p>
        </div>

        <div class="form-step">
            <div class="form-step-title">Teks Pengantar (opsional)</div>
            <textarea class="input" rows="4" wire:model.blur="caption" placeholder="Teks yang menyertai link, mis. ajakan atau ringkasan…"></textarea>
            @error('caption')<div class="field-error">{{ $message }}</div>@enderror
            <p class="field-hint">Link di atas otomatis disertakan di teks postingan kalau belum ada.</p>
        </div>

        <div class="form-step">
            <div class="form-step-title">Platform</div>
            <div class="checkbox-list">
                @foreach ($this->linkablePlatforms() as $platform)
                    @php($allowed = auth()->user()->canPostTo($platform->value, 'link'))
                    <label @unless ($allowed) style="opacity:.55;cursor:not-allowed;" @endunless>
                        <input type="checkbox" value="{{ $platform->value }}" wire:model="platforms" @disabled(! $allowed && ! in_array($platform->value, $platforms, true))>
                        <i class="{{ $platform->icon() }}"></i> {{ $platform->label() }}
                        @unless ($allowed)
                            <span class="badge badge-lock"><i class="fa-solid fa-lock"></i></span>
                        @endunless
                    </label>
                @endforeach
            </div>
            @error('platforms')<div class="field-error">{{ $message }}</div>@enderror
        </div>

        <div class="form-step">
            <div class="form-step-title">Preview Kartu Link</div>
            <div class="preview-card">
                <div class="link-card-preview">
                    <div class="link-card-domain">
                        <i class="fa-solid fa-globe"></i> {{ trim($linkUrl) !== '' ? (parse_url($linkUrl, PHP_URL_HOST) ?: $linkUrl) : 'website.com' }}
                    </div>
                    <strong class="link-card-title">{{ trim($linkTitle) ?: (trim($title) ?: 'Judul kartu link') }}</strong>
                    <div class="link-card-desc">{{ trim($linkDescription) ?: 'Deskripsi kartu akan tampil di sini…' }}</div>
                </div>
                <div class="preview-body">
                    <div class="preview-caption">{{ trim($caption) ?: 'Teks pengantar akan tampil di sini…' }}</div>
                    <div class="chip-row" style="margin-top:10px;">
                        @foreach ($platforms as $p)
                            @php($pf = Platform::tryFrom($p))
                            @if ($pf)
                                <span class="chip"><i class="{{ $pf->icon() }}"></i> {{ $pf->label() }} · Link</span>
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
    </div>
</div>
