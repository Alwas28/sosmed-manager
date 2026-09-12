<?php

use App\Enums\AiProvider;
use App\Models\AiSetting;
use App\Services\AI\AiException;
use App\Services\AI\AiService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.admin-layout', ['title' => 'Integrasi AI', 'subtitle' => 'Pilih penyedia dan model AI untuk membantu menulis konten.'])] class extends Component
{
    public bool $enabled = false;

    public string $provider = 'anthropic';

    public string $model = '';

    public string $apiKey = '';           // blank = keep existing

    public string $baseUrl = '';

    public int $maxTokens = 600;

    public string $temperature = '0.70';

    public string $systemPrompt = '';

    public bool $hasStoredKey = false;

    public ?string $testResult = null;

    public function mount(): void
    {
        $s = AiSetting::current();
        $this->enabled = $s->enabled;
        $this->provider = $s->provider->value;
        $this->model = (string) $s->model;
        $this->baseUrl = (string) $s->base_url;
        $this->maxTokens = $s->max_tokens;
        $this->temperature = number_format((float) $s->temperature, 2, '.', '');
        $this->systemPrompt = (string) $s->system_prompt;
        $this->hasStoredKey = filled($s->api_key);
    }

    protected function rules(): array
    {
        return [
            'enabled' => ['boolean'],
            'provider' => [Rule::enum(AiProvider::class)],
            'model' => ['required', 'string', 'max:120'],
            'apiKey' => ['nullable', 'string', 'max:300'],
            'baseUrl' => ['nullable', 'url', 'max:255'],
            'maxTokens' => ['integer', 'min:50', 'max:4000'],
            'temperature' => ['numeric', 'min:0', 'max:2'],
            'systemPrompt' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function save(): void
    {
        Gate::authorize('ai.manage');
        $this->validate();

        $data = [
            'enabled' => $this->enabled,
            'provider' => $this->provider,
            'model' => $this->model,
            'base_url' => $this->baseUrl ?: null,
            'max_tokens' => $this->maxTokens,
            'temperature' => (float) $this->temperature,
            'system_prompt' => $this->systemPrompt ?: null,
        ];

        if ($this->apiKey !== '') {
            $data['api_key'] = $this->apiKey;
        }

        AiSetting::current()->update($data);

        $this->apiKey = '';
        $this->hasStoredKey = filled(AiSetting::current()->api_key);
        session()->flash('status', 'Pengaturan AI tersimpan.');
    }

    public function test(): void
    {
        Gate::authorize('ai.manage');
        $this->save();

        try {
            $reply = app(AiService::class)->complete(
                'Kamu asisten uji koneksi.',
                'Balas dengan satu kata: OK',
            );
            $this->testResult = '✅ Terhubung. Respons model: '.\Illuminate\Support\Str::limit($reply, 80);
        } catch (AiException $e) {
            $this->testResult = '❌ '.$e->getMessage();
        }
    }

    public function with(): array
    {
        return [
            'providers' => AiProvider::cases(),
            'suggestions' => AiProvider::from($this->provider)->suggestedModels(),
            'currentProvider' => AiProvider::from($this->provider),
        ];
    }
}; ?>

<div style="max-width:640px;">
    @include('partials.toast-flash')

    @if ($testResult)
        <div class="flash {{ str_starts_with($testResult, '❌') ? 'flash-error' : '' }}">{{ $testResult }}</div>
    @endif

    <div class="panel">
        <label class="perm-item" style="cursor:pointer;">
            <input type="checkbox" wire:model="enabled">
            <span>
                <span class="p-name">Aktifkan asisten AI</span><br>
                <span class="p-desc">Menampilkan tombol "Buatkan judul / caption" di form konten.</span>
            </span>
        </label>
    </div>

    <div class="panel">
        <div class="field">
            <label class="field-label">Penyedia AI</label>
            <select class="select" wire:model.live="provider">
                @foreach ($providers as $p)
                    <option value="{{ $p->value }}">{{ $p->label() }}</option>
                @endforeach
            </select>
        </div>

        <div class="field">
            <label class="field-label">Model</label>
            <input type="text" class="input" wire:model="model" list="ai-models"
                   placeholder="{{ $suggestions[0] ?? 'nama-model' }}">
            <datalist id="ai-models">
                @foreach ($suggestions as $m)<option value="{{ $m }}">@endforeach
            </datalist>
            <p class="field-hint">Saran: {{ implode(', ', $suggestions) }}</p>
            @error('model')<div class="field-error">{{ $message }}</div>@enderror
        </div>

        <div class="field">
            <label class="field-label">API Key</label>
            <input type="password" class="input" wire:model="apiKey"
                   placeholder="{{ $hasStoredKey ? '•••••••• (tersimpan — isi untuk mengganti)' : 'Tempel API key' }}"
                   autocomplete="new-password">
            @error('apiKey')<div class="field-error">{{ $message }}</div>@enderror
        </div>

        @if ($currentProvider->needsBaseUrl())
            <div class="field">
                <label class="field-label">Base URL</label>
                <input type="url" class="input" wire:model="baseUrl" placeholder="{{ $currentProvider->defaultBaseUrl() }}">
                <p class="field-hint">Endpoint OpenAI-compatible, mis. OpenRouter, Groq, atau Ollama (http://localhost:11434/v1).</p>
                @error('baseUrl')<div class="field-error">{{ $message }}</div>@enderror
            </div>
        @endif

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="field">
                <label class="field-label">Max token respons</label>
                <input type="number" class="input" wire:model="maxTokens" min="50" max="4000">
                @error('maxTokens')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
                <label class="field-label">Temperature</label>
                <input type="number" step="0.05" class="input" wire:model="temperature" min="0" max="2">
                @error('temperature')<div class="field-error">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="field">
            <label class="field-label">Gaya bahasa (opsional)</label>
            <textarea class="input" rows="2" wire:model="systemPrompt"
                      placeholder="mis. Gunakan sapaan 'Sahabat Kampus', hindari singkatan, sertakan tagar #UMKendari."></textarea>
            @error('systemPrompt')<div class="field-error">{{ $message }}</div>@enderror
        </div>

        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <button class="btn btn-primary btn-sm" wire:click="save">
                <i class="fa-solid fa-floppy-disk"></i> Simpan
            </button>
            <button class="btn btn-sm" wire:click="test" wire:loading.attr="disabled" wire:target="test">
                <i class="fa-solid fa-plug-circle-bolt"></i>
                <span wire:loading.remove wire:target="test">Simpan &amp; Test Koneksi</span>
                <span wire:loading wire:target="test">Menguji…</span>
            </button>
        </div>
    </div>
</div>
