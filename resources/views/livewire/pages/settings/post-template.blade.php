<?php

use App\Enums\LogoPosition;
use App\Enums\Platform;
use App\Models\PostTemplateSetting;
use App\Services\Image\PostTemplateCompositor;
use App\Services\Image\TemplateException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.admin-layout', ['title' => 'Template Postingan', 'subtitle' => 'Gaya baku (logo, pita judul, ikon sosial) yang ditempel otomatis ke foto.'])] class extends Component
{
    use WithFileUploads;

    public string $brandName = '';

    public string $bannerColor = '#0B5E34';

    public string $textColor = '#FFFFFF';

    public bool $showLogo = true;

    public string $logoPosition = 'top-left';

    public float $cardMarginX = 4.50;

    public float $cardMarginBottom = 3.00;

    public float $cardHeight = 26.00;

    public int $headlineFontSize = 40;

    public float $lineSpacing = 1.30;

    public bool $showSocialRow = true;

    /** @var array<int, string> */
    public array $socialPlatforms = ['facebook', 'twitter', 'instagram'];

    /** @var array<string, string> platform slug => @username */
    public array $socialUsernames = [];

    public string $iconStyle = 'brand';

    public string $iconColor = '#FFFFFF';

    public string $iconBgColor = '#FFFFFF';

    public int $socialIconSize = 29;

    public int $socialUsernameFontSize = 9;

    public $logoUpload = null;

    public $fontUpload = null;

    public function mount(): void
    {
        $s = PostTemplateSetting::current();
        $this->brandName = $s->brand_name;
        $this->bannerColor = $s->banner_color;
        $this->textColor = $s->text_color;
        $this->showLogo = $s->show_logo;
        $this->logoPosition = $s->logo_position;
        $this->cardMarginX = $s->card_margin_x;
        $this->cardMarginBottom = $s->card_margin_bottom;
        $this->cardHeight = $s->card_height;
        $this->headlineFontSize = $s->headline_font_size;
        $this->lineSpacing = $s->line_spacing;
        $this->showSocialRow = $s->show_social_row;
        $this->socialPlatforms = $s->social_platforms ?: [];
        $this->socialUsernames = $s->social_usernames ?: [];
        $this->iconStyle = $s->icon_style;
        $this->iconColor = $s->icon_color;
        $this->iconBgColor = $s->icon_bg_color;
        $this->socialIconSize = $s->social_icon_size;
        $this->socialUsernameFontSize = $s->social_username_font_size;
    }

    protected function rules(): array
    {
        return [
            'brandName' => ['nullable', 'string', 'max:60'],
            'bannerColor' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'textColor' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logoPosition' => [Rule::in(LogoPosition::values())],
            'cardMarginX' => ['required', 'numeric', 'min:0', 'max:20'],
            'cardMarginBottom' => ['required', 'numeric', 'min:0', 'max:20'],
            'cardHeight' => ['required', 'numeric', 'min:10', 'max:70'],
            'headlineFontSize' => ['required', 'integer', 'min:14', 'max:90'],
            'lineSpacing' => ['required', 'numeric', 'min:1', 'max:2'],
            'socialPlatforms.*' => [Rule::in(Platform::values())],
            'socialUsernames.*' => ['nullable', 'string', 'max:40'],
            'iconStyle' => [Rule::in(['brand', 'custom'])],
            'iconColor' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'iconBgColor' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'socialIconSize' => ['required', 'integer', 'min:12', 'max:80'],
            'socialUsernameFontSize' => ['required', 'integer', 'min:6', 'max:40'],
            'logoUpload' => ['nullable', 'image', 'max:2048'],
            'fontUpload' => ['nullable', 'file', 'mimes:ttf', 'max:5120'],
        ];
    }

    public function save(): void
    {
        Gate::authorize('ai.manage');
        $data = $this->validate();

        $settings = PostTemplateSetting::current();
        $update = [
            'brand_name' => $data['brandName'] ?? '',
            'banner_color' => $data['bannerColor'],
            'text_color' => $data['textColor'],
            'show_logo' => $this->showLogo,
            'logo_position' => $data['logoPosition'],
            'card_margin_x' => $data['cardMarginX'],
            'card_margin_bottom' => $data['cardMarginBottom'],
            'card_height' => $data['cardHeight'],
            'headline_font_size' => $data['headlineFontSize'],
            'line_spacing' => $data['lineSpacing'],
            'show_social_row' => $this->showSocialRow,
            'social_platforms' => $this->socialPlatforms,
            'social_usernames' => $data['socialUsernames'] ?? [],
            'icon_style' => $data['iconStyle'],
            'icon_color' => $data['iconColor'],
            'icon_bg_color' => $data['iconBgColor'],
            'social_icon_size' => $data['socialIconSize'],
            'social_username_font_size' => $data['socialUsernameFontSize'],
        ];

        if ($this->logoUpload) {
            if ($settings->logo_path) {
                Storage::disk('public')->delete($settings->logo_path);
            }
            $update['logo_path'] = $this->logoUpload->storeAs('branding', 'logo.png', 'public');
        }

        if ($this->fontUpload) {
            if ($settings->font_path) {
                Storage::disk('public')->delete($settings->font_path);
            }
            $update['font_path'] = $this->fontUpload->storeAs('branding', 'headline.ttf', 'public');
        }

        $settings->update($update);
        $this->reset(['logoUpload', 'fontUpload']);

        session()->flash('status', 'Template postingan tersimpan.');
    }

    public function removeLogo(): void
    {
        Gate::authorize('ai.manage');
        $settings = PostTemplateSetting::current();
        if ($settings->logo_path) {
            Storage::disk('public')->delete($settings->logo_path);
            $settings->update(['logo_path' => null]);
        }

        session()->flash('status', 'Logo dihapus.');
    }

    public function removeFont(): void
    {
        Gate::authorize('ai.manage');
        $settings = PostTemplateSetting::current();
        if ($settings->font_path) {
            Storage::disk('public')->delete($settings->font_path);
            $settings->update(['font_path' => null]);
        }

        session()->flash('status', 'Font dihapus.');
    }

    public function with(): array
    {
        return [
            'settings' => PostTemplateSetting::current(),
            'allPlatforms' => Platform::cases(),
            'logoPositions' => LogoPosition::cases(),
        ];
    }

    /**
     * Live preview: composes the template with the form's *current* (not
     * yet saved) values over a generated placeholder photo, so tweaking a
     * setting shows the result immediately instead of requiring "Simpan" +
     * a trip to the content form. Memoised per request via #[Computed].
     */
    #[Computed]
    public function previewImage(): ?string
    {
        $settings = PostTemplateSetting::current()->replicate();
        $settings->brand_name = $this->brandName;
        $settings->banner_color = preg_match('/^#[0-9A-Fa-f]{6}$/', $this->bannerColor) ? $this->bannerColor : '#0B5E34';
        $settings->text_color = preg_match('/^#[0-9A-Fa-f]{6}$/', $this->textColor) ? $this->textColor : '#FFFFFF';
        $settings->show_logo = $this->showLogo;
        $settings->logo_position = $this->logoPosition;
        $settings->card_margin_x = $this->cardMarginX;
        $settings->card_margin_bottom = $this->cardMarginBottom;
        $settings->card_height = $this->cardHeight;
        $settings->headline_font_size = $this->headlineFontSize;
        $settings->line_spacing = $this->lineSpacing;
        $settings->show_social_row = $this->showSocialRow;
        $settings->social_platforms = $this->socialPlatforms;
        $settings->social_usernames = $this->socialUsernames;
        $settings->icon_style = $this->iconStyle;
        $settings->icon_color = preg_match('/^#[0-9A-Fa-f]{6}$/', $this->iconColor) ? $this->iconColor : '#FFFFFF';
        $settings->icon_bg_color = preg_match('/^#[0-9A-Fa-f]{6}$/', $this->iconBgColor) ? $this->iconBgColor : '#FFFFFF';
        $settings->social_icon_size = $this->socialIconSize;
        $settings->social_username_font_size = $this->socialUsernameFontSize;

        // A just-selected (not yet saved) upload previews immediately too —
        // read straight from its temp path, nothing is written to disk.
        $logoOverride = $this->logoUpload?->getRealPath() ?: null;
        $fontOverride = $this->fontUpload?->getRealPath() ?: null;

        try {
            $result = app(PostTemplateCompositor::class)->compose(
                $this->placeholderPhotoBytes(),
                // Deliberately long enough to wrap to 2 lines, so "Spasi
                // Antar Baris" actually has something visible to preview.
                'Contoh Judul Berita yang Cukup Panjang agar Tampil Dua Baris',
                'Foto',
                $settings,
                $logoOverride,
                $fontOverride,
            );
        } catch (TemplateException) {
            return null;
        }

        return 'data:'.$result['mime'].';base64,'.base64_encode($result['bytes']);
    }

    /** A generated neutral gradient standing in for a real photo, purely for the preview. */
    private function placeholderPhotoBytes(): string
    {
        $w = 900;
        $h = 900;
        $im = imagecreatetruecolor($w, $h);

        for ($y = 0; $y < $h; $y++) {
            $t = $y / $h;
            $r = (int) (90 + $t * 45);
            $g = (int) (95 + $t * 45);
            $b = (int) (105 + $t * 50);
            imageline($im, 0, $y, $w, $y, imagecolorallocate($im, $r, $g, $b));
        }

        $label = 'FOTO CONTOH';
        $labelColor = imagecolorallocatealpha($im, 255, 255, 255, 105);
        $labelWidth = imagefontwidth(5) * strlen($label);
        imagestring($im, 5, (int) (($w - $labelWidth) / 2), (int) ($h / 2) - 8, $label, $labelColor);

        ob_start();
        imagejpeg($im, null, 85);
        $bytes = ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }
}; ?>

