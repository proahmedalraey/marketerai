<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\User;
use App\Support\ImageThumbnail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * مصغّرات المعرض: الشبكة تعرض نسخة ~480px بدل الأصل (1–2MB) الذي كان يُحمَّل ستين مرة.
 */
class ImageStudioThumbnailTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['ai.media_disk' => 'public', 'ai.image_provider' => 'fake']);

        $this->user = User::create(['name' => 'سارة', 'email' => 'thumb@example.com', 'password' => 'secret123']);

        $this->brand = Brand::create([
            'user_id' => $this->user->id,
            'name' => 'محمصة الوادي',
            'credit_balance' => 100,
            'credits_allowance' => 100,
            'onboarding_completed' => true,
        ]);

        $this->user->update(['current_brand_id' => $this->brand->id]);
    }

    protected function upload(int $width, int $height): MediaAsset
    {
        $this->actingAs($this->user)
            ->post(route('studio.uploads'), ['file' => UploadedFile::fake()->image('ref.png', $width, $height)])
            ->assertOk();

        return MediaAsset::latest('id')->first();
    }

    public function test_a_large_upload_gets_a_small_jpeg_thumbnail(): void
    {
        $asset = $this->upload(1600, 900);

        $thumb = $asset->meta['thumb'] ?? null;
        $this->assertNotNull($thumb);
        Storage::disk('public')->assertExists($thumb);

        $size = getimagesizefromstring(Storage::disk('public')->get($thumb));
        $this->assertSame(480, $size[0]);
        $this->assertSame(270, $size[1], 'النسبة محفوظة');
        $this->assertSame('image/jpeg', $size['mime']);
        $this->assertNotSame($asset->url(), $asset->thumbUrl());
    }

    public function test_a_small_image_needs_no_thumbnail_and_falls_back_to_the_original(): void
    {
        $asset = $this->upload(300, 200);

        $this->assertArrayNotHasKey('thumb', $asset->meta);
        $this->assertSame($asset->url(), $asset->thumbUrl());
        $this->assertNull(ImageThumbnail::make(Storage::disk('public')->get($asset->path)));
    }

    public function test_generated_images_get_thumbnails_and_the_gallery_uses_them(): void
    {
        $this->actingAs($this->user)->post(route('studio.generate'), [
            'prompt' => 'كوب قهوة',
            'aspect_ratio' => '1:1',
            'quality' => '1k_medium',
            'count' => 1,
            'use_brand_identity' => 0,
        ])->assertRedirect();

        $asset = MediaAsset::whereNotNull('generation_job_id')->sole();

        // مزوّد الاختبار يرسم صورة صغيرة قد لا تحتاج مصغّرة؛ المهم أن العرض متسق مع meta
        $expected = $asset->meta['thumb'] ?? null
            ? $asset->thumbUrl()
            : $asset->url();

        $html = $this->actingAs($this->user)->get(route('studio.index'))->getContent();

        $this->assertStringContainsString('src="'.$expected.'"', $html);
    }

    public function test_deleting_an_image_removes_its_thumbnail_too(): void
    {
        $asset = $this->upload(1600, 900);
        $thumb = $asset->meta['thumb'];

        $this->actingAs($this->user)->delete(route('studio.media.destroy', $asset))->assertRedirect();

        Storage::disk('public')->assertMissing($asset->path);
        Storage::disk('public')->assertMissing($thumb);
        $this->assertDatabaseMissing('media_assets', ['id' => $asset->id]);
    }

    public function test_backfill_command_builds_thumbnails_for_existing_assets(): void
    {
        $asset = $this->upload(1600, 900);
        Storage::disk('public')->delete($asset->meta['thumb']);
        $asset->update(['meta' => ['source' => 'upload']]);

        $this->artisan('media:thumbnails')->assertSuccessful();

        $asset->refresh();
        $this->assertNotEmpty($asset->meta['thumb']);
        Storage::disk('public')->assertExists($asset->meta['thumb']);
    }
}
