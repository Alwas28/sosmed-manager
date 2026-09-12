<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\AI\AiException;
use App\Services\AI\AiService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AiIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function userWithRole(string $slug): User
    {
        return User::factory()->create(['role_id' => Role::where('slug', $slug)->value('id')]);
    }

    public function test_only_ai_manager_can_open_settings(): void
    {
        $this->actingAs($this->userWithRole('administrator'))->get(route('ai.settings'))->assertOk()->assertSee('Penyedia AI');
        $this->actingAs($this->userWithRole('content-creator'))->get(route('ai.settings'))->assertForbidden();
    }

    public function test_settings_save_encrypts_key_and_keeps_it_when_blank(): void
    {
        $this->actingAs($this->userWithRole('administrator'));

        Volt::test('pages.settings.ai')
            ->set('enabled', true)
            ->set('provider', 'openai')
            ->set('model', 'gpt-4o-mini')
            ->set('apiKey', 'sk-secret-123')
            ->call('save')
            ->assertHasNoErrors();

        $setting = AiSetting::current();
        $this->assertTrue($setting->enabled);
        $this->assertSame('gpt-4o-mini', $setting->model);
        $this->assertSame('sk-secret-123', $setting->api_key);
        $this->assertNotSame('sk-secret-123', DB::table('ai_settings')->value('api_key'));

        // saving again with a blank key keeps the stored one
        Volt::test('pages.settings.ai')->set('model', 'gpt-4o')->set('apiKey', '')->call('save');
        $this->assertSame('sk-secret-123', AiSetting::current()->api_key);
    }

    public function test_service_reports_disabled_and_unconfigured(): void
    {
        $ai = app(AiService::class);
        $this->assertFalse($ai->enabled());

        AiSetting::current()->update(['enabled' => true, 'model' => 'x', 'api_key' => 'k']);
        $this->assertTrue(app(AiService::class)->enabled());

        AiSetting::current()->update(['enabled' => false]);
        $this->expectException(AiException::class);
        app(AiService::class)->complete('s', 'u');
    }

    public function test_anthropic_driver_generates_caption(): void
    {
        AiSetting::current()->update([
            'enabled' => true, 'provider' => 'anthropic', 'model' => 'claude-opus-5', 'api_key' => 'k',
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Caption keren. #kampus']],
            ]),
        ]);

        $out = app(AiService::class)->suggestCaption('beasiswa', ['facebook'], null);
        $this->assertSame('Caption keren. #kampus', $out);

        Http::assertSent(fn ($r) => $r->hasHeader('anthropic-version', '2023-06-01')
            && $r['model'] === 'claude-opus-5');
    }

    public function test_openai_and_gemini_drivers(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'Judul OpenAI']]]]),
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'Judul Gemini']]]]],
            ]),
        ]);

        AiSetting::current()->update(['enabled' => true, 'provider' => 'openai', 'model' => 'gpt-4o-mini', 'api_key' => 'k']);
        $this->assertSame('Judul OpenAI', app(AiService::class)->suggestTitle('x', [], null));

        AiSetting::current()->update(['provider' => 'gemini', 'model' => 'gemini-2.0-flash']);
        $this->assertSame('Judul Gemini', app(AiService::class)->suggestTitle('x', [], null));
    }

    public function test_driver_error_becomes_ai_exception(): void
    {
        AiSetting::current()->update(['enabled' => true, 'provider' => 'openai', 'model' => 'm', 'api_key' => 'k']);
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'invalid key']], 401)]);

        $this->expectExceptionMessage('invalid key');
        app(AiService::class)->suggestCaption('x', [], null);
    }

    public function test_content_form_shows_ai_button_only_when_enabled(): void
    {
        $creator = $this->userWithRole('content-creator');

        $this->actingAs($creator)->get(route('konten.create'))->assertDontSee('Buat dengan AI');

        AiSetting::current()->update(['enabled' => true, 'provider' => 'openai', 'model' => 'm', 'api_key' => 'k']);
        $this->actingAs($creator)->get(route('konten.create'))->assertSee('Buat dengan AI');
    }

    public function test_generate_draft_modal_fills_title_and_caption(): void
    {
        AiSetting::current()->update(['enabled' => true, 'provider' => 'openai', 'model' => 'm', 'api_key' => 'k']);
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => "JUDUL: Sewa Mobil Mudik Hemat\nCAPTION: Rental lepas kunci mulai 300rb/hari. Pesan sekarang!\n#rentalmobil #mudik",
        ]]]])]);

        $this->actingAs($this->userWithRole('content-creator'));

        Volt::test('pages.contents.form')
            ->set('platforms', ['facebook'])
            ->set('category', 'iklan')
            ->set('aiBrief', 'Promosi rental mobil untuk mudik lebaran area Kendari')
            ->call('generateDraft')
            ->assertHasNoErrors()
            ->assertSet('showAiModal', false)
            ->assertSet('title', 'Sewa Mobil Mudik Hemat')
            ->assertSet('caption', "Rental lepas kunci mulai 300rb/hari. Pesan sekarang!\n#rentalmobil #mudik");

        // the chosen category steers the prompt
        Http::assertSent(fn ($r) => str_contains(
            $r['messages'][0]['content'] ?? '',
            'IKLAN / PROMOSI',
        ));
    }

    public function test_generate_draft_requires_a_brief(): void
    {
        AiSetting::current()->update(['enabled' => true, 'provider' => 'openai', 'model' => 'm', 'api_key' => 'k']);
        $this->actingAs($this->userWithRole('content-creator'));

        Volt::test('pages.contents.form')
            ->set('aiBrief', '')
            ->call('generateDraft')
            ->assertHasErrors('aiBrief');
    }

    public function test_generate_draft_handles_unformatted_reply(): void
    {
        AiSetting::current()->update(['enabled' => true, 'provider' => 'openai', 'model' => 'm', 'api_key' => 'k']);
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => "Diskon Akhir Tahun\nSemua produk potongan 20%. Buruan sebelum kehabisan!",
        ]]]])]);

        $this->actingAs($this->userWithRole('content-creator'));

        Volt::test('pages.contents.form')
            ->set('aiBrief', 'promo diskon akhir tahun')
            ->call('generateDraft')
            ->assertSet('title', 'Diskon Akhir Tahun')
            ->assertSet('caption', 'Semua produk potongan 20%. Buruan sebelum kehabisan!');
    }
}