<div style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-start;">
    <div style="flex:1 1 420px;min-width:0;max-width:640px;">
        @include('partials.toast-flash')

        <div class="panel">
            <p class="panel-sub" style="margin-bottom:0;">
                Pengaturan ini dipakai oleh menu <strong>Generate Gambar dengan AI → Terapkan Template Postingan</strong>
                di form konten: pita judul, logo, dan ikon di bawah ini ditempelkan otomatis ke foto yang diunggah —
                digambar langsung oleh sistem (bukan AI gambar), jadi hasilnya selalu identik setiap kali. Pratinjau
                di samping ikut berubah begitu Anda mengubah pengaturan, sebelum menekan Simpan.
            </p>
        </div>

        <div class="panel">
            <div class="field">
                <label class="field-label">Logo Watermark (PNG transparan)</label>
                @if ($settings->hasLogo())
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                        <img src="{{ $settings->logoUrl() }}" alt="Logo" style="height:40px;background:#222;border-radius:6px;padding:4px;">
                        <x-confirm-button message="Hapus logo watermark ini?" action="$wire.removeLogo()">
                            <button type="button" class="btn btn-sm btn-danger"><i class="fa-solid fa-trash"></i> Hapus</button>
                        </x-confirm-button>
                    </div>
                @endif
                <input type="file" wire:model="logoUpload" accept="image/png" class="input" style="padding:7px;">
                <div wire:loading wire:target="logoUpload" class="field-hint">Mengunggah…</div>
                @error('logoUpload')<div class="field-error">{{ $message }}</div>@enderror

                <label class="perm-item" style="cursor:pointer;margin-top:10px;">
                    <input type="checkbox" wire:model.live="showLogo">
                    <span class="p-name">Tampilkan logo di foto</span>
                </label>

                @if ($showLogo)
                    <div style="margin-top:8px;">
                        <label class="field-label">Posisi Logo</label>
                        <select class="input" wire:model.live="logoPosition">
                            @foreach ($logoPositions as $position)
                                <option value="{{ $position->value }}">{{ $position->label() }}</option>
                            @endforeach
                        </select>
                        @error('logoPosition')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                @endif
            </div>

            <div class="field">
                <label class="field-label">Font Judul (.ttf)</label>
                @if ($settings->hasFont())
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                        <span class="badge badge-accent"><i class="fa-solid fa-font"></i> Font tersimpan</span>
                        <x-confirm-button message="Hapus font judul ini?" action="$wire.removeFont()">
                            <button type="button" class="btn btn-sm btn-danger"><i class="fa-solid fa-trash"></i> Hapus</button>
                        </x-confirm-button>
                    </div>
                @else
                    <p class="field-hint">Belum ada font kustom — teks memakai font bawaan sistem (tampilannya sederhana). Unggah font .ttf tebal untuk hasil terbaik.</p>
                @endif
                <input type="file" wire:model="fontUpload" accept=".ttf" class="input" style="padding:7px;">
                <div wire:loading wire:target="fontUpload" class="field-hint">Mengunggah…</div>
                @error('fontUpload')<div class="field-error">{{ $message }}</div>@enderror
            </div>

            <div class="field">
                <label class="field-label">Nama / Handle Brand</label>
                <input type="text" class="input" wire:model.live.debounce.400ms="brandName" placeholder="mis. Kendariinfo">
                @error('brandName')<div class="field-error">{{ $message }}</div>@enderror
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="field">
                    <label class="field-label">Warna Pita</label>
                    <input type="color" class="input" style="padding:4px;height:40px;" wire:model.live="bannerColor">
                    @error('bannerColor')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label class="field-label">Warna Teks</label>
                    <input type="color" class="input" style="padding:4px;height:40px;" wire:model.live="textColor">
                    @error('textColor')<div class="field-error">{{ $message }}</div>@enderror
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="field">
                    <label class="field-label">Tepi Kartu Kiri/Kanan (% lebar foto)</label>
                    <input type="number" step="0.1" min="0" max="20" class="input" wire:model.live.debounce.400ms="cardMarginX">
                    <p class="field-hint">Jarak kartu hijau dari tepi kiri &amp; kanan foto.</p>
                    @error('cardMarginX')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label class="field-label">Jarak dari Bawah (% tinggi foto)</label>
                    <input type="number" step="0.1" min="0" max="20" class="input" wire:model.live.debounce.400ms="cardMarginBottom">
                    <p class="field-hint">Makin kecil, kartu makin turun mendekati tepi bawah foto.</p>
                    @error('cardMarginBottom')<div class="field-error">{{ $message }}</div>@enderror
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="field">
                    <label class="field-label">Tinggi Kartu (% tinggi foto)</label>
                    <input type="number" step="0.5" min="10" max="70" class="input" wire:model.live.debounce.400ms="cardHeight">
                    <p class="field-hint">Kartu selalu setinggi ini — kalau caption panjang, tulisan otomatis mengecil (bukan kartunya membesar) agar tetap muat.</p>
                    @error('cardHeight')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label class="field-label">Ukuran Font Judul (px, pada foto lebar 1080px)</label>
                    <input type="number" step="1" min="14" max="90" class="input" wire:model.live.debounce.400ms="headlineFontSize">
                    <p class="field-hint">Ukuran dasar judul. Otomatis mengikuti lebar foto asli — hanya mengecil sendiri kalau captionnya tidak muat di kartu.</p>
                    @error('headlineFontSize')<div class="field-error">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="field">
                <label class="field-label">Spasi Antar Baris (jika caption 2 baris atau lebih)</label>
                <input type="number" step="0.05" min="1" max="2" class="input" wire:model.live.debounce.400ms="lineSpacing" style="max-width:180px;">
                <p class="field-hint">Jarak antar baris judul saat teksnya panjang dan turun ke baris kedua/ketiga. 1.0 = rapat, 1.3 = normal, di atas itu makin renggang.</p>
                @error('lineSpacing')<div class="field-error">{{ $message }}</div>@enderror
            </div>

            <label class="perm-item" style="cursor:pointer;">
                <input type="checkbox" wire:model.live="showSocialRow">
                <span class="p-name">Tampilkan baris ikon media sosial (di dalam kartu, di bawah judul)</span>
            </label>

            @if ($showSocialRow)
                <div class="field" style="margin-top:10px;">
                    <label class="field-label">Warna Icon</label>
                    <select class="input" wire:model.live="iconStyle">
                        <option value="brand">Warna Asli Brand (Facebook biru, X hitam, dst.)</option>
                        <option value="custom">Warna Kustom (satu warna untuk semua icon)</option>
                    </select>
                    @error('iconStyle')<div class="field-error">{{ $message }}</div>@enderror

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:10px;">
                        @if ($iconStyle === 'custom')
                            <div class="field">
                                <label class="field-label">Warna Icon</label>
                                <input type="color" class="input" style="padding:4px;height:40px;" wire:model.live="iconColor">
                                @error('iconColor')<div class="field-error">{{ $message }}</div>@enderror
                            </div>
                        @endif
                        <div class="field">
                            <label class="field-label">Warna Latar Lingkaran</label>
                            <input type="color" class="input" style="padding:4px;height:40px;" wire:model.live="iconBgColor">
                            <p class="field-hint">Samakan dengan warna pita agar lingkaran menyatu, icon seolah melayang tanpa bulatan.</p>
                            @error('iconBgColor')<div class="field-error">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:10px;">
                        <div class="field">
                            <label class="field-label">Ukuran Icon (px, pada foto lebar 1080px)</label>
                            <input type="number" step="1" min="12" max="80" class="input" wire:model.live.debounce.400ms="socialIconSize">
                            <p class="field-hint">Besar lingkaran icon platform.</p>
                            @error('socialIconSize')<div class="field-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="field">
                            <label class="field-label">Ukuran Font Username (px, pada foto lebar 1080px)</label>
                            <input type="number" step="1" min="6" max="40" class="input" wire:model.live.debounce.400ms="socialUsernameFontSize">
                            <p class="field-hint">Ukuran teks @username di bawah tiap icon.</p>
                            @error('socialUsernameFontSize')<div class="field-error">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>

                <div class="field" style="margin-top:10px;">
                    <label class="field-label">Platform &amp; Username</label>
                    <p class="field-hint" style="margin-top:0;">Username akan tampil di bawah ikon masing-masing platform, karena handle tiap sosmed bisa berbeda-beda.</p>
                    <div style="display:flex;flex-direction:column;gap:8px;">
                        @foreach ($allPlatforms as $platform)
                            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                                <label style="display:flex;align-items:center;gap:6px;min-width:150px;cursor:pointer;">
                                    <input type="checkbox" value="{{ $platform->value }}" wire:model.live="socialPlatforms">
                                    <i class="{{ $platform->icon() }}"></i> {{ $platform->label() }}
                                </label>
                                @if (in_array($platform->value, $socialPlatforms))
                                    <input
                                        type="text"
                                        class="input"
                                        style="flex:1;min-width:140px;max-width:220px;"
                                        placeholder="{{ '@'.$platform->label() }}"
                                        wire:model.live.debounce.400ms="socialUsernames.{{ $platform->value }}"
                                    >
                                @endif
                                @error("socialUsernames.{$platform->value}")<div class="field-error">{{ $message }}</div>@enderror
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <button class="btn btn-primary btn-sm" type="button" wire:click="save" style="margin-top:8px;">
                <i class="fa-solid fa-floppy-disk"></i> Simpan
            </button>
        </div>
    </div>

    <div style="flex:0 0 320px;position:sticky;top:16px;">
        <div class="panel">
            <label class="field-label" style="display:flex;align-items:center;gap:8px;">
                <i class="fa-solid fa-eye"></i> Pratinjau
                <span wire:loading wire:target="brandName,bannerColor,textColor,showLogo,logoPosition,cardMarginX,cardMarginBottom,cardHeight,headlineFontSize,lineSpacing,showSocialRow,socialPlatforms,socialUsernames,iconStyle,iconColor,iconBgColor,socialIconSize,socialUsernameFontSize,logoUpload,fontUpload" class="field-hint" style="margin:0;">
                    <i class="fa-solid fa-spinner fa-spin"></i> memperbarui…
                </span>
            </label>
            @if ($this->previewImage())
                <img src="{{ $this->previewImage() }}" alt="Pratinjau template" style="width:100%;border-radius:10px;display:block;">
            @else
                <p class="field-hint">Pratinjau belum bisa dibuat — periksa isian di atas.</p>
            @endif
            <p class="field-hint" style="margin-top:8px;margin-bottom:0;">
                Pakai foto abu-abu contoh (bukan foto asli) hanya agar Anda bisa melihat posisi &amp; ukuran kartu, logo, dan ikon sebelum dipakai di konten sungguhan.
            </p>
        </div>
    </div>
</div>
