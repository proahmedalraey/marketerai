<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\GenerationJob;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * شبكة «الاستوديو»: شرائح الكاروسيل الواحد بطاقة واحدة مكدّسة بدل صورة لكل شريحة،
 * وزر «تكبير» يفتح عارض الكاروسيل. الصور المفردة كما هي.
 */
class ImageStudioCarouselStackTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Brand $brand;

    protected ContentItem $carousel;

    protected GenerationJob $job;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'سارة', 'email' => 'stack@example.com', 'password' => 'secret123']);
        $this->brand = Brand::create(['user_id' => $this->user->id, 'name' => 'إمدادات القهوة', 'credit_balance' => 10, 'credits_allowance' => 10, 'onboarding_completed' => true]);
        $this->user->update(['current_brand_id' => $this->brand->id]);

        $this->job = GenerationJob::create(['brand_id' => $this->brand->id, 'type' => 'image', 'status' => JobStatus::Completed, 'payload' => []]);

        $this->carousel = ContentItem::create([
            'brand_id' => $this->brand->id, 'goal' => 'engagement', 'platform' => 'instagram', 'format' => 'carousel',
            'caption' => 'نكهة المستكة', 'status' => 'ready',
            'body' => ['slides' => [
                ['role' => 'hook', 'text' => 'تبغى نكهة مميزة لمشروباتك؟'],
                ['role' => 'promise', 'text' => 'سيروب مستكة طبيعي'],
                ['role' => 'pull', 'text' => 'مستورد من اليونان'],
                ['role' => 'ask', 'text' => 'اطلبه الحين'],
            ]],
        ]);
    }

    protected function image(array $attributes = []): MediaAsset
    {
        return MediaAsset::create($attributes + [
            'brand_id' => $this->brand->id, 'generation_job_id' => $this->job->id, 'kind' => 'image', 'disk' => 'public',
            'path' => 'brands/1/media/'.uniqid().'.png', 'mime' => 'image/png', 'bytes' => 1, 'width' => 896, 'height' => 1120,
            'prompt' => 'Editorial product photo',
        ]);
    }

    public function test_a_carousels_slides_become_one_stacked_card(): void
    {
        $this->image(['prompt' => 'صورة مفردة قديمة']);
        foreach ([0, 1, 2] as $index) {
            $this->image(['content_item_id' => $this->carousel->id, 'slide_index' => $index]);
        }
        $this->image(['prompt' => 'صورة مفردة جديدة']);

        $response = $this->actingAs($this->user)->get(route('studio.index'))->assertOk();

        $entries = $response->viewData('galleryEntries');
        $this->assertSame(['asset', 'carousel', 'asset'], array_column($entries, 'type'), 'الكاروسيل بطاقة واحدة في موضع أحدث صورة منه');
        $this->assertSame($this->carousel->id, $entries[1]['item']->id);

        $html = $response->getContent();
        // بطاقة واحدة فيها ثلاثة مداخل للعارض: الغلاف، و«تكبير»، و«عرض الشرائح»
        $this->assertSame(3, substr_count($html, 'openCarouselViewer('.$this->carousel->id.', 0)'));
        $this->assertStringContainsString('تكبير وعرض الشرائح', $html);
        $this->assertStringContainsString('3/4 صور جاهزة', $html);
        $this->assertStringContainsString('تبغى نكهة مميزة لمشروباتك؟', $html);
        $this->assertStringContainsString('x-data="carouselViewer"', $html);
        $this->assertStringNotContainsString('الشريحة 2:', $html, 'لا بطاقة مستقلة لكل شريحة');

        // عدد التبويب = عدد البطاقات (الكاروسيل واحدة)
        $response->assertSeeInOrder(['الاستوديو', '3']);
    }

    public function test_an_older_version_of_a_slide_folds_into_the_stack(): void
    {
        $old = $this->image(['content_item_id' => $this->carousel->id, 'slide_index' => 0]);
        $new = $this->image(['content_item_id' => $this->carousel->id, 'slide_index' => 0]);

        $response = $this->actingAs($this->user)->get(route('studio.index'));

        $this->assertCount(1, $response->viewData('galleryEntries'));
        // الغلاف الظاهر هو الأحدث، والتحديد الجماعي يشمل النسختين
        $html = $response->getContent();
        $this->assertStringContainsString('ids: JSON.parse', $html);
        $this->assertMatchesRegularExpression('/ids: JSON\.parse\(\'\[\s*'.$old->id.',\s*'.$new->id.'\s*\]\'\)/', $html);
    }

    public function test_slides_of_a_non_carousel_item_stay_as_single_cards(): void
    {
        $post = ContentItem::create(['brand_id' => $this->brand->id, 'goal' => 'engagement', 'platform' => 'instagram', 'format' => 'post', 'caption' => 'منشور', 'status' => 'ready', 'body' => ['caption' => 'منشور']]);
        $this->image(['content_item_id' => $post->id]);
        $this->image();

        $entries = $this->actingAs($this->user)->get(route('studio.index'))->viewData('galleryEntries');

        $this->assertSame(['asset', 'asset'], array_column($entries, 'type'));
    }

    public function test_the_viewer_reads_the_carousel_it_shows(): void
    {
        $this->image(['content_item_id' => $this->carousel->id, 'slide_index' => 0]);

        $this->actingAs($this->user)->getJson(route('studio.carousels.show', $this->carousel))
            ->assertOk()
            ->assertJsonCount(4, 'slides')
            ->assertJsonPath('urls.content', route('content.show', $this->carousel));
    }
}
