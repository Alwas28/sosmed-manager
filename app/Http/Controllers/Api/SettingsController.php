<?php

namespace App\Http\Controllers\Api;

use App\Enums\AiProvider;
use App\Enums\ImageAiProvider;
use App\Enums\LogoPosition;
use App\Enums\Platform;
use App\Models\AiSetting;
use App\Models\ImageAiProfile;
use App\Models\PostTemplateSetting;
use App\Services\AI\AiException;
use App\Services\AI\AiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;

class SettingsController extends ApiController
{
    // -------------------------------------------------------------------------
    // Integrasi AI
    // -------------------------------------------------------------------------

    public function aiGet(): JsonResponse
    {
        Gate::authorize('ai.manage');
        $s = AiSetting::current();

        return $this->success([
            'settings' => [
                'enabled' => (bool) $s->enabled,
                'provider' => $s->provider->value,
                'model' => (string) $s->model,
                'base_url' => (string) $s->base_url,
                'max_tokens' => (int) $s->max_tokens,
                'temperature' => number_format((float) $s->temperature, 2, '.', ''),
                'system_prompt' => (string) $s->system_prompt,
                'has_stored_key' => filled($s->api_key),
                'is_configured' => $s->isConfigured(),
            ],
            'providers' => collect(AiProvider::cases())->map(fn (AiProvider $p) => [
                'value' => $p->value,
                'label' => $p->label(),
                'default_base_url' => $p->defaultBaseUrl(),
                'suggested_models' => $p->suggestedModels(),
                'needs_base_url' => $p->needsBaseUrl(),
            ])->all(),
        ]);
    }

    public function aiSave(Request $request): JsonResponse
    {
        Gate::authorize('ai.manage');
        $data = $request->validate([
            'enabled' => ['boolean'],
            'provider' => [Rule::enum(AiProvider::class)],
            'model' => ['required', 'string', 'max:120'],
            'api_key' => ['nullable', 'string', 'max:300'],
            'base_url' => ['nullable', 'url', 'max:255'],
            'max_tokens' => ['integer', 'min:50', 'max:4000'],
            'temperature' => ['numeric', 'min:0', 'max:2'],
            'system_prompt' => ['nullable', 'string', 'max:1000'],
        ]);

        $update = [
            'enabled' => (bool) ($data['enabled'] ?? false),
            'provider' => $data['provider'],
            'model' => $data['model'],
            'base_url' => $data['base_url'] ?: null,
            'max_tokens' => $data['max_tokens'] ?? 600,
            'temperature' => (float) ($data['temperature'] ?? 0.70),
            'system_prompt' => $data['system_prompt'] ?: null,
        ];
        if (! empty($data['api_key'])) {
            $update['api_key'] = $data['api_key'];
        }

        $s = AiSetting::current();
        $s->update($update);

        return $this->success(['has_stored_key' => filled($s->api_key)], 'Pengaturan AI tersimpan.');
    }

    public function aiTest(Request $request): JsonResponse
    {
        Gate::authorize('ai.manage');
        $this->aiSave($request);

        try {
            $reply = app(AiService::class)->complete(
                'Kamu asisten uji koneksi.',
                'Balas dengan satu kata: OK'
            );

            return $this->success([
                'ok' => true,
                'result' => '✅ Terhubung. Respons model: '.Str::limit($reply, 80),
            ]);
        } catch (AiException $e) {
            return $this->success([
                'ok' => false,
                'result' => '❌ '.$e->getMessage(),
            ]);
        }
    }

    // -------------------------------------------------------------------------
    // Generate gambar AI
    // -------------------------------------------------------------------------

    public function imageIndex(): JsonResponse
    {
        Gate::authorize('image_ai.manage');

        return $this->success([
            'profiles' => ImageAiProfile::orderByDesc('is_active')->orderBy('label')->get()
                ->map(fn (ImageAiProfile $p) => $this->imagePayload($p))->all(),
            'providers' => collect(ImageAiProvider::cases())->map(fn (ImageAiProvider $p) => [
                'value' => $p->value,
                'label' => $p->label(),
                'default_base_url' => $p->defaultBaseUrl(),
                'suggested_models' => $p->suggestedModels(),
                'supports_size' => $p->supportsSize(),
            ])->all(),
            'sizes' => ['1024x1024', '1024x1536', '1536x1024', '1024x1792', '1792x1024'],
        ]);
    }

