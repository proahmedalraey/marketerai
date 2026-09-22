<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\GenerationJob;
use App\Models\Offer;
use App\Models\Product;
use App\Models\Testimonial;
use App\Models\User;
use App\Services\Content\ContentGenerationService;
use App\Services\Content\Quality\ContentQualityCheck;
use App\Services\Import\ProductImporter;
use App\Services\Import\StoreCrawler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\ScriptedAiManager;
use Tests\TestCase;

/**
 * المرحلة 2 من خطة التدقيق: حقائق البيع.
 *
 * كل ما اخترعه نموذج المنافس حين غابت البيانات — كود خصم، تقييمات، شهادة
 * عميل، توصيل — له هنا مصدر حقيقي. والاختبار في الاتجاهين: الحقيقة المسجّلة
 * تمر، وما لم يُسجَّل (أو انتهى) يُلتقط.
 */
class SalesFactsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Brand $brand;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 9, 21)->startOfDay());

        $this->user = User::create(['name' => 'محمد', 'email' => 'facts@example.com', 'password' => 'secret123']);

        $this->brand = Brand::create([
            'user_id' => $this->user->id,
            'name' => 'بن الديرة',
            'description' => 'حبوب قهوة مختصة محمصة',
            'audience' => 'محبو القهوة المختصة',
            'dialect' => 'saudi',
            'credit_balance' => 100,
            'credits_allowance' => 100,
            'onboarding_completed' => true,
        ]);

        $this->user->update(['current_brand_id' => $this->brand->id]);

        $this->product = Product::create([
            'brand_id' => $this->brand->id,
            'type' => 'good',
            'title' => 'حبوب يرقاشيفي إثيوبية',
            'features' => 'درجة تحميص فاتحة',
            'price' => 75,
            'currency' => 'SAR',
            'is_primary' => true,
            'is_active' => true,
        ]);
    }

    /** البرومبت ودفتر الحقائق كما يبنيهما التوليد الحقيقي، بلا استدعاء نموذج. */
    protected function builder(?Product $product = null, array $payload = [])
    {
        return app(ContentGenerationService::class)->builderFor(
            $this->brand->fresh(),
            ($product ?? $this->product)->fresh(),
            ['template' => 'problem_solution', 'platform' => 'instagram', ...$payload],
        );
    }

    protected function check(string $caption, ?Product $product = null)
    {
        $builder = $this->builder($product);

        return (new ContentQualityCheck)->check(
            ['caption' => $caption, 'slides' => [], 'hashtags' => []],
            $builder->facts(),
            $builder->qualityOptions(),
        );
    }

    protected function offer(array $attributes = []): Offer
    {
        return Offer::create([
            'brand_id' => $this->brand->id,
            'title' => 'خصم اليوم الوطني',
            'type' => 'percent',
            'value' => 15,
            'coupon_code' => 'KSA96',
            'ends_at' => '2026-09-30',
            'is_active' => true,
            ...$attributes,
        ]);
    }

    // ================================================================
    //  العروض: تُذكر ما دامت سارية، وتختفي بعدها
    // ================================================================

    public function test_a_running_offer_reaches_the_prompt_and_its_code_is_allowed(): void
    {
        $this->offer();

        $this->assertStringContainsString('خصم اليوم الوطني: خصم 15%، بكود KSA96، حتى 30 سبتمبر 2026', $this->builder()->prompt());

        $report = $this->check('خصم 15% على يرقاشيفي بكود KSA96 حتى نهاية الشهر.');
        $this->assertTrue($report->passes(), json_encode($report->issues(), JSON_UNESCAPED_UNICODE));
    }

    public function test_an_expired_offer_is_gone_and_its_code_becomes_an_invention(): void
    {
        $this->offer(['ends_at' => '2026-09-20']);

        $this->assertStringNotContainsString('KSA96', $this->builder()->prompt());
        $this->assertStringContainsString('خصم أو كود خصم', $this->builder()->prompt(), 'بلا عرض سارٍ، الخصم يُسمّى غائباً');
        $this->assertTrue($this->check('استخدم كود KSA96 للخصم.')->has('invented_code'));
    }

    public function test_an_offer_that_has_not_started_is_not_mentioned_yet(): void
    {
        $this->offer(['starts_at' => '2026-10-01', 'ends_at' => '2026-10-31']);

        $this->assertStringNotContainsString('KSA96', $this->builder()->prompt());
    }

    public function test_a_paused_offer_is_not_mentioned(): void
    {
        $this->offer(['is_active' => false]);

        $this->assertStringNotContainsString('KSA96', $this->builder()->prompt());
    }

    public function test_a_product_offer_applies_to_its_product_only(): void
    {
        $other = Product::create(['brand_id' => $this->brand->id, 'type' => 'good', 'title' => 'مطحنة يدوية', 'features' => 'مطحنة']);
        $this->offer(['product_id' => $other->id, 'coupon_code' => 'GRIND10']);

        $this->assertStringContainsString('GRIND10', $this->builder($other)->prompt());
        $this->assertStringNotContainsString('GRIND10', $this->builder()->prompt());
    }

    public function test_offers_are_created_with_their_rules(): void
    {
        $this->actingAs($this->user)->post('/store/offers', [
            'title' => 'خصم الافتتاح', 'type' => 'percent', 'value' => 150,
        ])->assertSessionHasErrors('value');

        $this->actingAs($this->user)->post('/store/offers', [
            'title' => 'خصم', 'type' => 'percent', 'value' => 10, 'coupon_code' => 'خصم 10',
        ])->assertSessionHasErrors('coupon_code');

        $this->actingAs($this->user)->post('/store/offers', [
            'title' => 'خصم', 'type' => 'percent', 'value' => 10, 'ends_at' => '2026-09-01',
        ])->assertSessionHasErrors('ends_at');

        $this->actingAs($this->user)->post('/store/offers', [
            'title' => 'خصم الافتتاح', 'type' => 'percent', 'value' => 10, 'coupon_code' => 'open10',
        ])->assertSessionHasNoErrors();

        $this->assertSame('OPEN10', Offer::sole()->coupon_code);
    }

    public function test_an_arabic_validation_message_explains_the_problem(): void
    {
        $this->actingAs($this->user)->post('/store/offers', ['type' => 'percent', 'value' => 10])
            ->assertSessionHasErrors(['title' => 'اسم العرض مطلوب.']);

        $this->actingAs($this->user)->post('/store/offers', [
            'title' => 'خصم', 'type' => 'percent', 'value' => 10, 'ends_at' => '2026-09-01',
        ])->assertSessionHasErrors(['ends_at' => 'نهاية العرض يجب أن يكون اليوم أو بعده.']);
    }

    public function test_another_brands_offer_cannot_be_touched(): void
    {
        $stranger = Brand::create(['user_id' => $this->user->id, 'name' => 'متجر آخر']);
        $offer = Offer::withoutBrandScope()->create([
            'brand_id' => $stranger->id, 'title' => 'عرضهم', 'type' => 'other', 'is_active' => true,
        ]);

        $this->actingAs($this->user)->delete("/store/offers/{$offer->id}")->assertNotFound();
        $this->assertNotNull(Offer::withoutBrandScope()->find($offer->id));
    }

    // ================================================================
    //  حقائق المتجر: الموجب وحده يدخل الدفتر
    // ================================================================

    public function test_store_facts_are_saved_and_become_allowed_claims(): void
    {
        $this->actingAs($this->user)->put('/store/facts', [
            'delivery' => 'توصيل داخل الرياض خلال يوم',
            'free_shipping' => 'over',
            'free_shipping_over' => 200,
            'channels' => ['retail', 'wholesale'],
            'payments' => ['mada', 'tabby'],
        ])->assertSessionHasNoErrors();

        $prompt = $this->builder()->prompt();
        $this->assertStringContainsString('شحن مجاني للطلبات فوق 200 ريال', $prompt);
        $this->assertStringContainsString('البيع بالتجزئة والجملة', $prompt);
        $this->assertStringContainsString('طرق الدفع: مدى، تابي (تقسيط)', $prompt);

        $this->assertTrue($this->check('توصيل داخل الرياض خلال يوم، وشحن مجاني فوق 200 ريال.')->passes());
    }

    public function test_a_store_without_free_shipping_never_writes_the_word(): void
    {
        $this->actingAs($this->user)->put('/store/facts', ['delivery' => 'شحن لكل المدن', 'free_shipping' => '']);

        // «لا يوجد شحن مجاني» نصاً كانت ستجعل «مجاني» كلمة واردة، فيُسمح بها
        $this->assertStringNotContainsString('مجاني لكل', $this->builder()->prompt());
        $this->assertTrue($this->check('والشحن مجاني لكل الطلبات!')->has('unsupported_claim'));
    }

    // ================================================================
    //  حقائق المنتج: تُقرأ حيّة لأنها تتقادم
    // ================================================================

    public function test_a_running_sale_is_stated_and_disappears_when_it_ends(): void
    {
        $this->product->update(['compare_at_price' => 100, 'sale_ends_at' => '2026-09-25']);

        $this->assertStringContainsString('السعر قبل الخصم: 100 ريال، والسعر الحالي 75 ريال حتى 25 سبتمبر 2026', $this->builder()->prompt());
        $this->assertTrue($this->check('بدل 100 صار بـ 75 ريالاً.')->passes());

        $this->travelTo(now()->setDate(2026, 9, 26));

        $this->assertStringNotContainsString('السعر قبل الخصم', $this->builder()->prompt());
        $this->assertTrue($this->check('بدل 100 صار بـ 75 ريالاً.')->has('invented_number'));
    }

    public function test_installments_and_rating_are_facts_with_their_numbers(): void
    {
        $this->product->update([
            'installments' => [['provider' => 'tabby', 'count' => 4]],
            'rating_value' => 4.8,
            'rating_count' => 120,
            'stock_status' => 'in_stock',
        ]);

        $prompt = $this->builder()->prompt();
        $this->assertStringContainsString('التقسيط: تابي على 4 دفعات، كل دفعة 18.75 ريال', $prompt);
        $this->assertStringContainsString('تقييم المنتج في المتجر: 4.8 من 5 (120 تقييماً)', $prompt);
        $this->assertStringContainsString('التوفر: متوفر', $prompt);

        $report = $this->check('قسّطها مع تابي على 4 دفعات، 18.75 ريال للدفعة. تقييمها 4.8/5 من 120 تقييماً.');
        $this->assertTrue($report->passes(), json_encode($report->issues(), JSON_UNESCAPED_UNICODE));

        $this->assertTrue($this->check('تقييمها 4.9/5.')->has('invented_rating'));
    }

    public function test_the_product_form_saves_sales_facts(): void
    {
        $this->actingAs($this->user)->put("/products/{$this->product->id}", [
            'type' => 'good', 'title' => 'حبوب يرقاشيفي إثيوبية', 'features' => 'تحميص فاتح',
            'price' => 75, 'currency' => 'SAR', 'brand_name' => 'بن الديرة',
            'compare_at_price' => 90, 'sale_ends_at' => '2026-09-30', 'stock_status' => 'in_stock',
            'rating_value' => 4.7, 'rating_count' => 33,
            'installment_providers' => ['tabby', 'tamara'], 'installment_count' => 4,
        ])->assertSessionHasNoErrors();

        $product = $this->product->fresh();
        $this->assertSame(
            [['provider' => 'tabby', 'count' => 4], ['provider' => 'tamara', 'count' => 4]],
            $product->installments,
        );
        $this->assertSame('90.00', $product->compare_at_price);
        $this->assertStringContainsString('العلامة التجارية: بن الديرة', $product->spec_sheet);
    }

    public function test_a_compare_price_below_the_price_is_refused(): void
    {
        $this->actingAs($this->user)->put("/products/{$this->product->id}", [
            'type' => 'good', 'title' => 'حبوب', 'features' => 'تحميص', 'price' => 75, 'compare_at_price' => 60,
        ])->assertSessionHasErrors(['compare_at_price' => 'السعر قبل الخصم يجب أن يكون أعلى من السعر الحالي.']);
    }

    // ================================================================
    //  تجارب العملاء: تُقتبس بنصها، وبإذن
    // ================================================================

    public function test_a_testimonial_needs_consent(): void
    {
        $this->actingAs($this->user)->post('/store/testimonials', [
            'author_name' => 'سارة العتيبي', 'display_as' => 'first_name', 'body' => 'الطحن مضبوط على أداتي والطعم ممتاز',
        ])->assertSessionHasErrors('consent');

        $this->assertSame(0, Testimonial::count());
    }

    public function test_a_real_testimonial_unlocks_social_proof_and_must_be_quoted_verbatim(): void
    {
        $this->actingAs($this->user)->post('/store/testimonials', [
            'author_name' => 'سارة العتيبي', 'display_as' => 'first_name',
            'body' => 'الطحن مضبوط على أداتي والطعم ممتاز', 'rating' => 5, 'consent' => '1',
            'product_id' => $this->product->id,
        ])->assertSessionHasNoErrors();

        $prompt = $this->builder()->prompt();
        $this->assertStringContainsString('«الطحن مضبوط على أداتي والطعم ممتاز» — سارة (تقييمه 5 من 5)', $prompt);
        $this->assertStringNotContainsString('العتيبي', $prompt, 'الاسم الأول فقط كما اختار التاجر');

        $this->assertTrue($this->check('«الطحن مضبوط على أداتي والطعم ممتاز» — سارة')->passes());

        // إعادة صياغة كلام العميل تقويل له
        $this->assertTrue($this->check('«الطحن دقيق جداً وطعمه أفضل قهوة جربتها» — سارة')->has('invented_testimonial'));
    }

    // ================================================================
    //  الخطة: العرض ينتهي قبل موعد النشر
    // ================================================================

    public function test_the_plan_warns_when_an_offer_ends_before_the_publish_date(): void
    {
        config(['ai.proofread.enabled' => false]);
        $offer = $this->offer();

        ScriptedAiManager::install()->replyWith(['caption' => 'خصم 15% بكود KSA96 على يرقاشيفي.', 'hashtags' => ['قهوة']]);

        $this->actingAs($this->user)->post('/content/generator', [
            'items' => [[
                'goal' => 'direct_sales', 'platform' => 'instagram', 'format' => 'image',
                'product_id' => $this->product->id, 'language' => 'ar', 'dialect' => 'saudi',
            ]],
        ])->assertSessionHasNoErrors();

        $item = ContentItem::sole();
        $this->assertSame([$offer->id], $item->body['offer_ids']);
        $this->assertTrue($item->quality['passes']);

        // أُضيف للخطة بتاريخ بعد انتهاء العرض
        $item->update(['planned_for' => '2026-10-05', 'in_plan' => true]);

        $this->assertCount(1, $item->fresh()->offersEndingBeforePublish());
        $this->actingAs($this->user)->get('/content/plan?date=2026-10-05')->assertSee('العرض ينتهي قبل موعد النشر');
        $this->actingAs($this->user)->get("/content/{$item->id}")->assertSee('ينتهي في 30 سبتمبر');

        $item->update(['planned_for' => '2026-09-28']);
        $this->assertCount(0, $item->fresh()->offersEndingBeforePublish());
    }

    // ================================================================
    //  الاستيراد: ما أغفله المنافس (P3)
    // ================================================================

    public function test_the_product_page_yields_stock_rating_sale_and_installments_without_the_logo(): void
    {
        Http::fake(['*/p454952899' => Http::response(
            '<html><head><meta property="og:type" content="product">'
            .'<link rel="icon" href="/favicon.png">'
            .'<script type="application/ld+json">'.json_encode([
                '@graph' => [
                    ['@type' => 'Organization', 'name' => 'امدادات القهوة', 'logo' => ['@type' => 'ImageObject', 'url' => 'https://cdn.example.com/store/brand.png']],
                    [
                        '@type' => 'Product', 'name' => 'اسينزا سموذي فاكهة باشون فروت 1.3 كجم',
                        'image' => ['https://cdn.example.com/p/1.jpg', 'https://cdn.example.com/store/brand.png', 'https://cdn.example.com/p/2.jpg'],
                        'brand' => ['@type' => 'Brand', 'name' => 'اسينزا هيلاس'],
                        'aggregateRating' => ['@type' => 'AggregateRating', 'ratingValue' => '4.6', 'reviewCount' => '18'],
                        'offers' => [
                            '@type' => 'Offer', 'price' => '75', 'priceCurrency' => 'SAR',
                            'availability' => 'https://schema.org/OutOfStock',
                            'priceValidUntil' => '2026-09-30',
                            'priceSpecification' => [['@type' => 'UnitPriceSpecification', 'priceType' => 'https://schema.org/ListPrice', 'price' => '95']],
                        ],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE).'</script></head>'
            .'<body><div class="tabby-promo-snippet"></div><script src="https://cdn.tamara.co/widget.js"></script></body></html>'
        )]);

        $discovered = app(StoreCrawler::class)->read('https://coffee.example.com/ar/essenza/p454952899');

        $this->assertSame(['https://cdn.example.com/p/1.jpg', 'https://cdn.example.com/p/2.jpg'], $discovered->imageUrls, 'شعار المتجر ليس صورة منتج');
        $this->assertSame('out_of_stock', $discovered->stockStatus);
        $this->assertSame(95.0, $discovered->compareAtPrice);
        $this->assertSame('2026-09-30', $discovered->saleEndsAt);
        $this->assertSame([4.6, 18], [$discovered->ratingValue, $discovered->ratingCount]);
        $this->assertSame(['tabby', 'tamara'], array_column($discovered->installments, 'provider'));

        $product = app(ProductImporter::class)->import($this->brand, $discovered, 'خريطة الموقع');

        $this->assertSame('اسينزا هيلاس', $product->brand_name);
        $this->assertSame('out_of_stock', $product->stock_status);
        $this->assertStringContainsString('التقسيط: تابي على 4 دفعات، كل دفعة 18.75 ريال', implode("\n", $product->salesContext()));
    }

    public function test_the_store_facts_page_renders(): void
    {
        $this->offer();
        Testimonial::create([
            'brand_id' => $this->brand->id, 'author_name' => 'سارة', 'body' => 'تجربة رائعة مع الطحن',
            'display_as' => 'first_name', 'consented_at' => now(),
        ]);

        $this->actingAs($this->user)->get('/store/facts')
            ->assertOk()
            ->assertSee('خصم اليوم الوطني')
            ->assertSee('سارٍ اليوم')
            ->assertSee('تجربة رائعة مع الطحن');
    }
}
