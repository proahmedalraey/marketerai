<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\GenerationJob;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\AI\AiManager;
use App\Services\AI\DTO\ImageRequest;
use App\Services\AI\DTO\ImageResponse;
use App\Services\AI\ProviderException;
use App\Services\Content\ContentGenerationService;
use App\Services\Settings\AiSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ScriptedAiManager;
use Tests\TestCase;

/**
 * المرحلة 4 من خطة التدقيق: الكاروسيل البصري.
 *
 * الغلاف أولاً ثم البقية بالغلاف مرجعاً، وإعادة شريحة واحدة (نصاً بنقطة أو
 * صورة بسعرها) — وهي غير موجودة عند المنافس — وحفظ الترتيب مع صوره.
 */
class CarouselStudioTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Brand $brand;

    protected ContentItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['ai.media_disk' => 'public', 'ai.proofread.enabled' => false]);

        $this->user = User::create(['name' => 'محمد', 'email' => 'carousel@example.com', 'password' => 'secret123']);

        $this->brand = Brand::create([
            'user_id' => $this->user->id,
            'name' => 'بن الديرة',
            'description' => 'حبوب قهوة مختصة محمصة',
            'selling_points' => ['طحن حسب طريقة التحضير عند الطلب'],
            'audience' => 'محبو القهوة المختصة',
            'dialect' => 'msa',
            'colors' => [['hex' => '#1F3A2E', 'role' => 'primary'], ['hex' => '#E8833A', 'role' => 'accent']],
            'fonts' => ['ar_primary' => 'Cairo'],
            'credit_balance' => 100,
            'credits_allowance' => 100,
            'onboarding_completed' => true,
        ]);

        $this->user->update(['current_brand_id' => $this->brand->id]);

        $this->item = ContentItem::create([
            'brand_id' => $this->brand->id,
            'goal' => 'direct_sales',
            'platform' => 'instagram',
            'format' => 'carousel',
            'template' => 'marketing_carousel',
            'caption' => 'يرقاشيفي بتحميص فاتح.',
            'status' => 'ready',
            'body' => [
                'caption' => 'يرقاشيفي بتحميص فاتح.',
                'slides' => [
                    ['role' => 'hook', 'text' => 'قهوتك خيبت أملك؟ السر في الطحنة', 'kicker' => 'قهوتك خيبت أملك؟', 'focal' => 'السر', 'tail' => 'في الطحنة', 'visual' => 'Coffee beans on a dark table'],
                    ['role' => 'promise', 'text' => 'ستعرف لماذا تختلف القهوة المطحونة لأداتك.', 'visual' => 'Hand grinder close-up'],
                    ['role' => 'pull', 'text' => 'نطحنها حسب طريقة تحضيرك عند الطلب.', 'visual' => 'Ground coffee in a scoop'],
                    ['role' => 'pull', 'text' => 'تحميص فاتح يكشف نكهات الياسمين.', 'visual' => 'Jasmine flowers beside a cup'],
                    ['role' => 'harvest', 'text' => 'الطحنة المناسبة نصف الكوب.', 'visual' => 'Pour over brewing'],
                    ['role' => 'ask', 'text' => 'اطلب عبوتك الآن.', 'visual' => 'Coffee bag on a counter'],
                ],
                'hashtags' => ['قهوة_مختصة'],
            ],
        ]);
    }

    /** مدير نماذج يسجّل طلبات الصور، ويرفض ما يطلب الاختبار رفضه. */
    protected function imageSpy(string $failOn = '___'): object
    {
        $spy = new class(app(AiSettings::class)) extends AiManager
        {
            public array $imageRequests = [];

            public string $failOn = '___';

            public function generateImage(ImageRequest $request, ?GenerationJob $job = null, ?string $provider = null): ImageResponse
            {
                $this->imageRequests[] = $request;

                if (str_contains($request->prompt, $this->failOn)) {
                    throw new ProviderException('رفض المزود هذه الصورة', 'fake', 400);
                }

                return parent::generateImage($request, $job, $provider);
            }
        };

        $spy->failOn = $failOn;
        app()->instance(AiManager::class, $spy);

        return $spy;
    }

    protected function stage(string $stage, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->post("/studio/carousel/{$this->item->id}", ['stage' => $stage, 'quality' => 'standard_1k', ...$extra]);
    }

    protected function images(): array
    {
        return $this->item->fresh()->load('mediaAssets')->slideImages();
    }

    // ================================================================
    //  الغلاف أولاً
    // ================================================================

    public function test_the_cover_is_generated_alone_and_costs_one_image(): void
    {
        $this->imageSpy();

        $response = $this->stage('cover', ['aspect_ratio' => '4:5']);

        $parent = GenerationJob::where('type', 'carousel_images')->sole();
        $response->assertRedirect(route('content.show', [$this->item, 'job' => $parent->uuid]));

        $this->assertSame([0], array_keys($this->images()));
        $this->assertEquals(99, $this->brand->refresh()->credit_balance);
        $this->assertSame(JobStatus::Completed, $parent->refresh()->status, 'المهمة الأم تُغلق حين يكتمل أبناؤها');
        $this->assertSame(1, $parent->children_done);
        $this->assertSame(0, (int) $parent->credits_held, 'لا حجز معلّق على الأم');
    }

    public function test_the_rest_follow_the_cover_as_their_visual_reference(): void
    {
        $spy = $this->imageSpy();
        $this->stage('cover');
        $cover = $this->images()[0];

        $this->stage('rest');

        $this->assertSame([0, 1, 2, 3, 4, 5], array_keys($this->images()));
        $this->assertEquals(94, $this->brand->refresh()->credit_balance, 'غلاف + خمس شرائح، لا أكثر');

        $rest = array_slice($spy->imageRequests, 1);
        $this->assertCount(5, $rest);

        foreach ($rest as $request) {
            $this->assertSame(Storage::disk('public')->path($cover->path), $request->referenceImage);
            $this->assertStringContainsString('Match the reference image', $request->prompt);
            $this->assertStringContainsString('no text', $request->prompt);
        }

        $this->assertStringContainsString('Hand grinder close-up', $rest[0]->prompt, 'التوجيه البصري للشريحة يصل لصورتها');
    }

    public function test_the_rest_cannot_come_before_the_cover(): void
    {
        $this->imageSpy();

        $this->stage('rest')->assertSessionHasErrors('stage');
        $this->assertSame(0, GenerationJob::count());
    }

    public function test_a_failed_slide_refunds_itself_and_the_rest_are_delivered(): void
    {
        $this->imageSpy(failOn: 'Jasmine flowers');
        $this->stage('cover');

        $this->stage('rest');

        $parent = GenerationJob::where('type', 'carousel_images')->latest('id')->first();
        $this->assertSame(JobStatus::Partial, $parent->status);
        $this->assertSame(5, $parent->children_done);
        $this->assertSame([0, 1, 2, 4, 5], array_keys($this->images()));
        $this->assertEquals(95, $this->brand->refresh()->credit_balance, 'الشريحة الفاشلة أُرجعت نقطتها');
    }

    public function test_one_slide_image_can_be_regenerated_and_the_newest_is_shown(): void
    {
        $this->imageSpy();
        $this->stage('cover');
        $this->stage('rest');
        $before = $this->images()[3]->id;

        $this->stage('slide', ['index' => 3]);

        $this->assertNotSame($before, $this->images()[3]->id);
        $this->assertEquals(93, $this->brand->refresh()->credit_balance);
    }

    // ================================================================
    //  إعادة كتابة شريحة واحدة: نقطة واحدة
    // ================================================================

    protected function rewrite(int $index, array $input, array ...$replies): \Illuminate\Testing\TestResponse
    {
        $ai = ScriptedAiManager::install();
        $ai->replyWith(...$replies);
        $this->ai = $ai;

        return $this->actingAs($this->user)->post("/content/{$this->item->id}/slides/{$index}/rewrite", $input);
    }

    public function test_a_slide_is_rewritten_alone_for_one_credit(): void
    {
        $before = $this->item->slides();

        $this->rewrite(2, ['direction' => 'shorter'], ['text' => 'نطحنها لأداتك عند الطلب.', 'visual' => 'Grinder dial close-up'])
            ->assertRedirect();

        $after = $this->item->fresh()->slides();

        $this->assertSame('نطحنها لأداتك عند الطلب.', $after[2]['text']);
        $this->assertSame('Grinder dial close-up', $after[2]['visual']);
        $this->assertSame('pull', $after[2]['role']);
        $this->assertSame(array_diff_key($before, [2 => 1]), array_diff_key($after, [2 => 1]), 'باقي الشرائح لم تُمس');
        $this->assertEquals(99, $this->brand->refresh()->credit_balance);

        $request = $this->ai->lastRequest();
        $this->assertSame(ContentGenerationService::SLIDE_OPERATION, $request->operation);
        $this->assertStringContainsString('أعد كتابة الشريحة رقم 3 وحدها', $request->prompt);
        $this->assertStringContainsString('اجعلها أقصر', $request->prompt);
        $this->assertStringContainsString('1. [hook]', $request->prompt, 'الشرائح الأخرى سياقٌ لها');
        $this->assertSame(3, $this->item->fresh()->quality['rewritten']);
    }

    public function test_a_rewritten_slide_passes_the_honesty_gate_too(): void
    {
        $this->rewrite(2, [],
            ['text' => 'تكفيك العبوة 15 كوباً، ونطحنها مجاناً.', 'visual' => 'x'],
            ['text' => 'نطحنها حسب طريقة تحضيرك.', 'visual' => 'x'],
        );

        $this->assertCount(2, $this->ai->requests);
        $this->assertStringContainsString('«15»', $this->ai->lastRequest()->prompt);
        $this->assertSame('نطحنها حسب طريقة تحضيرك.', $this->item->fresh()->slides()[2]['text']);
        $this->assertEquals(99, $this->brand->refresh()->credit_balance, 'التصحيح لا يُخصم');
    }

    public function test_a_note_that_would_be_ignored_is_refused_before_charging(): void
    {
        ScriptedAiManager::install();

        $this->actingAs($this->user)->post("/content/{$this->item->id}/slides/1/rewrite", ['note' => 'قل إننا الأفضل'])
            ->assertSessionHasErrors('note');

        $this->assertEquals(100, $this->brand->refresh()->credit_balance);
    }

    // ================================================================
    //  الترتيب والحذف والإضافة: تُحفظ مع صورها
    // ================================================================

    protected function row(int|string $origin, array $slide): array
    {
        return ['origin' => $origin, 'role' => $slide['role'], 'text' => $slide['text']];
    }

    public function test_reordering_moves_each_image_with_its_slide(): void
    {
        foreach ([0, 2, 4] as $index) {
            MediaAsset::create([
                'brand_id' => $this->brand->id, 'content_item_id' => $this->item->id, 'kind' => 'image',
                'disk' => 'public', 'path' => "s{$index}.png", 'slide_index' => $index,
            ]);
        }

        $s = $this->item->slides();

        $this->actingAs($this->user)->put("/content/{$this->item->id}", [
            'caption' => 'يرقاشيفي بتحميص فاتح.',
            'slides' => [
                ['origin' => 0, 'role' => 'hook', 'text' => $s[0]['text'], 'kicker' => 'قهوتك خيبت أملك؟', 'focal' => 'السر كله', 'tail' => 'في الطحنة'],
                $this->row(2, $s[2]),
                $this->row(1, $s[1]),
                $this->row('new', ['role' => 'pull', 'text' => 'شريحة أضافها التاجر.']),
                $this->row(3, $s[3]),
                $this->row(5, $s[5]),
                // الشريحة 4 حُذفت
            ],
        ])->assertSessionHasNoErrors();

        $item = $this->item->fresh();
        $slides = $item->slides();

        $this->assertSame('قهوتك خيبت أملك؟ السر كله في الطحنة', $slides[0]['text'], 'نص الهوك مجموع طبقاته');
        $this->assertSame($s[2]['text'], $slides[1]['text']);
        $this->assertSame('Ground coffee in a scoop', $slides[1]['visual'], 'التوجيه البصري يتبع شريحته');
        $this->assertSame('شريحة أضافها التاجر.', $slides[3]['text']);
        $this->assertCount(6, $slides);

        $byPath = MediaAsset::pluck('slide_index', 'path');
        $this->assertSame(0, $byPath['s0.png']);
        $this->assertSame(1, $byPath['s2.png'], 'صورة الشريحة 3 انتقلت معها إلى الموضع 2');
        $this->assertNull($byPath['s4.png'], 'صورة الشريحة المحذوفة فُصلت وبقيت في الاستوديو');
    }

    public function test_a_carousel_keeps_at_least_three_slides(): void
    {
        $s = $this->item->slides();

        $this->actingAs($this->user)->put("/content/{$this->item->id}", [
            'slides' => [$this->row(0, $s[0]), $this->row(1, $s[1])],
        ])->assertSessionHasErrors('slides');

        $this->assertCount(6, $this->item->fresh()->slides());
    }

    // ================================================================
    //  الصفحة
    // ================================================================

    public function test_the_page_feeds_the_composer_and_offers_the_cover_first(): void
    {
        $html = $this->actingAs($this->user)->get("/content/{$this->item->id}")->assertOk()->getContent();

        $this->assertStringContainsString('contentEditor(', $html);
        $this->assertStringContainsString('ولّد الغلاف أولاً', $html);
        $this->assertStringContainsString('#E8833A', $html, 'لون العلامة المميز يصل للمركّب');
        $this->assertStringContainsString('Cairo', $html, 'وخطها');
        // النص العربي يصل مرمّزاً داخل JSON؛ يكفي أن طبقات الهوك وصلت
        $this->assertStringContainsString('focal', $html);

        $this->imageSpy();
        $this->stage('cover');

        $this->actingAs($this->user)->get("/content/{$this->item->id}")
            ->assertSee('اعتمد الغلاف وأكمل 5 شرائح')
            ->assertSee('غلاف آخر');
    }
}
