<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Models\Brand;
use App\Models\GenerationJob;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** المعرض لا يقصّ صامتاً عند 60 صورة: زر «عرض المزيد» يرفع الحد ويحفظ الفلاتر. */
class ImageStudioGalleryTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'سارة', 'email' => 'gallery@example.com', 'password' => 'secret123']);

        $brand = Brand::create([
            'user_id' => $this->user->id,
            'name' => 'محمصة الوادي',
            'credit_balance' => 100,
            'credits_allowance' => 100,
            'onboarding_completed' => true,
        ]);

        $this->user->update(['current_brand_id' => $brand->id]);

        $job = GenerationJob::create(['brand_id' => $brand->id, 'type' => 'image', 'status' => JobStatus::Completed, 'payload' => []]);

        foreach (range(1, 130) as $i) {
            MediaAsset::create([
                'brand_id' => $brand->id, 'generation_job_id' => $job->id, 'kind' => 'image',
                'disk' => 'public', 'path' => "brands/{$brand->id}/media/{$i}.png", 'mime' => 'image/png',
                'bytes' => 1000, 'width' => 512, 'height' => 512, 'prompt' => "صورة رقم {$i}",
            ]);
        }
    }

    public function test_the_gallery_shows_sixty_then_offers_more(): void
    {
        $response = $this->actingAs($this->user)->get(route('studio.index'));

        $this->assertCount(60, $response->viewData('gallery'));
        $this->assertTrue($response->viewData('galleryHasMore'));
        $response->assertSee('عرض المزيد')->assertSee('limit=120', false);
    }

    public function test_the_limit_grows_and_the_button_disappears_at_the_end(): void
    {
        $second = $this->actingAs($this->user)->get(route('studio.index', ['limit' => 120, 'q' => 'صورة']));

        $this->assertCount(120, $second->viewData('gallery'));
        // البحث محفوظ في رابط الصفحة التالية
        $second->assertSee('limit=180', false)->assertSee('q=', false);

        $last = $this->actingAs($this->user)->get(route('studio.index', ['limit' => 180]));

        $this->assertCount(130, $last->viewData('gallery'));
        $this->assertFalse($last->viewData('galleryHasMore'));
        $last->assertDontSee('عرض المزيد');
    }

    public function test_the_progress_panel_reserves_one_placeholder_per_requested_image(): void
    {
        $job = GenerationJob::create([
            'brand_id' => $this->user->current_brand_id, 'type' => 'image', 'status' => JobStatus::Processing,
            'payload' => ['count' => 2, 'aspect_ratio' => '16:9'],
        ]);

        $response = $this->actingAs($this->user)->get(route('studio.index', ['job' => $job->uuid]));

        $response->assertOk();
        $this->assertSame(2, $response->viewData('trackedCount'));
        $this->assertSame(2, substr_count($response->getContent(), 'style="aspect-ratio: 16 / 9"'));
    }
}