    public function imageStore(Request $request): JsonResponse
    {
        Gate::authorize('image_ai.manage');
        $data = $this->validateImage($request);
        if (empty($data['api_key'])) {
            return $this->error('API key wajib diisi untuk profil baru.', 422, [
                'api_key' => ['API key wajib diisi untuk profil baru.'],
            ]);
        }

        $profile = ImageAiProfile::create($this->imageAttributes($data, true));

        return $this->success($this->imagePayload($profile), 'Profil AI gambar tersimpan.', 201);
    }

    public function imageUpdate(Request $request, ImageAiProfile $image): JsonResponse
    {
        Gate::authorize('image_ai.manage');
        $data = $this->validateImage($request);
        $image->update($this->imageAttributes($data, false));

        return $this->success($this->imagePayload($image->refresh()), 'Profil AI gambar diperbarui.');
    }

    public function imageToggle(ImageAiProfile $image): JsonResponse
    {
        Gate::authorize('image_ai.manage');
        $image->update(['is_active' => ! $image->is_active]);

        return $this->success($this->imagePayload($image->refresh()), 'Status profil diperbarui.');
    }

    public function imageDestroy(ImageAiProfile $image): JsonResponse
    {
        Gate::authorize('image_ai.manage');
        $image->delete();

        return $this->success(null, 'Profil dihapus.');
    }

