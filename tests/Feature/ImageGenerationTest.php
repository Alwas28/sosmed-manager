<?php

namespace Tests\Feature;

use App\Models\ImageAiProfile;
use App\Models\Media;
use App\Models\Role;
use App\Models\User;
use App\Services\AI\Image\ImageGenerationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ImageGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('public');
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $slug)->value('id')]);
    }

    private function tinyPngBase64(): string
    {
        return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8Xw8AAoMBgDTD2qgAAAAASUVORK5CYII=';
    }

    public function test_only_ai_manager_can_open_image_settings(): void
    {
        $this->actingAs($this->userWithRole('administrator'))->get(route('image-ai.settings'))
            ->assertOk()->assertSee('Generate Gambar AI');
        $this->actingAs($this->userWithRole('content-creator'))->get(route('image-ai.settings'))->assertForbidden();
    }

    public function test_admin_can_create_a_profile_with_encrypted_key(): void
    {
        $this->actingAs($this->userWithRole('administrator'));

        Volt::test('pages.settings.image-ai')
            ->call('newProfile')
            ->set('label', 'OpenAI gpt-image-1')
            ->set('provider', 'openai')
            ->set('model', 'gpt-image-1')
            ->set('apiKey', 'sk-test-123')
            ->call('save')
            ->assertHasNoErrors();

        $profile = ImageAiProfile::first();
        $this->assertSame('OpenAI gpt-image-1', $profile->label);
        $this->assertSame('sk-test-123', $profile->api_key);
        $this->assertNotSame('sk-test-123', DB::table('image_ai_profiles')->value('api_key'));
        $this->assertTrue($profile->is_active);
    }

    public function test_new_profile_requires_api_key(): void
    {
        $this->actingAs($this->userWithRole('administrator'));

        Volt::test('pages.settings.image-ai')
            ->call('newProfile')
            ->set('label', 'X')
            ->set('model', 'gpt-image-1')
            ->set('apiKey', '')
            ->call('save')
            ->assertHasErrors('apiKey');

        $this->assertDatabaseCount('image_ai_profiles', 0);
    }

    public function test_generate_via_openai_driver_returns_bytes(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['data' => [['b64_json' => $this->tinyPngBase64()]]])]);

        $profile = ImageAiProfile::create([
            'label' => 'OpenAI', 'provider' => 'openai', 'model' => 'gpt-image-1', 'api_key' => 'k',
        ]);

        $result = app(ImageGenerationService::class)->generate($profile, 'a cat');

        $this->assertSame('image/png', $result['mime']);
        $this->assertSame(base64_decode($this->tinyPngBase64()), $result['bytes']);
    }

    public function test_generate_via_gemini_driver_returns_bytes(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [
                ['inlineData' => ['mimeType' => 'image/png', 'data' => $this->tinyPngBase64()]],
            ]]]],
        ])]);

        $profile = ImageAiProfile::create([
            'label' => 'Gemini', 'provider' => 'gemini', 'model' => 'gemini-2.5-flash-image', 'api_key' => 'k',
        ]);

        $result = app(ImageGenerationService::class)->generate($profile, 'a dog');

        $this->assertSame(base64_decode($this->tinyPngBase64()), $result['bytes']);
    }

    public function test_content_form_generates_image_and_saves_as_media(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['data' => [['b64_json' => $this->tinyPngBase64()]]])]);

        $profile = ImageAiProfile::create([
            'label' => 'OpenAI gpt-image-1', 'provider' => 'openai', 'model' => 'gpt-image-1', 'api_key' => 'k',
        ]);

        $this->actingAs($this->userWithRole('content-creator'));

        Volt::test('pages.contents.form')
            ->call('openImageModal')
            ->assertSet('imageProfileId', $profile->id)
            ->set('imageMode', 'generate')
            ->set('imagePrompt', 'Mobil putih di depan bandara')
            ->call('runImageGeneration')
            ->assertHasNoErrors();

        $media = Media::first();
        $this->assertNotNull($media);
        $this->assertSame('ai_generated', $media->source);
        $this->assertSame('openai:gpt-image-1', $media->ai_model);
        $this->assertSame('image', $media->type);
        Storage::disk('public')->assertExists($media->path);
    }

    public function test_content_form_edits_uploaded_image(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['data' => [['b64_json' => $this->tinyPngBase64()]]])]);

        ImageAiProfile::create(['label' => 'OpenAI', 'provider' => 'openai', 'model' => 'gpt-image-1', 'api_key' => 'k']);

        $this->actingAs($this->userWithRole('content-creator'));

        Volt::test('pages.contents.form')
            ->call('openImageModal')
            ->set('imageMode', 'edit')
            ->set('imageEditUpload', UploadedFile::fake()->image('source.jpg'))
            ->set('imagePrompt', 'Tambahkan teks DISKON 50% di atas')
            ->call('runImageGeneration')
            ->assertHasNoErrors();

        $media = Media::first();
        $this->assertSame('ai_edited', $media->source);
    }

    public function test_generate_mode_requires_a_prompt(): void
    {
        ImageAiProfile::create(['label' => 'OpenAI', 'provider' => 'openai', 'model' => 'gpt-image-1', 'api_key' => 'k']);

        $this->actingAs($this->userWithRole('content-creator'));

        Volt::test('pages.contents.form')
            ->call('openImageModal')
            ->set('imageMode', 'generate')
            ->set('imagePrompt', '')
            ->call('runImageGeneration')
            ->assertHasErrors('imagePrompt');

        $this->assertDatabaseCount('media', 0);
    }

    public function test_edit_mode_requires_an_upload(): void
    {
        ImageAiProfile::create(['label' => 'OpenAI', 'provider' => 'openai', 'model' => 'gpt-image-1', 'api_key' => 'k']);

        $this->actingAs($this->userWithRole('content-creator'));

        Volt::test('pages.contents.form')
            ->call('openImageModal')
            ->set('imageMode', 'edit')
            ->call('runImageGeneration')
            ->assertHasErrors('imageEditUpload');
    }

    public function test_driver_failure_surfaces_as_form_error(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'insufficient_quota']], 429)]);
        $profile = ImageAiProfile::create(['label' => 'OpenAI', 'provider' => 'openai', 'model' => 'gpt-image-1', 'api_key' => 'k']);

        $this->actingAs($this->userWithRole('content-creator'));

        Volt::test('pages.contents.form')
            ->call('openImageModal')
            ->set('imageProfileId', $profile->id)
            ->set('imagePrompt', 'x')
            ->call('runImageGeneration')
            ->assertHasErrors('image');

        $this->assertDatabaseCount('media', 0);
    }

    public function test_modal_shows_hint_when_no_profiles_configured(): void
    {
        $this->actingAs($this->userWithRole('content-creator'));

        $component = Volt::test('pages.contents.form')->call('openImageModal');
        $component->assertSee('Belum ada profil AI gambar');
    }
}
