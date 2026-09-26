<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Models\Brand;
use App\Models\GenerationJob;
use App\Models\MediaAsset;
use App\Models\MediaFolder;
use App\Models\Product;
use App\Models\User;
use App\Services\Media\ImageGenerationService;
use App\Support\ImageRatio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * بنود «قريباً» التي صارت حقيقية في قائمة الصورة ولوحة المجلدات:
 * إزالة الخلفية، حفظ الصورة مرجعاً أساسياً لمنتج، والحذف/النقل الجماعي.
 */
class ImageStudioToolsTest extends TestCase
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

        $this->user = User::create(['name' => 'سارة', 'email' => 'tools@example.com', 'password' => 'secret123']);
        $this->brand = $this->makeBrand($this->user, 'محمصة الوادي');
    }

    protected function makeBrand(User $user, string $name): Brand
    {
        $brand = Brand::create([
            'user_id' => $user->id,
            'name' => $name,
            'credit_balance' => 100,
            'credits_allowance' => 100,
            'onboarding_completed' => true,
        ]);

        $user->update(['current_brand_id' => $brand->id]);

        return $brand;
    }

    /** PNG بخلفية شفافة ومربع ملوّن في الوسط (ما يعيده النموذج بعد إزالة الخلفية). */
    protected function png(int $width, int $height, bool $transparent = false): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, $transparent ? imagecolorallocatealpha($image, 0, 0, 0, 127) : imagecolorallocate($image, 240, 240, 240));
        imagefilledrectangle($image, (int) ($width / 3), (int) ($height / 3), (int) ($width * 2 / 3), (int) ($height * 2 / 3), imagecolorallocate($image, 200, 90, 20));
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    protected function asset(array $overrides = [], ?Brand $brand = null): MediaAsset
    {
        $brand ??= $this->brand;
        $path = "brands/{$brand->id}/media/".uniqid().'.png';
        Storage::disk('public')->put($path, $this->png(1000, 1000));

        $job = GenerationJob::create(['brand_id' => $brand->id, 'type' => 'image', 'status' => JobStatus::Completed, 'payload' => []]);

        return MediaAsset::withoutBrandScope()->create($overrides + [
            'brand_id' => $brand->id, 'generation_job_id' => $job->id, 'kind' => 'image', 'disk' => 'public',
            'path' => $path, 'mime' => 'image/png', 'bytes' => 1000, 'width' => 1000, 'height' => 1000,
            'prompt' => 'علبة سيروب المستكة',
        ]);
    }

    protected function fakeOpenRouter(bool $transparentSupported = true): void
    {
        Http::fake([
            self::BASE.'/images/models' => Http::response(['data' => [
                ['id' => 'openai/gpt-image-2.5-flare', 'supported_parameters' => [
                    'aspect_ratio' => ['values' => ['1:1', '3:2', '2:3', '16:9', '9:16']],
                    'quality' => ['values' => ['low', 'medium', 'high']],
                    'background' => ['values' => $transparentSupported ? ['auto', 'transparent', 'opaque'] : ['auto', 'opaque']],
                    'input_references' => ['type' => 'range', 'min' => 0, 'max' => 16],
                ]],
            ]]),
            self::BASE.'/images' => Http::response([
                'data' => [['b64_json' => base64_encode($this->png(1024, 1024, transparent: true)), 'media_type' => 'image/png']],
                'usage' => ['cost' => 0.0145],
            ]),
        ]);
    }

    protected function sentImageRequest(): array
    {
        return Http::recorded(fn ($r) => str_ends_with($r->url(), '/images'))->first()[0]->data();
    }

    // ------------------------------------------------------------ إزالة الخلفية

    public function test_removing_the_background_creates_a_transparent_copy_beside_the_original(): void
    {
        $this->fakeOpenRouter();
        $folder = MediaFolder::create(['brand_id' => $this->brand->id, 'name' => 'منتجات']);
        $source = $this->asset(['folder_id' => $folder->id]);

        $this->actingAs($this->user)
            ->post(route('studio.media.remove-background', $source))
            ->assertRedirect();

        $body = $this->sentImageRequest();
        $this->assertSame('openai/gpt-image-2.5-flare', $body['model']);
        $this->assertSame('transparent', $body['background']);
        $this->assertSame('1:1', $body['aspect_ratio']);
        $this->assertStringStartsWith('data:image/png;base64,', $body['input_references'][0]['image_url']['url']);
        $this->assertSame(ImageGenerationService::REMOVE_BACKGROUND_PROMPT, $body['prompt']);

        $copy = MediaAsset::whereKeyNot($source->id)->sole();
        $this->assertTrue($copy->isTransparent());
        $this->assertSame($source->id, $copy->meta['source_asset_id']);
        $this->assertSame($folder->id, $copy->folder_id, 'تبقى في مجلد الأصل');
        $this->assertStringStartsWith('بلا خلفية — ', $copy->prompt);
        $this->assertTrue(Storage::disk('public')->exists($source->path), 'الأصل لا يُمس');

        // الشفافية تنجو من الحفظ والمصغّرة (PNG لا JPEG أبيض)
        $saved = imagecreatefromstring(Storage::disk('public')->get($copy->path));
        $this->assertSame(127, (imagecolorat($saved, 2, 2) >> 24) & 0x7F);
        $this->assertStringEndsWith('.thumb.png', $copy->meta['thumb']);
        $thumb = imagecreatefromstring(Storage::disk('public')->get($copy->meta['thumb']));
        $this->assertSame(127, (imagecolorat($thumb, 1, 1) >> 24) & 0x7F);

        // سعر ثابت نقطة واحدة
        $this->assertEquals(99, $this->brand->refresh()->credit_balance);
    }

    public function test_the_ratio_of_the_original_is_kept_and_cropped_with_alpha(): void
    {
        $this->fakeOpenRouter();
        $source = $this->asset(['width' => 864, 'height' => 1080]);

        $this->actingAs($this->user)->post(route('studio.media.remove-background', $source))->assertRedirect();

        // 4:5 غير مدعومة عند النموذج: تُطلب الأقرب ثم يُقصّ الناتج لنسبة الأصل
        $copy = MediaAsset::whereKeyNot($source->id)->sole();
        $this->assertSame('4:5', $copy->meta['aspect_ratio']);
        $this->assertSame([819, 1024], [$copy->width, $copy->height]);

        $saved = imagecreatefromstring(Storage::disk('public')->get($copy->path));
        $this->assertSame(127, (imagecolorat($saved, 1, 1) >> 24) & 0x7F, 'القصّ يحفظ الشفافية');
    }

    public function test_it_needs_openrouter_and_a_model_with_transparency(): void
    {
        $source = $this->asset();

        config(['ai.image_provider' => 'fake']);
        $this->actingAs($this->user)->post(route('studio.media.remove-background', $source))
            ->assertSessionHasErrors('remove_background');

        config(['ai.image_provider' => 'openrouter']);
        $this->fakeOpenRouter(transparentSupported: false);
        $this->actingAs($this->user)->post(route('studio.media.remove-background', $source))
            ->assertSessionHasErrors('remove_background');

        $this->assertSame(0, GenerationJob::where('payload->mode', 'remove_background')->count());
        $this->assertEquals(100, $this->brand->refresh()->credit_balance);
    }

    public function test_a_source_deleted_before_the_job_runs_refunds_the_credit(): void
    {
        $this->fakeOpenRouter();
        config(['queue.default' => 'database']);
        $source = $this->asset();

        $job = app(ImageGenerationService::class)->dispatchRemoveBackground($this->brand, $source, $this->user->id);
        $this->assertEquals(99, $this->brand->refresh()->credit_balance);

        $source->deleteFiles();
        $source->delete();

        app(ImageGenerationService::class)->run($job->fresh());

        $this->assertSame(JobStatus::Failed, $job->fresh()->status);
        $this->assertEquals(100, $this->brand->refresh()->credit_balance);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/images'));
    }

    public function test_regenerating_a_background_removal_repeats_it(): void
    {
        $this->fakeOpenRouter();
        $source = $this->asset();
        $this->actingAs($this->user)->post(route('studio.media.remove-background', $source));
        $copy = MediaAsset::whereKeyNot($source->id)->sole();

        $this->actingAs($this->user)->post(route('studio.regenerate', $copy))->assertRedirect();

        $this->assertSame(2, MediaAsset::where('meta->mode', 'remove_background')->count());
        $this->assertEquals(98, $this->brand->refresh()->credit_balance, 'بسعر إزالة الخلفية لا بسعر الجودة');
    }

    public function test_another_brands_image_cannot_be_processed(): void
    {
        $this->fakeOpenRouter();
        $other = User::create(['name' => 'ليلى', 'email' => 'other-tools@example.com', 'password' => 'secret123']);
        $foreign = $this->asset([], $this->makeBrand($other, 'متجر آخر'));
        $this->user->update(['current_brand_id' => $this->brand->id]);

        $this->actingAs($this->user)->post(route('studio.media.remove-background', $foreign))->assertNotFound();
        $this->actingAs($this->user)->post(route('studio.media.to-product', $foreign), ['product_id' => 1])->assertNotFound();
    }

    // ------------------------------------------------------------ حفظ الصورة مرجعاً لمنتج

    public function test_saving_an_image_as_the_products_main_reference(): void
    {
        $product = Product::create(['brand_id' => $this->brand->id, 'title' => 'سيروب المستكة', 'is_active' => true]);
        $old = $product->images()->create(['disk' => 'public', 'path' => 'brands/x/old.jpg', 'position' => 0, 'is_reference' => true]);
        $asset = $this->asset();

        $this->actingAs($this->user)
            ->post(route('studio.media.to-product', $asset), ['product_id' => $product->id])
            ->assertRedirect()
            ->assertSessionHas('status');

        $new = $product->images()->whereKeyNot($old->id)->sole();
        $this->assertTrue($new->is_reference);
        $this->assertFalse($old->fresh()->is_reference);
        $this->assertSame($new->id, $product->referenceImage()->id);
        $this->assertSame(1, $new->position);

        // نسخة مستقلة: حذف الصورة من المعرض لا يُفقد المنتج مرجعه
        $this->assertNotSame($asset->path, $new->path);
        $asset->deleteFiles();
        Storage::disk('public')->assertExists($new->path);
    }

    public function test_a_full_product_or_another_brands_product_is_refused(): void
    {
        $service = Product::create(['brand_id' => $this->brand->id, 'title' => 'استشارة', 'type' => 'service', 'is_active' => true]);
        $service->images()->create(['disk' => 'public', 'path' => 'brands/x/a.jpg', 'position' => 0, 'is_reference' => true]);
        $asset = $this->asset();

        $this->actingAs($this->user)->post(route('studio.media.to-product', $asset), ['product_id' => $service->id])
            ->assertSessionHasErrors('product_id');
        $this->assertSame(1, $service->images()->count());

        $other = User::create(['name' => 'ليلى', 'email' => 'other-p@example.com', 'password' => 'secret123']);
        $foreignBrand = $this->makeBrand($other, 'متجر آخر');
        $foreign = Product::withoutBrandScope()->create(['brand_id' => $foreignBrand->id, 'title' => 'منتج غيري', 'is_active' => true]);
        $this->user->update(['current_brand_id' => $this->brand->id]);

        $this->actingAs($this->user)->post(route('studio.media.to-product', $asset), ['product_id' => $foreign->id])
            ->assertSessionHasErrors('product_id');
        $this->assertSame(0, $foreign->images()->count());
    }

    // ------------------------------------------------------------ الحذف والنقل الجماعي

    public function test_bulk_delete_removes_only_this_brands_images_and_their_files(): void
    {
        $a = $this->asset();
        $b = $this->asset();
        $keep = $this->asset();

        $other = User::create(['name' => 'ليلى', 'email' => 'other-b@example.com', 'password' => 'secret123']);
        $foreign = $this->asset([], $this->makeBrand($other, 'متجر آخر'));
        $this->user->update(['current_brand_id' => $this->brand->id]);

        $this->actingAs($this->user)
            ->post(route('studio.media.bulk-destroy'), ['ids' => [$a->id, $b->id, $foreign->id]])
            ->assertRedirect()
            ->assertSessionHas('status', 'حُذفت 2 صور نهائياً.');

        $this->assertDatabaseMissing('media_assets', ['id' => $a->id]);
        $this->assertDatabaseMissing('media_assets', ['id' => $b->id]);
        Storage::disk('public')->assertMissing($a->path);
        $this->assertDatabaseHas('media_assets', ['id' => $keep->id]);
        $this->assertDatabaseHas('media_assets', ['id' => $foreign->id]);
        Storage::disk('public')->assertExists($foreign->path);
    }

    public function test_bulk_move_and_validation(): void
    {
        $folder = MediaFolder::create(['brand_id' => $this->brand->id, 'name' => 'حملة رمضان']);
        $a = $this->asset();
        $b = $this->asset(['folder_id' => $folder->id]);

        $this->actingAs($this->user)
            ->post(route('studio.media.bulk-move'), ['ids' => [$a->id], 'folder_id' => $folder->id])
            ->assertRedirect();
        $this->assertSame($folder->id, $a->fresh()->folder_id);

        $this->actingAs($this->user)->post(route('studio.media.bulk-move'), ['ids' => [$a->id, $b->id], 'folder_id' => '']);
        $this->assertNull($a->fresh()->folder_id);
        $this->assertNull($b->fresh()->folder_id);

        $this->actingAs($this->user)->post(route('studio.media.bulk-destroy'), ['ids' => []])->assertSessionHasErrors('ids');
    }

    // ------------------------------------------------------------ الواجهة

    public function test_the_studio_has_no_coming_soon_items_left(): void
    {
        $this->asset();
        Product::create(['brand_id' => $this->brand->id, 'title' => 'سيروب المستكة', 'is_active' => true]);

        $html = $this->actingAs($this->user)->get(route('studio.index'))->assertOk()->getContent();

        $this->assertFalse(str_contains($html, '>قريباً<'), 'بقي بند معطّل بوسم قريباً');
        $this->assertFalse(str_contains($html, 'قريباً — إدخال صوتي'), 'الميكروفون ما زال معطّلاً');

        foreach (['toggleVoice()', 'shareAsset(', 'openProductPicker(', 'startSelecting()', 'bulk-destroy', 'remove-background', 'studio-dock'] as $needle) {
            $this->assertTrue(str_contains($html, $needle), "غائب من الصفحة: {$needle}");
        }

        // قوائم الشريط لا تحمل click.outside خاصاً بها (سبب الوميض): واحد على الشريط كله
        $this->assertSame(0, substr_count($html, '@click.outside="popover = null"'));
        $this->assertSame(1, substr_count($html, '@click.outside="closePopovers()"'));
    }

    public function test_ratio_crop_keeps_transparency(): void
    {
        $cropped = ImageRatio::crop($this->png(1024, 1024, transparent: true), '4:5');
        $image = imagecreatefromstring($cropped['contents']);

        $this->assertSame(127, (imagecolorat($image, 0, 0) >> 24) & 0x7F);
    }
}
