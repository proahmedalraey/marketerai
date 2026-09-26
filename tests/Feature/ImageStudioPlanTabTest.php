<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\GenerationJob;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * تبويب «من خطة المحتوى» في استوديو الصور: شهر واحد من الخطة، شرائح كل كاروسيل كاملة،
 * وزر توليد يعمل (كان يُرسل بلا stage فيرفضه الخادم) ويعيد التاجر إلى «الاستوديو».
 */
class ImageStudioPlanTabTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Brand $brand;

    protected ContentItem $carousel;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['ai.media_disk' => 'public', 'ai.image_provider' => 'fake', 'ai.proofread.enabled' => false]);

        $this->user = User::create(['name' => 'سارة', 'email' => 'plan-tab@example.com', 'password' => 'secret123']);
        $this->brand = Brand::create([
            'user_id' => $this->user->id,
            'name' => 'إمدادات القهوة',
            'credit_balance' => 100,
            'credits_allowance' => 100,
            'onboarding_completed' => true,
        ]);
        $this->user->update(['current_brand_id' => $this->brand->id]);

        $product = Product::create(['brand_id' => $this->brand->id, 'title' => 'اسينزا سيروب المستكة 1 لتر', 'is_active' => true]);

        $this->carousel = $this->item([
            'product_id' => $product->id,
            'template' => 'value_carousel',
            'body' => ['slides' => [
                ['role' => 'hook', 'text' => 'ليه الطعم القديم · تغيّر بلمسة واحدة', 'visual' => 'Syrup bottle on a shelf'],
                ['role' => 'promise', 'text' => 'كنت أحسب نكهة المشروب المميزة تحتاج تعقيد', 'visual' => 'Iced matcha latte'],
                ['role' => 'pull', 'text' => 'أول رشفة ما كانت مجرد حلاوة', 'visual' => 'Close-up of a sip'],
                ['role' => 'harvest', 'text' => 'النكهات العريقة مو بس للمشروبات الساخنة', 'visual' => 'Hot and cold cups'],
                ['role' => 'ask', 'text' => 'شاركني تجربتك في التعليقات', 'visual' => 'Hand holding a cup'],
            ]],
        ]);
    }

    protected function item(array $overrides = []): ContentItem
    {
        return ContentItem::create($overrides + [
            'brand_id' => $this->brand->id,
            'goal' => 'engagement',
            'platform' => 'instagram',
            'format' => 'carousel',
            'caption' => 'نكهة المستكة',
            'status' => 'ready',
            'in_plan' => true,
            'planned_for' => now()->startOfMonth()->addDays(3),
            'body' => ['slides' => []],
        ]);
    }

    protected function studio(array $query = [])
    {
        return $this->actingAs($this->user)->get(route('studio.index', $query));
    }

    public function test_the_studio_tab_is_named_studio(): void
    {
        $html = $this->studio()->assertOk()->getContent();

        $this->assertStringContainsString('الاستوديو', $html);
        $this->assertDoesNotMatchRegularExpression('/>\s*المعرض\s*</u', $html, 'اسم التبويب القديم باقٍ');
        $this->assertSame('studio', $this->studio()->viewData('activeTab'));
        $this->assertSame('plan', $this->studio(['tab' => 'plan'])->viewData('activeTab'));
    }

    public function test_the_plan_tab_shows_this_months_plan_with_every_slide(): void
    {
        $this->item(['caption' => 'مسودة لم تُضف للخطة', 'in_plan' => false]);
        $this->item(['caption' => 'منشور الشهر القادم', 'planned_for' => now()->addMonthNoOverflow()->startOfMonth()->addDays(2)]);

        $response = $this->studio(['tab' => 'plan']);

        $this->assertSame([$this->carousel->id], $response->viewData('planItems')->pluck('id')->all());

        $response->assertSeeInOrder(['محتوى قيمي', 'كاروسيل', 'إنستغرام'])
            ->assertSee('الشريحة 1:')
            ->assertSee('ليه الطعم القديم · تغيّر بلمسة واحدة')
            ->assertSee('الشريحة 5:')
            ->assertSee('شاركني تجربتك في التعليقات')
            ->assertSee('اسينزا سيروب المستكة 1 لتر')
            ->assertSee('توليد صور الكاروسيل')
            ->assertSee(now()->translatedFormat('F'))
            ->assertDontSee('مسودة لم تُضف للخطة')
            ->assertDontSee('منشور الشهر القادم');
    }

    public function test_months_are_navigable_and_a_bad_month_falls_back_to_now(): void
    {
        $next = now()->addMonthNoOverflow();
        $this->item(['caption' => 'منشور الشهر القادم', 'format' => 'post', 'planned_for' => $next->copy()->startOfMonth()->addDays(2)]);

        $response = $this->studio(['tab' => 'plan', 'plan_month' => $next->format('Y-m')]);

        $response->assertSee('منشور الشهر القادم')->assertDontSee('الشريحة 1:');
        $response->assertSee('plan_month='.$next->copy()->subMonthNoOverflow()->format('Y-m'), false);
        $response->assertSee('plan_month='.$next->copy()->addMonthNoOverflow()->format('Y-m'), false);

        $this->assertSame(now()->format('Y-m'), $this->studio(['tab' => 'plan', 'plan_month' => '2026-13'])->viewData('planMonth')->format('Y-m'));
    }

    public function test_every_generate_button_sends_its_stage(): void
    {
        $html = $this->studio(['tab' => 'plan'])->getContent();

        // الزر القديم كان نموذجاً بلا stage: الخادم يرفضه بـ«حقل stage مطلوب» ولا يولّد شيئاً
        preg_match_all('#<form method="POST" action="[^"]*/studio/carousel/\d+">(.*?)</form>#s', $html, $forms);
        $this->assertNotEmpty($forms[1]);

        foreach ($forms[1] as $form) {
            $this->assertStringContainsString('name="stage"', $form);
            $this->assertStringContainsString('name="return" value="studio"', $form);
        }
    }

    public function test_generating_from_the_plan_tab_returns_to_the_studio_where_the_images_appear(): void
    {
        $response = $this->actingAs($this->user)->post(route('studio.carousel', $this->carousel), ['stage' => 'cover', 'return' => 'studio']);

        $parent = GenerationJob::where('type', 'carousel_images')->sole();
        $response->assertRedirect(route('studio.index', ['job' => $parent->uuid]));

        $cover = MediaAsset::where('content_item_id', $this->carousel->id)->sole();
        $this->assertSame(0, $cover->slide_index);
        $this->assertEquals(99, $this->brand->refresh()->credit_balance, 'الغلاف وحده: صورة واحدة');

        // الاستوديو يعرض الصورة بنص شريحتها لا ببرومبت الصورة الإنجليزي
        $this->studio(['job' => $parent->uuid])
            ->assertSee('الشريحة 1:')
            ->assertSee($cover->thumbUrl());

        // بعد الغلاف: البطاقة تعرض صورته وتعرض إكمال بقية الشرائح
        $this->studio(['tab' => 'plan'])
            ->assertSee('أكمل 4 شرائح')
            ->assertSee('value="rest"', false)
            ->assertDontSee('توليد صور الكاروسيل');

        $rest = $this->actingAs($this->user)->post(route('studio.carousel', $this->carousel), ['stage' => 'rest', 'return' => 'studio']);
        $restJob = GenerationJob::where('type', 'carousel_images')->latest('id')->first();
        $rest->assertRedirect(route('studio.index', ['job' => $restJob->uuid]));

        // هياكل الانتظار بعدد الشرائح المطلوبة ونسبتها
        $tracking = $this->studio(['job' => $restJob->uuid]);
        $this->assertSame(4, $tracking->viewData('trackedCount'));
        $this->assertSame('4:5', $tracking->viewData('trackedRatio'));

        $this->assertCount(5, $this->carousel->fresh()->load('mediaAssets')->slideImages());
        $this->studio(['tab' => 'plan'])->assertSee('الصور جاهزة');
    }

    public function test_the_content_page_keeps_its_own_return(): void
    {
        $this->actingAs($this->user)
            ->post(route('studio.carousel', $this->carousel), ['stage' => 'cover'])
            ->assertRedirect(route('content.show', [$this->carousel, 'job' => GenerationJob::where('type', 'carousel_images')->sole()->uuid]));
    }

    public function test_the_generation_dock_is_hidden_on_the_plan_tab(): void
    {
        $html = $this->studio(['tab' => 'plan'])->getContent();

        $this->assertMatchesRegularExpression('/class="sticky bottom-4 z-30" x-show="activeTab === \'studio\'"\s+x-cloak/', $html);
    }
}
