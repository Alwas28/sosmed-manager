<?php

use App\Enums\ImageAiProvider;
use App\Models\ImageAiProfile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.admin-layout', ['title' => 'Generate Gambar AI', 'subtitle' => 'Kelola profil model AI untuk membuat/mengedit gambar.'])] class extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $label = '';

    public string $provider = 'openai';

    public string $model = '';

    public string $apiKey = '';

    public string $baseUrl = '';

    public string $size = '1024x1024';

    public bool $isActive = true;

    public bool $hasStoredKey = false;

    public function with(): array
    {
        return [
            'profiles' => ImageAiProfile::orderByDesc('is_active')->orderBy('label')->get(),
            'providers' => ImageAiProvider::cases(),
            'suggestions' => ImageAiProvider::from($this->provider)->suggestedModels(),
            'currentProvider' => ImageAiProvider::from($this->provider),
        ];
    }

    public function newProfile(): void
    {
        Gate::authorize('ai.manage');
        $this->reset(['editingId', 'label', 'model', 'apiKey', 'baseUrl', 'hasStoredKey']);
        $this->provider = 'openai';
        $this->size = '1024x1024';
        $this->isActive = true;
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        Gate::authorize('ai.manage');
        $profile = ImageAiProfile::findOrFail($id);

        $this->editingId = $profile->id;
        $this->label = $profile->label;
        $this->provider = $profile->provider->value;
        $this->model = $profile->model;
        $this->baseUrl = (string) $profile->base_url;
        $this->size = $profile->size;
        $this->isActive = $profile->is_active;
        $this->apiKey = '';
        $this->hasStoredKey = filled($profile->api_key);
        $this->resetValidation();
        $this->showForm = true;
    }

    protected function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:100'],
            'provider' => [Rule::enum(ImageAiProvider::class)],
            'model' => ['required', 'string', 'max:120'],
            'apiKey' => ['nullable', 'string', 'max:300'],
            'baseUrl' => ['nullable', 'url', 'max:255'],
            'size' => ['required', 'string', 'max:20'],
        ];
    }

    public function save(): void
    {
        Gate::authorize('ai.manage');
        $data = $this->validate();

        if (! $this->editingId && $this->apiKey === '') {
            $this->addError('apiKey', 'API key wajib diisi untuk profil baru.');

            return;
        }

        $profile = $this->editingId ? ImageAiProfile::findOrFail($this->editingId) : new ImageAiProfile;
        $profile->fill([
            'label' => $data['label'],
            'provider' => $data['provider'],
            'model' => $data['model'],
            'base_url' => $data['baseUrl'] ?: null,
            'size' => $data['size'],
            'is_active' => $this->isActive,
        ]);

        if ($this->apiKey !== '') {
            $profile->api_key = $this->apiKey;
        }

        $profile->save();

        $this->reset(['showForm', 'editingId', 'label', 'model', 'apiKey', 'baseUrl', 'hasStoredKey']);
        session()->flash('status', 'Profil AI gambar tersimpan.');
    }

    public function toggleActive(int $id): void
    {
        Gate::authorize('ai.manage');
        $profile = ImageAiProfile::findOrFail($id);
        $profile->update(['is_active' => ! $profile->is_active]);
    }

    public function delete(int $id): void
    {
        Gate::authorize('ai.manage');
        ImageAiProfile::findOrFail($id)->delete();
        session()->flash('status', 'Profil dihapus.');
    }
}; ?>

