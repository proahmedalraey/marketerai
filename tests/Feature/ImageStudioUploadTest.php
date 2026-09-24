<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * رفع صورة مرجعية من الجهاز (البند 2.8 من مرحلة استوديو الصور الثانية):
 * حفظ فوري كـMediaAsset عادي بلا توليد ولا نقاط، على نمط BrandLogoController.
 */
class ImageStudioUploadTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['ai.media_disk' => 'public']);

        $this->user = User::create(['name' => 'سارة', 'email' => 'upload@example.com', 'password' => 'secret123']);

        $this->brand = Brand::create([
            'user_id' => $this->user->id,
            'name' => 'محمصة الوادي',
            'credit_balance' => 100,
            'credits_allowance' => 100,
            'onboarding_completed' => true,
        ]);

        $this->user->update(['current_brand_id' => $this->brand->id]);
    }

    public function test_uploading_an_image_creates_a_media_asset_and_returns_its_url(): void
    {
        $file = UploadedFile::fake()->image('reference.png', 400, 300);

        $response = $this->actingAs($this->user)
            ->post(route('studio.uploads'), ['file' => $file]);

        $response->assertOk();

        $asset = MediaAsset::sole();
        $response->assertJson(['id' => $asset->id, 'url' => $asset->url()]);

        $this->assertSame($this->brand->id, $asset->brand_id);
        $this->assertSame('image', $asset->kind);
        $this->assertSame(400, $asset->width);
        $this->assertSame(300, $asset->height);
        $this->assertSame(['source' => 'upload'], $asset->meta);
        $this->assertNull($asset->generation_job_id, 'لا مهمة توليد ولا نقاط لرفع مباشر');
        Storage::disk('public')->assertExists($asset->path);
    }

    public function test_uploading_does_not_charge_any_credits(): void
    {
        $this->actingAs($this->user)->post(route('studio.uploads'), [
            'file' => UploadedFile::fake()->image('reference.png'),
        ]);

        $this->assertEquals(100, $this->brand->refresh()->credit_balance);
    }

    public function test_a_non_image_file_is_rejected(): void
    {
        // الواجهة الفعلية ترسل Accept: application/json (fetch)، فتُختبر بنفس الصيغة
        $this->actingAs($this->user)
            ->postJson(route('studio.uploads'), ['file' => UploadedFile::fake()->create('notes.txt', 10)])
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, MediaAsset::count());
    }

    public function test_a_file_larger_than_the_limit_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('studio.uploads'), ['file' => UploadedFile::fake()->image('big.png')->size(10241)])
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, MediaAsset::count());
    }
}