    /** @return array<string, mixed> */
    private function validateImage(Request $request): array
    {
        return $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'provider' => [Rule::enum(ImageAiProvider::class)],
            'model' => ['required', 'string', 'max:120'],
            'api_key' => ['nullable', 'string', 'max:300'],
            'base_url' => ['nullable', 'url', 'max:255'],
            'size' => ['required', 'string', 'max:20'],
            'is_active' => ['boolean'],
        ]);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function imageAttributes(array $data, bool $creating): array
    {
        $attributes = [
            'label' => $data['label'],
            'provider' => $data['provider'],
            'model' => $data['model'],
            'base_url' => $data['base_url'] ?: null,
            'size' => $data['size'],
            'is_active' => (bool) ($data['is_active'] ?? ($creating ? true : false)),
        ];
        if (! empty($data['api_key'])) {
            $attributes['api_key'] = $data['api_key'];
        }

        return $attributes;
    }

    /** @return array<string, mixed> */
    private function imagePayload(ImageAiProfile $p): array
    {
        return [
            'id' => $p->id,
            'label' => $p->label,
            'provider' => $p->provider->value,
            'model' => $p->model,
            'base_url' => (string) $p->base_url,
            'size' => $p->size,
            'is_active' => (bool) $p->is_active,
            'has_stored_key' => filled($p->api_key),
            'is_configured' => $p->isConfigured(),
            'tag' => $p->tag(),
        ];
    }

    // -------------------------------------------------------------------------
    // Template postingan
    // -------------------------------------------------------------------------

    public function templateGet(): JsonResponse
    {
        Gate::authorize('template.manage');
        $s = PostTemplateSetting::current();

        return $this->success([
            'settings' => $this->templatePayload($s),
            'platforms' => collect(Platform::cases())->map(fn (Platform $p) => [
                'value' => $p->value,
                'label' => $p->label(),
            ])->all(),
            'logo_positions' => collect(LogoPosition::cases())->map(fn (LogoPosition $p) => [
                'value' => $p->value,
                'label' => $p->label(),
            ])->all(),
        ]);
    }

    public function templateSave(Request $request): JsonResponse
    {
        Gate::authorize('template.manage');
        $data = $request->validate([
            'brand_name' => ['nullable', 'string', 'max:60'],
            'banner_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'text_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo_position' => [Rule::in(LogoPosition::values())],
            'card_margin_x' => ['required', 'numeric', 'min:0', 'max:20'],
            'card_margin_bottom' => ['required', 'numeric', 'min:0', 'max:20'],
            'card_height' => ['required', 'numeric', 'min:10', 'max:70'],
            'headline_font_size' => ['required', 'integer', 'min:14', 'max:90'],
            'line_spacing' => ['required', 'numeric', 'min:1', 'max:2'],
            'social_platforms' => ['array'],
            'social_platforms.*' => [Rule::in(Platform::values())],
            'social_usernames' => ['array'],
            'social_usernames.*' => ['nullable', 'string', 'max:40'],
            'icon_style' => [Rule::in(['brand', 'custom'])],
            'icon_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'icon_bg_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'social_icon_size' => ['required', 'integer', 'min:12', 'max:80'],
            'social_username_font_size' => ['required', 'integer', 'min:6', 'max:40'],
            'show_logo' => ['boolean'],
            'show_social_row' => ['boolean'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'font' => ['nullable', 'file', 'mimes:ttf', 'max:5120'],
        ]);

        $settings = PostTemplateSetting::current();
        $update = [
            'brand_name' => $data['brand_name'] ?? '',
            'banner_color' => $data['banner_color'],
            'text_color' => $data['text_color'],
            'show_logo' => (bool) ($data['show_logo'] ?? false),
            'logo_position' => $data['logo_position'],
            'card_margin_x' => $data['card_margin_x'],
            'card_margin_bottom' => $data['card_margin_bottom'],
            'card_height' => $data['card_height'],
            'headline_font_size' => $data['headline_font_size'],
            'line_spacing' => $data['line_spacing'],
            'show_social_row' => (bool) ($data['show_social_row'] ?? false),
            'social_platforms' => $data['social_platforms'] ?? [],
            'social_usernames' => $data['social_usernames'] ?? [],
            'icon_style' => $data['icon_style'],
            'icon_color' => $data['icon_color'],
            'icon_bg_color' => $data['icon_bg_color'],
            'social_icon_size' => $data['social_icon_size'],
            'social_username_font_size' => $data['social_username_font_size'],
        ];

        if ($request->hasFile('logo')) {
            if ($settings->logo_path) {
                Storage::disk('public')->delete($settings->logo_path);
            }
            $update['logo_path'] = $request->file('logo')->storeAs('branding', 'logo.png', 'public');
        }
        if ($request->hasFile('font')) {
            if ($settings->font_path) {
                Storage::disk('public')->delete($settings->font_path);
            }
            $update['font_path'] = $request->file('font')->storeAs('branding', 'headline.ttf', 'public');
        }

        $settings->update($update);

        return $this->success($this->templatePayload($settings->refresh()), 'Template postingan tersimpan.');
    }

    public function templateRemoveLogo(): JsonResponse
    {
        Gate::authorize('template.manage');
        $settings = PostTemplateSetting::current();
        if ($settings->logo_path) {
            Storage::disk('public')->delete($settings->logo_path);
        }
        $settings->update(['logo_path' => null]);

        return $this->success($this->templatePayload($settings->refresh()), 'Logo dihapus.');
    }

    public function templateRemoveFont(): JsonResponse
    {
        Gate::authorize('template.manage');
        $settings = PostTemplateSetting::current();
        if ($settings->font_path) {
            Storage::disk('public')->delete($settings->font_path);
        }
        $settings->update(['font_path' => null]);

        return $this->success($this->templatePayload($settings->refresh()), 'Font dihapus.');
    }

    /** @return array<string, mixed> */
    private function templatePayload(PostTemplateSetting $s): array
    {
        return [
            'brand_name' => (string) $s->brand_name,
            'banner_color' => (string) $s->banner_color,
            'text_color' => (string) $s->text_color,
            'show_logo' => (bool) $s->show_logo,
            'logo_position' => (string) $s->logo_position,
            'card_margin_x' => (float) $s->card_margin_x,
            'card_margin_bottom' => (float) $s->card_margin_bottom,
            'card_height' => (float) $s->card_height,
            'headline_font_size' => (int) $s->headline_font_size,
            'line_spacing' => (float) $s->line_spacing,
            'show_social_row' => (bool) $s->show_social_row,
            'social_platforms' => $s->social_platforms ?? [],
            'social_usernames' => $s->social_usernames ?? [],
            'icon_style' => (string) $s->icon_style,
            'icon_color' => (string) $s->icon_color,
            'icon_bg_color' => (string) $s->icon_bg_color,
            'social_icon_size' => (int) $s->social_icon_size,
            'social_username_font_size' => (int) $s->social_username_font_size,
            'has_logo' => $s->hasLogo(),
            'has_font' => $s->hasFont(),
            'logo_url' => $s->logoUrl(),
        ];
    }
}