<div>
    @include('partials.toast-flash')

    <div class="panel">
        <p class="panel-sub" style="margin-bottom:0;">
            Setiap profil = satu kombinasi provider + model. Buat beberapa profil untuk membandingkan hasil generate
            gambar antar model. <strong>Claude (Anthropic) tidak mendukung generate gambar</strong>, jadi hanya
            OpenAI dan Google Gemini yang tersedia di sini.
        </p>
    </div>

    <div class="toolbar">
        <p class="panel-sub" style="margin:0;">{{ $profiles->count() }} profil.</p>
        @can('ai.manage')
            <button class="btn btn-primary btn-sm" type="button" wire:click="newProfile">
                <i class="fa-solid fa-plus"></i> Profil Baru
            </button>
        @endcan
    </div>

    @if ($showForm)
        <div class="panel" style="margin-bottom:16px;">
            <h2 class="panel-title">{{ $editingId ? 'Ubah Profil' : 'Profil Baru' }}</h2>

            <div class="field">
                <label class="field-label">Nama Profil</label>
                <input type="text" class="input" wire:model="label" placeholder="mis. OpenAI gpt-image-1">
                @error('label')<div class="field-error">{{ $message }}</div>@enderror
            </div>

            <div class="field">
                <label class="field-label">Penyedia</label>
                <select class="select" wire:model.live="provider">
                    @foreach ($providers as $p)
                        <option value="{{ $p->value }}">{{ $p->label() }}</option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label class="field-label">Model</label>
                <input type="text" class="input" wire:model="model" list="image-models" placeholder="{{ $suggestions[0] ?? 'nama-model' }}">
                <datalist id="image-models">
                    @foreach ($suggestions as $m)<option value="{{ $m }}">@endforeach
                </datalist>
                @error('model')<div class="field-error">{{ $message }}</div>@enderror
            </div>

            <div class="field">
                <label class="field-label">API Key</label>
                <input type="password" class="input" wire:model="apiKey"
                       placeholder="{{ $hasStoredKey ? '•••••••• (tersimpan — isi untuk mengganti)' : 'Tempel API key' }}"
                       autocomplete="new-password">
                @error('apiKey')<div class="field-error">{{ $message }}</div>@enderror
            </div>

            <div class="field">
                <label class="field-label">Base URL (opsional)</label>
                <input type="url" class="input" wire:model="baseUrl" placeholder="{{ $currentProvider->defaultBaseUrl() }}">
                @error('baseUrl')<div class="field-error">{{ $message }}</div>@enderror
            </div>

            @if ($currentProvider->supportsSize())
                <div class="field">
                    <label class="field-label">Ukuran Gambar</label>
                    <select class="select" wire:model="size">
                        <option value="1024x1024">Persegi (1024×1024)</option>
                        <option value="1024x1536">Potret (1024×1536)</option>
                        <option value="1536x1024">Lanskap (1536×1024)</option>
                        <option value="1024x1792">Potret panjang (1024×1792)</option>
                        <option value="1792x1024">Lanskap lebar (1792×1024)</option>
                    </select>
                </div>
            @endif

            <label class="perm-item" style="cursor:pointer;margin-bottom:14px;">
                <input type="checkbox" wire:model="isActive">
                <span class="p-name">Aktif (tampil sebagai pilihan saat generate)</span>
            </label>

            <div style="display:flex;gap:8px;">
                <button class="btn btn-primary btn-sm" type="button" wire:click="save"><i class="fa-solid fa-floppy-disk"></i> Simpan</button>
                <button class="btn btn-sm" type="button" wire:click="$set('showForm', false)"><i class="fa-solid fa-xmark"></i> Batal</button>
            </div>
        </div>
    @endif

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Profil</th>
                    <th>Penyedia</th>
                    <th>Model</th>
                    <th>Status</th>
                    <th style="text-align:right;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($profiles as $profile)
                    <tr>
                        <td class="title-text">{{ $profile->label }}</td>
                        <td class="date-cell">{{ $profile->provider->label() }}</td>
                        <td class="date-cell"><code>{{ $profile->model }}</code></td>
                        <td>
                            <span class="badge {{ $profile->is_active ? 'badge-accent' : '' }}">
                                {{ $profile->is_active ? 'aktif' : 'nonaktif' }}
                            </span>
                        </td>
                        <td class="action-cell">
                            <button class="btn btn-sm" type="button" wire:click="toggleActive({{ $profile->id }})">
                                <i class="fa-solid fa-power-off"></i> {{ $profile->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                            </button>
                            <button class="btn btn-sm" type="button" wire:click="edit({{ $profile->id }})"><i class="fa-solid fa-pen"></i> Ubah</button>
                            <x-confirm-button message="Hapus profil “{{ $profile->label }}”? Tindakan ini tidak dapat dibatalkan." action="$wire.delete({{ $profile->id }})">
                                <button type="button" class="btn btn-sm btn-danger"><i class="fa-solid fa-trash"></i> Hapus</button>
                            </x-confirm-button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="text-align:center;padding:40px;color:var(--text-muted);">Belum ada profil. Tambahkan minimal satu untuk mulai generate gambar.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
