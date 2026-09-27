<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\GenerationJob;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * نافذة «نظام الكاروسيل» في الاستوديو: قراءة الكاروسيل، وحفظ النص والتصميم (مجاني)،
 * و«حفظ كنسخة جديدة» بصور منسوخة، وصورة شريحة جديدة دون مغادرة النافذة.
 */
class CarouselEditorTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Brand $brand;

    protected ContentItem $item;

    protected MediaAsset $cover;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['ai.media_disk' => 'public', 'ai.image_provider' => 'fake', 'ai.text_provider' => 'fake', 'ai.proofread.enabled' => false]);

        $this->user = User::create(['name' => 'سارة', 'email' => 'carousel-editor@example.com', 'password' => 'secret123']);
        $this->brand = Brand::create([
            'user_id' => $this->user->id,
            'name' => 'إمدادات القهوة',
            'colors' => [['hex' => '#1F3A2E', 'role' => 'primary'], ['hex' => '#E8833A', 'role' => 'accent']],
            'fonts' => ['ar_primary' => 'Cairo'],
            'credit_balance' => 100,
            'credits_allowance' => 100,
            'onboarding_completed' => true,
        ]);
        $this->user->update(['current_brand_id' => $this->brand->id]);

        $this->item = ContentItem::create([
            'brand_id' => $this->brand->id,
            'goal' => 'engagement',
            'platform' => 'instagram',
            'format' => 'carousel',
            'template' => 'value_carousel',
            'caption' => 'نكهة المستكة',
            'status' => 'ready',
            'in_plan' => true,
            'planned_for' => '2026-09-23',
            'body' => ['caption' => 'نكهة المستكة', 'slides' => [
                ['role' => 'hook', 'text' => 'نكهة تقليدية نجاح عصري', 'visual' => 'Syrup bottle on a cafe counter'],
                ['role' => 'promise', 'text' => 'ستعرف كيف تضيف المستكة لمشروباتك', 'visual' => 'Iced matcha latte'],
                ['role' => 'pull', 'text' => 'ملعقة صغيرة تكفي للكوب', 'visual' => 'Measuring spoon'],
                ['role' => 'harvest', 'text' => 'التوازن سر الطعم', 'visual' => 'Two cups side by side'],
                ['role' => 'ask', 'text' => 'شاركنا تجربتك في التعليقات', 'visual' => 'Hand holding a cup'],
            ]],
        ]);

        $job = GenerationJob::create(['brand_id' => $this->brand->id, 'type' => 'image', 'status' => JobStatus::Completed, 'payload' => []]);
        Storage::disk('public')->put("brands/{$this->brand->id}/media/cover.png", $this->png());

        $this->cover = MediaAsset::create([
            'brand_id' => $this->brand->id, 'content_item_id' => $this->item->id, 'generation_job_id' => $job->id,
            'kind' => 'image', 'disk' => 'public', 'path' => "brands/{$this->brand->id}/media/cover.png", 'mime' => 'image/png',
            'bytes' => 10, 'width' => 896, 'height' => 1120, 'prompt' => 'slide', 'slide_index' => 0,
            'meta' => ['aspect_ratio' => '4:5'],
        ]);
    }

    protected function png(): string
    {
        $image = imagecreatetruecolor(896, 1120);
        imagefill($image, 0, 0, imagecolorallocate($image, 120, 90, 60));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    protected function slidesPayload(array $overrides = []): array
    {
        return collect($this->item->fresh()->slides())
            ->map(fn ($slide, $index) => ($overrides[$index] ?? []) + $slide + ['origin' => $index])
            ->values()->all();
    }

    public function test_the_editor_loads_the_carousel_with_its_images_design_and_brand(): void
    {
        $data = $this->actingAs($this->user)->getJson(route('studio.carousels.show', $this->item))->assertOk()->json();

        $this->assertCount(5, $data['slides']);
        $this->assertSame(0, $data['slides'][0]['origin']);
        $this->assertSame('Syrup bottle on a cafe counter', $data['slides'][0]['visual']);
        $this->assertSame([0], array_keys($data['images']));
        $this->assertSame(['template' => 'coffee_modern', 'date' => 'none', 'quality' => 'standard_1k'], $data['design']);
        $this->assertSame('4:5', $data['ratio']);
        $this->assertSame('#E8833A', $data['brand']['accent']);
        $this->assertSame('Cairo', $data['brand']['font']);
        $this->assertStringContainsString('سبتمبر', $data['dates']['month']);
        $this->assertCount(6, $data['templates']);
    }

    public function test_only_carousels_of_this_brand_open(): void
    {
        $post = ContentItem::create(['brand_id' => $this->brand->id, 'goal' => 'engagement', 'platform' => 'instagram', 'format' => 'post', 'caption' => 'منشور', 'status' => 'ready', 'body' => ['caption' => 'منشور']]);
        $this->actingAs($this->user)->getJson(route('studio.carousels.show', $post))->assertNotFound();

        $other = User::create(['name' => 'ليلى', 'email' => 'other-ce@example.com', 'password' => 'secret123']);
        $otherBrand = Brand::create(['user_id' => $other->id, 'name' => 'متجر آخر', 'credit_balance' => 10, 'credits_allowance' => 10, 'onboarding_completed' => true]);
        $other->update(['current_brand_id' => $otherBrand->id]);

        $this->actingAs($other)->getJson(route('studio.carousels.show', $this->item))->assertNotFound();
        $this->actingAs($other)->putJson(route('studio.carousels.update', $this->item), ['slides' => $this->slidesPayload()])->assertNotFound();
    }

    public function test_saving_keeps_text_design_and_sanitised_layout_for_free(): void
    {
        $response = $this->actingAs($this->user)->putJson(route('studio.carousels.update', $this->item), [
            'slides' => $this->slidesPayload([
                0 => ['text' => 'نكهة تقليدية، نجاح عصري', 'layout' => ['x' => 5, 'y' => 0.4, 'w' => 0.7, 'scale' => 1.3, 'color' => '#f59e0b', 'bold' => false, 'evil' => '<script>']],
                1 => ['visual' => 'مشهد مقهى دافئ بإضاءة جانبية', 'layout' => ['color' => 'red; background:url(x)']],
                2 => ['image' => false],
            ]),
            'design' => ['template' => 'bold_dark', 'date' => 'day_month', 'quality' => 'high_1k'],
        ])->assertOk();

        $response->assertJsonPath('carousel.design', ['template' => 'bold_dark', 'date' => 'day_month', 'quality' => 'high_1k']);

        $slides = $this->item->fresh()->slides();
        $this->assertSame('نكهة تقليدية، نجاح عصري', $slides[0]['text']);
        $this->assertSame(['x' => 0.9, 'y' => 0.4, 'w' => 0.7, 'scale' => 1.3, 'color' => '#F59E0B', 'bold' => false], $slides[0]['layout']);
        $this->assertSame('Syrup bottle on a cafe counter', $slides[0]['visual'], 'ما لم يُرسل يبقى');
        $this->assertSame('مشهد مقهى دافئ بإضاءة جانبية', $slides[1]['visual']);
        $this->assertArrayNotHasKey('layout', $slides[1], 'لون غير صالح لا يُخزَّن');
        $this->assertFalse($slides[2]['image']);
        $this->assertArrayNotHasKey('image', $slides[3]);

        $this->assertEquals(100, $this->brand->refresh()->credit_balance, 'التعديل مجاني');

        // تصميم غير معروف يعود للافتراضي
        $this->actingAs($this->user)->putJson(route('studio.carousels.update', $this->item), [
            'slides' => $this->slidesPayload(), 'design' => ['template' => 'hacker', 'date' => 'year'],
        ])->assertJsonPath('carousel.design.template', 'coffee_modern')->assertJsonPath('carousel.design.date', 'none');
    }

    public function test_reordering_and_deleting_slides_moves_their_images(): void
    {
        $slides = $this->slidesPayload();
        // الغلاف (الأصل 0) إلى الموضع الثالث، وحذف الشريحة 4
        $reordered = [$slides[1], $slides[2], $slides[0], $slides[3]];

        $this->actingAs($this->user)->putJson(route('studio.carousels.update', $this->item), ['slides' => $reordered])->assertOk();

        $this->assertSame(2, $this->cover->fresh()->slide_index);
        $this->assertCount(4, $this->item->fresh()->slides());
    }

    public function test_a_carousel_keeps_at_least_three_slides(): void
    {
        $slides = $this->slidesPayload();

        $this->actingAs($this->user)->putJson(route('studio.carousels.update', $this->item), ['slides' => [$slides[0], $slides[1]]])
            ->assertStatus(422)->assertJsonValidationErrors('slides');

        // شرائح بلا نص تسقط، فلا تُحتسب ضمن الثلاث
        $this->actingAs($this->user)->putJson(route('studio.carousels.update', $this->item), [
            'slides' => [$slides[0], $slides[1], ['origin' => null, 'text' => '']],
        ])->assertStatus(422);

        $this->assertCount(5, $this->item->fresh()->slides());
    }

    public function test_the_honesty_check_reruns_after_saving(): void
    {
        $response = $this->actingAs($this->user)->putJson(route('studio.carousels.update', $this->item), [
            'slides' => $this->slidesPayload([2 => ['text' => 'اطلب الآن بخصم 50 بالمئة وتوصيل مجاني']]),
        ])->assertOk();

        $response->assertJsonPath('needsReview', true);
        $this->assertTrue($this->item->fresh()->needsReview());
    }

    public function test_save_as_a_new_copy_leaves_the_original_and_copies_the_images(): void
    {
        $response = $this->actingAs($this->user)->postJson(route('studio.carousels.copy', $this->item), [
            'slides' => $this->slidesPayload([0 => ['text' => 'نسخة بعنوان جديد']]),
            'design' => ['template' => 'heritage'],
        ])->assertCreated();

        $copy = ContentItem::whereKeyNot($this->item->id)->sole();
        $response->assertJsonPath('carousel.id', $copy->id);

        $this->assertSame('نكهة تقليدية نجاح عصري', $this->item->fresh()->slides()[0]['text'], 'الأصل كما هو');
        $this->assertSame('نسخة بعنوان جديد', $copy->slides()[0]['text']);
        $this->assertSame('heritage', $copy->body['design']['template']);
        $this->assertTrue($copy->in_plan);
        $this->assertSame('2026-09-23', $copy->planned_for->toDateString());

        $copiedCover = $copy->mediaAssets()->sole();
        $this->assertSame(0, $copiedCover->slide_index);
        $this->assertNotSame($this->cover->path, $copiedCover->path);
        $this->assertNull($copiedCover->generation_job_id, 'لا تتكرر في شبكة الاستوديو');
        Storage::disk('public')->assertExists($copiedCover->path);

        // حذف صورة الأصل لا يُفقد النسخة صورتها
        $this->cover->deleteFiles();
        Storage::disk('public')->assertExists($copiedCover->path);
    }

    public function test_a_slide_image_is_regenerated_from_the_modal_as_a_json_job(): void
    {
        config(['queue.default' => 'database']);

        $response = $this->actingAs($this->user)->postJson(route('studio.carousel', $this->item), ['stage' => 'slide', 'index' => 2, 'quality' => 'standard_1k'])
            ->assertStatus(202);

        $parent = GenerationJob::where('type', 'carousel_images')->sole();
        $response->assertJson(['uuid' => $parent->uuid, 'status_url' => route('api.jobs.show', $parent)]);
        $this->assertSame([2], $parent->payload['indices']);
    }

    public function test_slides_with_their_image_turned_off_are_skipped_by_the_rest(): void
    {
        $this->actingAs($this->user)->putJson(route('studio.carousels.update', $this->item), [
            'slides' => $this->slidesPayload([2 => ['image' => false], 4 => ['image' => false]]),
        ])->assertOk();

        config(['queue.default' => 'database']);
        $this->actingAs($this->user)->post(route('studio.carousel', $this->item), ['stage' => 'rest']);

        $this->assertSame([1, 3], GenerationJob::where('type', 'carousel_images')->sole()->payload['indices']);
    }

    public function test_rewriting_a_slide_keeps_its_design(): void
    {
        $this->actingAs($this->user)->putJson(route('studio.carousels.update', $this->item), [
            'slides' => $this->slidesPayload([1 => ['layout' => ['y' => 0.6, 'color' => '#FFFFFF'], 'image' => false]]),
        ])->assertOk();

        $this->actingAs($this->user)->post(route('content.slides.rewrite', [$this->item, 1]))->assertRedirect();

        $slide = $this->item->fresh()->slides()[1];
        $this->assertSame(['y' => 0.6, 'color' => '#FFFFFF'], $slide['layout']);
        $this->assertFalse($slide['image']);
    }

    public function test_the_studio_offers_the_editor_on_carousel_images_and_plan_cards(): void
    {
        $html = $this->actingAs($this->user)->get(route('studio.index'))->assertOk()->getContent();

        $this->assertStringContainsString('openCarouselEditor('.$this->item->id.', 0)', $html);
        $this->assertStringContainsString('تعديل الكاروسيل', $html);
        $this->assertStringContainsString('x-data="carouselEditor"', $html);
        $this->assertMatchesRegularExpression('#carouselDataUrl.{1,90}studio.{1,12}carousels.{1,12}__ITEM__#', $html);
    }

    public function test_the_content_page_draws_the_saved_design(): void
    {
        $this->actingAs($this->user)->putJson(route('studio.carousels.update', $this->item), [
            'slides' => $this->slidesPayload(), 'design' => ['template' => 'vibrant', 'date' => 'month'],
        ])->assertOk();

        $html = $this->actingAs($this->user)->get(route('content.show', $this->item))->assertOk()->getContent();

        $this->assertStringContainsString('vibrant', $html);
    }
}
