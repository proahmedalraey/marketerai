<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\GenerationJob;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Media\StudioModels;
use App\Services\Settings\AiSettings;
use App\Support\ImageRatio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * توجيه نماذج استوديو الصور فعلياً عبر OpenRouter (المرحلة الثانية، البند 2.1).
 * الاختيار في الواجهة يصل إلى الطلب، وما لا يدعمه النموذج يُرفض قبل الحجز،
 * والنسبة غير المدعومة تُنتَج بأقرب نسبة ثم تُقصّ للمطلوبة.
 */
class ImageStudioModelsTest extends TestCase
{
    use RefreshDatabase;

    protected const BASE = 'openrouter.ai/api/v1';

    protected User $user;

    protected Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Storage::fake('public');

        config([
            'ai.image_provider' => 'openrouter',
            'ai.providers.openrouter.api_key' => 'sk-or-v1-test',
            'ai.media_disk' => 'public',
            'ai.retry.times' => 0,
        ]);

        $this->user = User::create(['name' => 'سارة', 'email' => 'models@example.com', 'password' => 'secret123']);

        $this->brand = Brand::create([
            'user_id' => $this->user->id,
            'name' => 'محمصة الوادي',
            'credit_balance' => 100,
            'credits_allowance' => 100,
            'onboarding_completed' => true,
        ]);

