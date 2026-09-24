<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\GenerationJob;
use App\Models\Product;
use App\Models\User;
use App\Services\AI\ProviderException;
use App\Services\Content\ContentGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ScriptedAiManager;
use Tests\TestCase;

/**
 * بوابة الصدق عبر المسار الكامل: من زر «إنشاء المحتوى» إلى ما يُحفظ
 * ويُعرض ويُخصم. النموذج «مكتوب مسبقاً» ليرد بما رصده تدقيق المنافس حرفياً.
 */
class ContentHonestyGateTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Brand $brand;

    protected Product $product;

    protected ScriptedAiManager $ai;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ai = ScriptedAiManager::install();

        // التدقيق اللغوي طلب إضافي بعد البوابة، وله اختباراته (ContentProofreadTest)
        config(['ai.proofread.enabled' => false]);

        $this->user = User::create(['name' => 'محمد', 'email' => 'gate@example.com', 'password' => 'secret123']);

        $this->brand = Brand::create([
            'user_id' => $this->user->id,
            'name' => 'بن الديرة',
            'description' => 'حبوب قهوة مختصة محمصة وأدوات تحضير منزلية',
            'selling_points' => ['طحن حسب طريقة التحضير عند الطلب'],
            'audience' => 'محبو القهوة المختصة في المنزل',
            'dialect' => 'msa',
            'banned_words' => ['تجربة'],
            'credit_balance' => 100,
            'credits_allowance' => 100,
            'onboarding_completed' => true,
        ]);

        $this->user->update(['current_brand_id' => $this->brand->id]);

        $this->product = Product::create([
            'brand_id' => $this->brand->id,
            'type' => 'good',
            'title' => 'حبوب يرقاشيفي إثيوبية',
            'features' => "درجة تحميص فاتحة\nنكهات الياسمين والليمون والتوت",
            'specifications' => 'الوزن: 250 جرام',
            'price' => 75,
            'currency' => 'SAR',
            'is_primary' => true,
            'is_active' => true,
        ]);
    }

    /** إعداد واحد من صفحة «كتابة المحتوى»: منشور صورة على إنستغرام بالفصحى. */
    protected function item(array $input = []): array
    {
        return [
            'goal' => 'direct_sales',
            'platform' => 'instagram',
            'format' => 'image',
            'product_id' => $this->product->id,
            'language' => 'ar',
            'dialect' => 'msa',
            ...$input,
        ];
    }

    protected function generate(array $input = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->post('/content/generator', [
            'mode' => 'single',
            'items' => [$this->item($input)],
        ]);
    }

    /** الإعداد نفسه مكرراً في دفعة واحدة. */
    protected function generateBatch(int $count, array $input = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->post('/content/generator', [
            'mode' => 'batch',
            'items' => array_fill(0, $count, $this->item($input)),
        ]);
    }

    protected function reply(string $caption, array $hashtags = ['قهوة_مختصة']): array
    {
        return ['caption' => $caption, 'hashtags' => $hashtags];
    }

    /** مخرج C4·V2 في التدقيق: سعر سوق وإنتاجية مخترعان. */
    protected const FABRICATED = 'لماذا تدفع 20 ريالاً يومياً؟ يرقاشيفي 250 جرام بـ 75 ريالاً تكفيك 15 كوباً.';

    protected const CLEAN = 'يرقاشيفي بتحميص فاتح ونكهات الياسمين والليمون. 250 جرام بـ 75 ريالاً، ونطحنها حسب طريقة تحضيرك.';

    // ================================================================

    public function test_fabricated_numbers_are_sent_back_once_and_the_correction_is_saved(): void
    {
        $this->ai->replyWith($this->reply(self::FABRICATED), $this->reply(self::CLEAN));

        $this->generate()->assertSessionHasNoErrors();

        $this->assertCount(2, $this->ai->requests);

        $correction = $this->ai->lastRequest();
        $this->assertSame(ContentGenerationService::CORRECTION_OPERATION, $correction->operation);
        $this->assertSame(0.3, $correction->temperature);
        $this->assertStringContainsString('هذه مسودتك السابقة', $correction->prompt);
        $this->assertStringContainsString(self::FABRICATED, $correction->prompt, 'التصحيح يحرّر المسودة لا يبدأ من الصفر');
        $this->assertStringContainsString('«20»', $correction->prompt);
        $this->assertStringContainsString('«15»', $correction->prompt);

        $item = ContentItem::sole();
        $this->assertSame(self::CLEAN, $item->caption);
        $this->assertTrue($item->quality['passes']);
        $this->assertSame(2, $item->quality['attempts']);
        $this->assertFalse($item->needsReview());
    }

    public function test_the_correction_is_free_the_merchant_pays_for_one_post(): void
    {
        $this->ai->replyWith($this->reply(self::FABRICATED), $this->reply(self::CLEAN));

        $this->generate();

        $this->assertEquals(100 - (int) config('credits.costs')['content.post'], $this->brand->refresh()->credit_balance);
    }

    public function test_a_clean_draft_is_saved_without_a_correction_request(): void
    {
        $this->ai->replyWith($this->reply(self::CLEAN));

        $this->generate();

        $this->assertCount(1, $this->ai->requests);
        $this->assertSame(1, ContentItem::sole()->quality['attempts']);
    }

    public function test_what_survives_the_correction_is_shown_to_the_merchant(): void
    {
        $stillBad = 'اطلب الآن واستخدم كود KSA96 للحصول على خصم، وتصلك طلبيتك بسرعة.';

        $this->ai->replyWith($this->reply(self::FABRICATED), $this->reply($stillBad));

        $this->generate();

        $item = ContentItem::sole();
        $this->assertTrue($item->needsReview());

        // التصحيح أسوأ (ثلاثة أخطاء مقابل اثنين): تبقى المسودة الأولى
        $this->assertSame(self::FABRICATED, $item->caption);

        $this->actingAs($this->user)->get("/content/{$item->id}")
            ->assertOk()
            ->assertSee('راجع قبل النشر')
            ->assertSee('«20»', false)
            ->assertSee('دون أن نخصم نقاطاً');

        // مسودة لم تُضف للخطة بعد: التنبيه في صفحة الكتابة حيث هي
        $this->actingAs($this->user)->get('/content/generator')->assertSee('راجع قبل النشر');
    }

    public function test_a_failed_correction_request_still_delivers_the_first_draft(): void
    {
        $this->ai->replyWith($this->reply(self::FABRICATED), new ProviderException('تعطل مؤقت', 'scripted', 503));

        $this->generate();

        $job = GenerationJob::sole();
        $this->assertSame('completed', $job->status->value);

        $item = ContentItem::sole();
        $this->assertSame(self::FABRICATED, $item->caption);
        $this->assertTrue($item->needsReview());
    }

    public function test_a_banned_word_in_any_inflection_triggers_a_correction(): void
    {
        $this->ai->replyWith(
            $this->reply('اكتشف تجربتك الجديدة مع يرقاشيفي.'),
            $this->reply(self::CLEAN),
        );

        $this->generate();

        $this->assertStringContainsString('تجربتك', $this->ai->lastRequest()->prompt);
        $this->assertSame(self::CLEAN, ContentItem::sole()->caption);
    }

    // ================================================================
    //  البرومبت: الحدود بصيغة موجبة، ومحسوبة من بيانات المتجر
    // ================================================================

    public function test_the_prompt_names_what_this_store_did_not_offer(): void
    {
        $this->ai->replyWith($this->reply(self::CLEAN));

        $this->generate();

        $prompt = $this->ai->requests[0]->prompt;
        $this->assertStringContainsString('## حدود الحقائق', $prompt);
        $this->assertStringContainsString('التوصيل أو الشحن', $prompt);
        $this->assertStringContainsString('خصم أو كود خصم', $prompt);

        // الفصحى المختارة تُطبَّق بمفرداتها، والكلمة الممنوعة بكل تصريفاتها
        $system = $this->ai->requests[0]->system;
        $this->assertStringContainsString('فصحى مبسطة', $system);
        $this->assertStringContainsString('بأي صيغة أو تصريف: تجربة', $system);
        $this->assertStringNotContainsString('بلهجة سعودية', $system);
    }

    public function test_a_store_that_delivers_is_not_told_to_hide_delivery(): void
    {
        $this->brand->update(['selling_points' => ['طحن حسب طريقة التحضير عند الطلب', 'توصيل مجاني داخل الرياض']]);
        $this->ai->replyWith($this->reply('يرقاشيفي 250 جرام بـ 75 ريالاً، والتوصيل مجاني داخل الرياض.'));

        $this->generate();

        $this->assertStringNotContainsString('التوصيل أو الشحن', $this->ai->requests[0]->prompt);
        $this->assertTrue(ContentItem::sole()->quality['passes']);
    }

    // ================================================================
    //  الدفعة: الإعدادات المتطابقة تأخذ زاوية لكل نسخة بدل «اكتب نسخة مختلفة»
    // ================================================================

    public function test_each_version_in_a_batch_gets_its_own_angle(): void
    {
        $this->ai->replyWith($this->reply(self::CLEAN), $this->reply(self::CLEAN), $this->reply(self::CLEAN));

        $this->generateBatch(3)->assertSessionHasNoErrors();

        $angles = ContentItem::orderBy('id')->get()->map(fn ($item) => $item->body['angle'] ?? null)->all();

        $this->assertSame(['feature', 'usage', 'sensory'], $angles);
        $this->assertStringContainsString('## زاوية هذه النسخة', $this->ai->requests[1]->prompt);
        $this->assertStringNotContainsString('اكتب نسخة مختلفة تماماً', $this->ai->requests[1]->prompt);
    }

    public function test_the_price_angle_needs_a_price(): void
    {
        $this->product->update(['price' => null]);
        $this->ai->replyWith(...array_fill(0, 5, $this->reply('يرقاشيفي بتحميص فاتح.')));

        $this->generateBatch(5)->assertSessionHasNoErrors();

        $angles = ContentItem::pluck('body')->map(fn ($body) => $body['angle'] ?? null);

        $this->assertNotContains('value', $angles->all());
        $this->assertCount(5, $angles->unique());
    }

    public function test_a_single_version_has_no_angle(): void
    {
        $this->ai->replyWith($this->reply(self::CLEAN));

        $this->generate();

        $this->assertStringNotContainsString('## زاوية هذه النسخة', $this->ai->requests[0]->prompt);
        $this->assertArrayNotHasKey('angle', ContentItem::sole()->body);
    }

    // ================================================================
    //  القوالب التي تحتاج بيانات لا نملكها
    // ================================================================

    /** «تعزيز السمعة» يفضّل الدليل الاجتماعي؛ بلا تجربة حقيقية يُكتب منشوراً عادياً لا شهادة مخترعة. */
    public function test_the_trust_goal_without_testimonials_falls_back_to_a_plain_post(): void
    {
        $this->ai->replyWith($this->reply(self::CLEAN));

        $this->generate(['goal' => 'trust'])->assertSessionHasNoErrors();

        $this->assertSame('focused_post', GenerationJob::sole()->payload['template']);
        $this->assertStringNotContainsString('اقتبس تجربة العميل', $this->ai->requests[0]->prompt);
    }

    // ================================================================
    //  البنية وإعادة الفحص
    // ================================================================

    public function test_hashtags_are_capped_at_the_platform_limit(): void
    {
        $this->ai->replyWith($this->reply('يرقاشيفي بتحميص فاتح.', ['قهوة', 'بن', 'مختصة', 'يرقاشيفي', 'اثيوبيا', 'باريستا', 'صباح']));

        $this->generate(['platform' => 'x', 'format' => 'tweet']);

        $this->assertCount((int) config('content.platforms.x.hashtags.max'), ContentItem::sole()->hashtags());
    }

    public function test_editing_the_text_rechecks_it(): void
    {
        $this->ai->replyWith($this->reply(self::FABRICATED), $this->reply(self::FABRICATED));
        $this->generate();

        $item = ContentItem::sole();
        $this->assertTrue($item->needsReview());

        $this->actingAs($this->user)
            ->put("/content/{$item->id}", ['caption' => self::CLEAN])
            ->assertSessionHas('status', 'حُفظت التعديلات.');

        $item->refresh();
        $this->assertFalse($item->needsReview());
        $this->assertTrue($item->quality['edited']);
        $this->assertSame(2, $item->quality['attempts'], 'عدد المحاولات يصف التوليد لا التعديل');

        $this->actingAs($this->user)
            ->put("/content/{$item->id}", ['caption' => 'اشترِ الآن بـ 60 ريالاً فقط.'])
            ->assertSessionHas('status', 'حُفظت التعديلات، وما زالت فيها نقاط تحتاج مراجعة.');
    }
}
