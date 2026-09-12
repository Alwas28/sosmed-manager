<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\Media;
use App\Models\PostTemplateSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\Image\PostTemplateCompositor;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PostTemplateTest extends TestCase
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

    private function samplePhotoBytes(int $w = 900, int $h = 700): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 80, 140, 200));
        ob_start();
        imagejpeg($im);
        $bytes = ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    public function test_only_ai_manager_can_open_post_template_settings(): void
    {
        $this->actingAs($this->userWithRole('administrator'))->get(route('post-template.settings'))
            ->assertOk()->assertSee('Template Postingan');
        $this->actingAs($this->userWithRole('content-creator'))->get(route('post-template.settings'))->assertForbidden();
    }

    public function test_admin_can_save_branding_and_upload_logo(): void
    {
        $this->actingAs($this->userWithRole('administrator'));

        Volt::test('pages.settings.post-template')
            ->set('brandName', 'Kendariinfo')
            ->set('bannerColor', '#0B5E34')
            ->set('textColor', '#FFFFFF')
            ->set('socialPlatforms', ['facebook', 'twitter', 'instagram'])
            ->set('logoUpload', UploadedFile::fake()->image('logo.png', 300, 120))
            ->call('save')
            ->assertHasNoErrors();

        $settings = PostTemplateSetting::current();
        $this->assertSame('Kendariinfo', $settings->brand_name);
        $this->assertTrue($settings->hasLogo());
        Storage::disk('public')->assertExists($settings->logo_path);
    }

    public function test_compositor_draws_banner_logo_and_headline_on_a_plain_photo(): void
    {
        PostTemplateSetting::current()->update([
            'brand_name' => 'Kendariinfo',
            'social_platforms' => ['facebook', 'twitter', 'instagram'],
        ]);

        $result = app(PostTemplateCompositor::class)->compose(
            $this->samplePhotoBytes(),
            'Revitalisasi Tanggul Sungai Wanggu di Kendari Segera Dikerjakan, Kementerian PU Mulai Proses Kontrak',
            'Foto',
        );

        $this->assertSame('image/jpeg', $result['mime']);
        $this->assertNotEmpty($result['bytes']);

        $info = getimagesizefromstring($result['bytes']);
        $this->assertNotFalse($info);
        $this->assertSame(900, $info[0]);
        $this->assertSame(700, $info[1]);
    }

    public function test_compositor_works_without_logo_or_font_configured(): void
    {
        // fresh settings row, no logo/font uploaded
        $result = app(PostTemplateCompositor::class)->compose($this->samplePhotoBytes(400, 300), 'Judul Singkat', 'Foto');

        $this->assertSame('image/jpeg', $result['mime']);
        $this->assertNotFalse(getimagesizefromstring($result['bytes']));
    }

    public function test_content_form_applies_template_and_saves_media(): void
    {
        PostTemplateSetting::current()->update(['brand_name' => 'Kendariinfo']);

        $this->actingAs($this->userWithRole('content-creator'));

        Volt::test('pages.contents.form')
            ->call('openImageModal')
            ->set('imageMode', 'template')
            ->set('templateUpload', UploadedFile::fake()->image('river.jpg', 900, 700))
            ->set('templateHeadline', 'Revitalisasi Tanggul Sungai Wanggu')
            ->set('templateMediaKind', 'video')
            ->call('runTemplateCompose')
            ->assertHasNoErrors();

        $media = Media::first();
        $this->assertNotNull($media);
        $this->assertSame('template', $media->source);
        $this->assertSame('image', $media->type);
        Storage::disk('public')->assertExists($media->path);
    }

    public function test_template_mode_requires_upload_and_headline(): void
    {
        $this->actingAs($this->userWithRole('content-creator'));

        Volt::test('pages.contents.form')
            ->call('openImageModal')
            ->set('imageMode', 'template')
            ->set('templateHeadline', '')
            ->call('runTemplateCompose')
            ->assertHasErrors(['templateUpload', 'templateHeadline']);

        $this->assertDatabaseCount('media', 0);
    }

    public function test_ai_suggest_headline_fills_field(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'Judul Berita Hasil AI']]]])]);
        AiSetting::current()->update(['enabled' => true, 'provider' => 'openai', 'model' => 'gpt-4o-mini', 'api_key' => 'k']);

        $this->actingAs($this->userWithRole('content-creator'));

        Volt::test('pages.contents.form')
            ->call('openImageModal')
            ->set('imageMode', 'template')
            ->call('aiSuggestHeadline')
            ->assertHasNoErrors()
            ->assertSet('templateHeadline', 'Judul Berita Hasil AI');
    }

    public function test_template_tab_does_not_require_an_image_ai_profile(): void
    {
        // no ImageAiProfile rows at all — template mode must still work.
        $this->assertDatabaseCount('image_ai_profiles', 0);

        $this->actingAs($this->userWithRole('content-creator'));

        Volt::test('pages.contents.form')
            ->call('openImageModal')
            ->set('imageMode', 'template')
            ->set('templateUpload', UploadedFile::fake()->image('x.jpg', 600, 500))
            ->set('templateHeadline', 'Judul')
            ->call('runTemplateCompose')
            ->assertHasNoErrors();

        $this->assertSame(1, Media::count());
    }
}