        $this->user->update(['current_brand_id' => $this->brand->id]);
    }

    protected function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 200, 90, 20));
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    /** قدرات مطابقة لما قاسه OpenRouter فعلياً (2026-09-25). */
    protected function fakeOpenRouter(int $width = 1024, int $height = 1024): void
    {
        $ratios = ['1:1', '3:2', '2:3', '4:3', '3:4', '16:9', '9:16', 'auto'];

        Http::fake([
            self::BASE.'/images/models' => Http::response(['data' => [
                ['id' => 'google/gemini-3.1-flash-image', 'supported_parameters' => [
                    'resolution' => ['values' => ['512', '1K', '2K', '4K']],
                    'aspect_ratio' => ['values' => ['1:1', '4:5', '16:9', '9:16']],
                    'input_references' => ['type' => 'range', 'min' => 0, 'max' => 14],
                ]],
                ['id' => 'openai/gpt-image-2.5-flare', 'supported_parameters' => [
                    'aspect_ratio' => ['values' => $ratios],
                    'quality' => ['values' => ['auto', 'low', 'medium', 'high', 'xhigh', 'max']],
                    'input_references' => ['type' => 'range', 'min' => 0, 'max' => 16],
                ]],
                ['id' => 'x-ai/grok-imagine-image-2.0', 'supported_parameters' => [
                    'resolution' => ['values' => ['1K', '2K']],
                    'aspect_ratio' => ['values' => ['1:1', '16:9', '9:16']],
                    'quality' => ['values' => ['low', 'medium']],
                    'input_references' => ['type' => 'range', 'min' => 0, 'max' => 3],
                ]],
            ]]),
            self::BASE.'/images' => Http::response([
                'data' => [['b64_json' => base64_encode($this->png($width, $height)), 'media_type' => 'image/png']],
                'usage' => ['cost' => 0.0085],
            ]),
        ]);
    }

    protected function generate(array $overrides = [])
    {
        return $this->actingAs($this->user)->post(route('studio.generate'), $overrides + [
            'prompt' => 'كوب قهوة',
            'aspect_ratio' => '1:1',
            'quality' => '1k_low',
            'count' => 1,
            'use_brand_identity' => 0,
            'model' => 'gpt_image_flare',
        ]);
    }

    protected function sentBody(): array
    {
        $sent = Http::recorded(fn ($r) => str_ends_with($r->url(), '/images'))->first();

        return $sent[0]->data();
    }

    public function test_the_selected_model_reaches_openrouter_and_is_recorded(): void
    {
        $this->fakeOpenRouter();

        $this->generate(['model' => 'grok_imagine_2'])->assertRedirect();

        $this->assertSame('x-ai/grok-imagine-image-2.0', $this->sentBody()['model']);
        $this->assertSame('x-ai/grok-imagine-image-2.0', MediaAsset::sole()->meta['model']);
    }

    public function test_quality_and_resolution_are_mapped_for_each_model(): void
    {
        $this->fakeOpenRouter();

        // GPT: "عالية جداً" = xhigh، بلا معامل دقة
        $this->generate(['model' => 'gpt_image_flare', 'quality' => '1k_very_high']);
        // Nano Banana: 4K تُرسَل 4K، وبلا معامل جودة
        $this->generate(['model' => 'nano_banana_2', 'quality' => '4k_medium']);

        $bodies = Http::recorded(fn ($r) => str_ends_with($r->url(), '/images'))
            ->mapWithKeys(fn ($pair) => [$pair[0]['model'] => $pair[0]->data()]);

        $gpt = $bodies['openai/gpt-image-2.5-flare'];
        $this->assertSame('xhigh', $gpt['quality']);
        $this->assertArrayNotHasKey('resolution', $gpt);

        $banana = $bodies['google/gemini-3.1-flash-image'];
        $this->assertSame('4K', $banana['resolution']);
        $this->assertArrayNotHasKey('quality', $banana);
    }

    public function test_an_unsupported_resolution_is_rejected_before_charging(): void
    {
        $this->fakeOpenRouter();

        $this->generate(['model' => 'gpt_image_flare', 'quality' => '4k_medium'])
            ->assertSessionHasErrors('quality');

        // والصفحة تعرض سبب الرفض بدل أن يبدو الضغط بلا أثر
        $this->actingAs($this->user)->get(route('studio.index'))->assertSee('لا يدعم دقة 4K');

        $this->assertSame(0, GenerationJob::count());
        $this->assertEquals(100, $this->brand->refresh()->credit_balance);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/images'));
    }

    public function test_a_quality_level_the_model_cannot_apply_is_rejected(): void
    {
        $this->fakeOpenRouter();

        // Grok 2.0 يقبل low/medium فقط؛ Nano Banana بلا معامل جودة فيُعرض متوسطة وحدها
        $this->generate(['model' => 'grok_imagine_2', 'quality' => '1k_max'])->assertSessionHasErrors('quality');
        $this->generate(['model' => 'nano_banana_2', 'quality' => '1k_high'])->assertSessionHasErrors('quality');

        $this->assertSame(0, GenerationJob::count());
    }

    public function test_an_unsupported_ratio_is_generated_nearest_then_cropped(): void
    {
        $this->fakeOpenRouter(1024, 1024);

        // 9:8 لا يدعمه أي نموذج: يُطلب 1:1 (الأقرب) ثم تُقصّ النتيجة إلى 9:8
        $this->generate(['aspect_ratio' => '9:8'])->assertRedirect();

        $this->assertSame('1:1', $this->sentBody()['aspect_ratio']);

        $asset = MediaAsset::sole();
        $this->assertSame(1024, $asset->width);
        $this->assertSame(910, $asset->height);
        $this->assertSame('9:8', $asset->meta['aspect_ratio']);
        $this->assertSame('1024x1024', $asset->meta['cropped_from']);

        $real = getimagesizefromstring(Storage::disk('public')->get($asset->path));
        $this->assertSame([1024, 910], [$real[0], $real[1]], 'الأبعاد المسجلة هي أبعاد الملف الفعلية');
    }

    public function test_a_matching_ratio_is_not_cropped(): void
    {
        $this->fakeOpenRouter(1536, 864);

        $this->generate(['aspect_ratio' => '16:9'])->assertRedirect();

        $asset = MediaAsset::sole();
        $this->assertSame([1536, 864], [$asset->width, $asset->height]);
        $this->assertArrayNotHasKey('cropped_from', $asset->meta);
    }

    public function test_regenerating_keeps_the_same_model(): void
    {
        $this->fakeOpenRouter();

        $this->generate(['model' => 'grok_imagine_2'])->assertRedirect();
        $asset = MediaAsset::sole();

        $this->post(route('studio.regenerate', $asset))->assertRedirect();

        $bodies = Http::recorded(fn ($r) => str_ends_with($r->url(), '/images'))->map(fn ($pair) => $pair[0]['model']);
        $this->assertSame(['x-ai/grok-imagine-image-2.0', 'x-ai/grok-imagine-image-2.0'], $bodies->values()->all());
    }

    public function test_an_unknown_model_key_is_rejected(): void
    {
        $this->fakeOpenRouter();

        $this->generate(['model' => 'made_up'])->assertSessionHasErrors('model');
        $this->assertSame(0, GenerationJob::count());
    }

    public function test_the_choice_is_ignored_when_the_provider_is_not_openrouter(): void
    {
        config(['ai.image_provider' => 'fake']);

        $this->generate(['model' => 'grok_imagine_2', 'quality' => '4k_medium'])->assertRedirect();

        // بلا توجيه: لا قيود قدرات ولا طلبات خارجية، والمزوّد المُعدّ يعمل كما هو
        $this->assertFalse(app(StudioModels::class)->routable());
        $this->assertNull(app(StudioModels::class)->modelId('grok_imagine_2'));
        $this->assertSame('fake-image-1', MediaAsset::sole()->meta['model']);
        Http::assertNothingSent();
    }

    public function test_generated_images_get_a_grid_thumbnail(): void
    {
        $this->fakeOpenRouter(1024, 1024);

        $this->generate()->assertRedirect();

        $asset = MediaAsset::sole();
        $thumb = $asset->meta['thumb'] ?? null;

        $this->assertNotNull($thumb);
        Storage::disk('public')->assertExists($thumb);
        $this->assertSame(480, getimagesizefromstring(Storage::disk('public')->get($thumb))[0]);

        // الشبكة تعرض المصغّرة، والأصل يبقى للعارض والتنزيل
        $html = $this->actingAs($this->user)->get(route('studio.index'))->getContent();
        $this->assertStringContainsString('src="'.$asset->thumbUrl().'"', $html);
        $this->assertStringContainsString('href="'.$asset->url().'" download', $html);
    }

    public function test_a_reference_image_keeps_the_product_label_instead_of_banning_logos(): void
    {
        $this->fakeOpenRouter();
        $this->actingAs($this->user)
            ->post(route('studio.uploads'), ['file' => UploadedFile::fake()->image('product.png', 600, 600)])
            ->assertOk();

        $this->generate(['reference_asset_id' => MediaAsset::latest('id')->first()->id, 'prompt' => 'علبة سيروب على رف'])
            ->assertRedirect();

        $prompt = $this->sentBody()['prompt'];

        // «لا شعارات ولا نص» كانت تمحو اسم المنتج من العبوة نفسها (اختبار حي 2026-09-25)
        $this->assertStringContainsString('shows the exact product', $prompt);
        $this->assertStringContainsString('every word printed on it', $prompt);
        $this->assertStringNotContainsString('no logos', $prompt);
        $this->assertStringContainsString('Scene: علبة سيروب على رف', $prompt);
        $this->assertStringStartsWith('The attached reference image', $prompt, 'تعليمة المنتج أولاً');
    }

    public function test_without_a_reference_no_text_or_logos_are_requested(): void
    {
        $this->fakeOpenRouter();

        $this->generate(['prompt' => 'كوب قهوة'])->assertRedirect();

        $prompt = $this->sentBody()['prompt'];

        $this->assertStringContainsString('no logos', $prompt);
        $this->assertStringNotContainsString('exact product', $prompt);
    }

    public function test_platform_settings_override_env_for_the_web_request(): void
    {
        // .env يقول fake، ومدير المنصة اختار openrouter من /settings/ai
        config(['ai.image_provider' => 'fake']);
        app(AiSettings::class)->save(['ai.image_provider' => 'openrouter']);

        // طلب ويب جديد: config عاد لقيمة البيئة ولم يُطبَّق أي إعداد بعد
        config(['ai.image_provider' => 'fake']);
        app()->forgetInstance(AiSettings::class);
        app()->forgetInstance(StudioModels::class);
        $this->fakeOpenRouter();

        $response = $this->actingAs($this->user)->get(route('studio.index'));

        $response->assertOk();
        $this->assertTrue($response->viewData('modelsRoutable'));
        $this->assertSame('openrouter', app(StudioModels::class)->provider());

        // ويصل الاختيار للتنفيذ لا للعرض فقط
        $this->generate(['model' => 'grok_imagine_2', 'quality' => '1k_low'])->assertRedirect();
        $this->assertSame('x-ai/grok-imagine-image-2.0', MediaAsset::latest('id')->first()->meta['model']);
    }

    public function test_the_page_exposes_capabilities_to_the_interface(): void
    {
        $this->fakeOpenRouter();

        $response = $this->actingAs($this->user)->get(route('studio.index'));

        $response->assertOk();
        $caps = $response->viewData('modelCaps');

        $this->assertSame(['1k', '2k', '4k'], $caps['nano_banana_2']['resolutions']);
        $this->assertSame(['medium'], $caps['nano_banana_2']['qualities']);
        $this->assertSame(['1k'], $caps['gpt_image_flare']['resolutions']);
        $this->assertSame(['low', 'medium', 'high', 'very_high', 'max'], $caps['gpt_image_flare']['qualities']);
        $this->assertSame(['low', 'medium'], $caps['grok_imagine_2']['qualities']);
    }

    public function test_image_ratio_crops_from_the_center_only_when_needed(): void
    {
        $tall = $this->png(1000, 1500);

        $result = ImageRatio::crop($tall, '1:1');
        $this->assertSame([1000, 1000], [$result['width'], $result['height']]);
        $this->assertSame('1000x1500', $result['from']);

        $this->assertNull(ImageRatio::crop($tall, '2:3'), 'نفس النسبة: لا قصّ');
        $this->assertNull(ImageRatio::crop($tall, 'garbage'));
    }
}
