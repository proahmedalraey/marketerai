<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\GenerationJob;
use App\Models\Product;
use App\Models\User;
use App\Services\AI\ProviderException;
use App\Services\Content\ContentGenerationService;
use App\Services\Content\Proofreader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ScriptedAiManager;
use Tests\TestCase;

/**
 * التدقيق اللغوي بعد بوابة الصدق.
 *
 * أخطاء اللغة هنا من تدقيق المنافس: «أصلح» بدل «أصبح» (C2)، و«نلتشف» بدل
 * «نكشف» (C7). والمدقق نموذج لا يُؤتمن: إعادة الصياغة وتغيير الرقم
 * وإضافة ادعاء كلها تُرفض ويبقى الأصل.
 */
class ContentProofreadTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Brand $brand;

    protected Product $product;

    protected ScriptedAiManager $ai;

    protected const TYPO = 'هل تشعر أن صباحك أصلح مكرراً؟ يرقاشيفي بتحميص فاتح، 250 جرام بـ 75 ريالاً، ونطحنها حسب طريقة تحضيرك.';

    protected const FIXED = 'هل تشعر أن صباحك أصبح مكرراً؟ يرقاشيفي بتحميص فاتح، 250 جرام بـ 75 ريالاً، ونطحنها حسب طريقة تحضيرك.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->ai = ScriptedAiManager::install();
        config(['ai.proofread.enabled' => true]);

        $this->user = User::create(['name' => 'محمد', 'email' => 'proof@example.com', 'password' => 'secret123']);

        $this->brand = Brand::create([
            'user_id' => $this->user->id,
            'name' => 'بن الديرة',
            'description' => 'حبوب قهوة مختصة محمصة وأدوات تحضير منزلية',
            'selling_points' => ['طحن حسب طريقة التحضير عند الطلب'],
            'audience' => 'محبو القهوة المختصة في المنزل',
            'dialect' => 'msa',
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

    /** المبيعات على إنستغرام: «صورة» تُكتب «مشكلة وحل»، و«Carousel» كاروسيلاً تسويقياً. */
    protected function generate(string $format = 'image'): void
    {
        $this->actingAs($this->user)->post('/content/generator', [
            'items' => [[
                'goal' => 'direct_sales',
                'platform' => 'instagram',
                'format' => $format,
                'product_id' => $this->product->id,
                'language' => 'ar',
                'dialect' => $this->brand->dialect,
            ]],
        ])->assertSessionHasNoErrors();
    }

    protected function draft(string $caption): array
    {
        return ['caption' => $caption, 'hashtags' => ['قهوة_مختصة']];
    }

    // ================================================================

    public function test_a_spelling_mistake_is_fixed_and_recorded(): void
    {
        $this->ai->replyWith($this->draft(self::TYPO), ['caption' => self::FIXED]);

        $this->generate();

        $proof = $this->ai->lastRequest();
        $this->assertSame(Proofreader::OPERATION, $proof->operation);
        $this->assertSame(0.1, $proof->temperature);
        $this->assertStringContainsString('أصلح مكرراً', $proof->prompt);
        $this->assertStringContainsString('فصحى مبسطة', $proof->system);

        $item = ContentItem::sole();
        $this->assertSame(self::FIXED, $item->caption);
        $this->assertSame('applied', $item->quality['proofread']['status']);
        $this->assertSame([['field' => 'caption', 'before' => 'أصلح', 'after' => 'أصبح']], $item->quality['proofread']['changes']);
    }

    public function test_proofreading_is_free(): void
    {
        $this->ai->replyWith($this->draft(self::TYPO), ['caption' => self::FIXED]);

        $this->generate();

        $this->assertSame(100 - (int) config('credits.costs')['content.post'], $this->brand->refresh()->credit_balance);
    }

    public function test_the_merchant_sees_what_was_corrected(): void
    {
        $this->ai->replyWith($this->draft(self::TYPO), ['caption' => self::FIXED]);

        $this->generate();

        $this->actingAs($this->user)->get('/content/'.ContentItem::sole()->id)
            ->assertOk()
            ->assertSee('دققنا النص لغوياً وصححنا خطأً واحداً')
            ->assertSee('<del class="text-danger-fg">أصلح</del>', false);
    }

    public function test_it_runs_after_the_honesty_correction_on_the_text_that_will_be_saved(): void
    {
        $this->ai->replyWith(
            $this->draft('تكفيك العبوة 15 كوباً. '.self::TYPO),
            $this->draft(self::TYPO),
            ['caption' => self::FIXED],
        );

        $this->generate();

        $this->assertSame(
            ['content.post', ContentGenerationService::CORRECTION_OPERATION, Proofreader::OPERATION],
            array_map(fn ($r) => $r->operation, $this->ai->requests),
        );
        $this->assertSame(self::FIXED, ContentItem::sole()->caption);
    }

    public function test_each_slide_is_proofread_on_its_own_and_roles_are_kept(): void
    {
        $slides = [
            ['role' => 'hook', 'text' => 'هل مللت من القهوة العادية؟'],
            ['role' => 'promise', 'text' => 'في هذه الشرائح تعرف الفرق.'],
            ['role' => 'pull', 'text' => 'نلتشف لك سر التحميص الفاتح.'],
            ['role' => 'pull', 'text' => 'نكهات الياسمين والليمون والتوت.'],
            ['role' => 'harvest', 'text' => 'نطحنها حسب طريقة تحضيرك.'],
            ['role' => 'ask', 'text' => 'اطلب عبوتك الآن.'],
        ];

        $this->ai->replyWith(
            ['caption' => 'يرقاشيفي 250 جرام بـ 75 ريالاً.', 'slides' => $slides, 'hashtags' => ['قهوة']],
            ['caption' => 'يرقاشيفي 250 جرام بـ 75 ريالاً.', 'slide_3' => 'نكشف لك سر التحميص الفاتح.'],
        );

        $this->generate('carousel');

        $item = ContentItem::sole();
        $this->assertSame('نكشف لك سر التحميص الفاتح.', $item->slides()[2]['text']);
        $this->assertSame(array_column($slides, 'role'), array_column($item->slides(), 'role'));
        $this->assertSame('slide:3', $item->quality['proofread']['changes'][0]['field']);
        $this->assertStringContainsString('"slide_6"', $this->ai->lastRequest()->prompt);
    }

    // ================================================================
    //  ما يُرفض: المدقق ليس كاتباً
    // ================================================================

    public function test_a_rewrite_is_refused_and_the_original_kept(): void
    {
        $this->ai->replyWith(
            $this->draft(self::TYPO),
            ['caption' => 'صباح جديد مع يرقاشيفي الإثيوبية: تحميص فاتح ونكهة زهرية، 250 جرام بـ 75 ريالاً، مطحونة لأداتك.'],
        );

        $this->generate();

        $item = ContentItem::sole();
        $this->assertSame(self::TYPO, $item->caption);
        $this->assertSame('clean', $item->quality['proofread']['status']);
        $this->assertSame(['caption'], $item->quality['proofread']['skipped']);
    }

    public function test_a_changed_number_is_refused_even_as_a_one_word_edit(): void
    {
        $this->ai->replyWith($this->draft(self::TYPO), ['caption' => str_replace('75', '57', self::FIXED)]);

        $this->generate();

        $this->assertSame(self::TYPO, ContentItem::sole()->caption);
    }

    public function test_a_proofreader_that_adds_a_claim_is_rejected(): void
    {
        $this->ai->replyWith(
            $this->draft(self::TYPO),
            ['caption' => str_replace('ونطحنها حسب', 'ونطحنها مجاناً حسب', self::FIXED)],
        );

        $this->generate();

        $item = ContentItem::sole();
        $this->assertSame(self::TYPO, $item->caption);
        $this->assertSame('rejected', $item->quality['proofread']['status']);
        $this->assertTrue($item->quality['passes']);
    }

    public function test_a_failed_proofread_keeps_the_text_and_the_job(): void
    {
        $this->ai->replyWith($this->draft(self::TYPO), new ProviderException('تعطل مؤقت', 'scripted', 503));

        $this->generate();

        $this->assertSame('completed', GenerationJob::sole()->status->value);
        $this->assertSame(self::TYPO, ContentItem::sole()->caption);
        $this->assertSame('failed', ContentItem::sole()->quality['proofread']['status']);
    }

    public function test_proofreading_can_be_turned_off(): void
    {
        config(['ai.proofread.enabled' => false]);
        $this->ai->replyWith($this->draft(self::TYPO));

        $this->generate();

        $this->assertCount(1, $this->ai->requests);
        $this->assertArrayNotHasKey('proofread', ContentItem::sole()->quality);
    }
}
