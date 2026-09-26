<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Models\Brand;
use App\Models\GenerationJob;
use App\Models\Product;
use App\Models\User;
use App\Services\AI\AiManager;
use App\Services\AI\DTO\TextRequest;
use App\Services\AI\DTO\TextResponse;
use App\Services\AI\ProviderException;
use App\Services\Settings\AiSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * زر «تحسين الوصف» في استوديو الصور: مهمة طابور (لا نموذج داخل طلب HTTP)، والنتيجة تمر بحارس
 * يمنع الاختلاق (أرقام/ادعاءات/نص داخل الصورة) قبل أن تصل حقل الوصف.
 */
class PromptEnhanceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'سارة', 'email' => 'enhance@example.com', 'password' => 'secret123']);

        $this->brand = Brand::create([
            'user_id' => $this->user->id,
            'name' => 'محمصة الوادي',
            'credit_balance' => 100,
            'credits_allowance' => 100,
            'onboarding_completed' => true,
        ]);

        $this->user->update(['current_brand_id' => $this->brand->id]);
    }

    /** نموذج نص يرد بما نحدده بالترتيب (نص أو استثناء)، ويحتفظ بما وصله. */
    protected function fakeText(array $replies): object
    {
        $fake = new class(app(AiSettings::class), $replies) extends AiManager
        {
            /** @var list<TextRequest> */
            public array $seen = [];

            public function __construct(AiSettings $settings, public array $replies)
            {
                parent::__construct($settings);
            }

            public function generateText(TextRequest $request, ?GenerationJob $job = null, ?string $provider = null): TextResponse
            {
                $this->seen[] = $request;
                $reply = array_shift($this->replies);

                if ($reply instanceof \Throwable) {
                    throw $reply;
                }

                return new TextResponse(raw: json_encode(['enhanced' => $reply]), data: ['enhanced' => $reply], provider: 'fake', model: 'fake');
            }
        };

        $this->app->instance(AiManager::class, $fake);

        return $fake;
    }

    protected function enhance(array $overrides = [])
    {
        return $this->actingAs($this->user)->postJson(route('studio.enhance'), $overrides + [
            'prompt' => 'كوب قهوة على طاولة',
            'aspect_ratio' => '1:1',
            'use_brand_identity' => true,
        ]);
    }

    protected function job(): GenerationJob
    {
        return GenerationJob::withoutBrandScope()->where('type', 'prompt_enhance')->latest('id')->firstOrFail();
    }

    public function test_it_enhances_through_a_queued_job_the_ui_can_poll(): void
    {
        $fake = $this->fakeText(['كوب قهوة عربية على طاولة رخامية بيضاء، بإضاءة صباحية ناعمة من نافذة جانبية، وخلفية هادئة غير واضحة المعالم، بزاوية مائلة قليلاً.']);

        $response = $this->enhance()->assertStatus(202);

        $uuid = $response->json('uuid');
        $this->assertNotEmpty($uuid);

        // الطابور sync في الاختبار: انتهت المهمة، والواجهة تستطلع نفس الرابط المعتاد
        $status = $this->actingAs($this->user)->getJson($response->json('status_url'))->assertOk();
        $status->assertJson(['status' => 'completed', 'type' => 'prompt_enhance']);
        $this->assertStringContainsString('إضاءة صباحية ناعمة', $status->json('result.prompt'));
        $this->assertSame('كوب قهوة على طاولة', $status->json('result.original'));

        $request = $fake->seen[0];
        $this->assertSame('prompt.enhance', $request->operation);
        $this->assertStringContainsString('«كوب قهوة على طاولة»', $request->prompt);
        $this->assertStringContainsString('المتجر: محمصة الوادي', $request->prompt);
        $this->assertStringContainsString('نسبة الصورة 1:1', $request->prompt);
    }

    public function test_it_is_free_by_default_and_leaves_no_credit_trace(): void
    {
        $this->fakeText(['كوب قهوة على طاولة رخامية بإضاءة ناعمة وخلفية هادئة.']);

        $this->enhance()->assertStatus(202);

        $this->assertEquals(100, $this->brand->refresh()->credit_balance);
        $this->assertEquals(0, (float) $this->job()->credits_held);
    }

    public function test_a_priced_enhancement_holds_then_settles_and_refunds_on_failure(): void
    {
        config(['credits.costs' => array_merge(config('credits.costs'), ['prompt.enhance' => 0.5])]);

        $this->fakeText(['كوب قهوة على طاولة رخامية بإضاءة ناعمة وخلفية هادئة.']);
        $this->enhance()->assertStatus(202);

        $this->assertEquals(99.5, $this->brand->refresh()->credit_balance);
        $this->assertEquals(0.5, (float) $this->job()->credits_charged);

        // فشل المزود: تعود النقاط
        $this->fakeText([new ProviderException('down', 'fake', 500)]);
        $this->enhance()->assertStatus(202);

        $this->assertEquals(99.5, $this->brand->refresh()->credit_balance);
        $this->assertSame(JobStatus::Failed, $this->job()->status);
    }

    public function test_an_invented_claim_or_number_is_sent_back_once_then_accepted_when_fixed(): void
    {
        $fake = $this->fakeText([
            'كوب قهوة مع خصم 50 على طاولة رخامية بإضاءة ناعمة.',
            'كوب قهوة على طاولة رخامية بإضاءة ناعمة وخلفية هادئة.',
        ]);

        $this->enhance()->assertStatus(202);

        $this->assertCount(2, $fake->seen);
        $this->assertStringContainsString('تصحيح مطلوب', $fake->seen[1]->prompt);
        $this->assertStringContainsString('خصم', $fake->seen[1]->prompt);
        $this->assertSame(JobStatus::Completed, $this->job()->status);
        $this->assertStringNotContainsString('50', $this->job()->result['prompt']);
    }

    public function test_when_the_model_keeps_inventing_the_job_fails_instead_of_delivering_it(): void
    {
        config(['credits.costs' => array_merge(config('credits.costs'), ['prompt.enhance' => 0.5])]);

        $this->fakeText([
            'كوب قهوة بسعر 30 ريال على طاولة.',
            'كوب قهوة مجاني على طاولة رخامية.',
        ]);

        $this->enhance()->assertStatus(202);

        $job = $this->job();
        $this->assertSame(JobStatus::Failed, $job->status);
        $this->assertStringContainsString('عدّله يدوياً', $job->error);
        $this->assertEquals(100, $this->brand->refresh()->credit_balance, 'أُرجع الحجز');
    }

    public function test_numbers_and_claims_the_merchant_wrote_are_allowed(): void
    {
        $this->fakeText(['ثلاثة أكواب قهوة (3) بعرض خصم على طاولة رخامية بإضاءة ناعمة.']);

        $this->enhance(['prompt' => 'كوب قهوة 3 مع خصم'])->assertStatus(202);

        $this->assertSame(JobStatus::Completed, $this->job()->status);
    }

    public function test_a_request_to_write_text_inside_the_image_is_rejected(): void
    {
        $fake = $this->fakeText([
            'كوب قهوة على طاولة مكتوب عليها اسم المحمصة بخط عريض.',
            'كوب قهوة على طاولة رخامية بإضاءة ناعمة وخلفية هادئة.',
        ]);

        $this->enhance()->assertStatus(202);

        $this->assertCount(2, $fake->seen);
        $this->assertStringContainsString('داخل الصورة', $fake->seen[1]->prompt);
        $this->assertSame(JobStatus::Completed, $this->job()->status);
    }

    public function test_the_reply_is_cleaned_of_quotes_and_labels(): void
    {
        $this->fakeText(["الوصف المحسّن: «كوب قهوة على **طاولة** رخامية\nبإضاءة ناعمة»"]);

        $this->enhance()->assertStatus(202);

        $this->assertSame('كوب قهوة على طاولة رخامية بإضاءة ناعمة', $this->job()->result['prompt']);
    }

    public function test_a_selected_product_is_named_but_its_look_is_left_to_the_reference(): void
    {
        $product = Product::create(['brand_id' => $this->brand->id, 'title' => 'سيروب المستكة', 'is_active' => true]);
        $fake = $this->fakeText(['علبة على رف خشبي بإضاءة دافئة وخلفية هادئة متناسقة.']);

        $this->enhance(['product_id' => $product->id, 'prompt' => 'علبة على رف'])->assertStatus(202);

        $this->assertStringContainsString('المنتج: سيروب المستكة', $fake->seen[0]->prompt);
        $this->assertStringContainsString('فلا تصف عبوته', $fake->seen[0]->prompt);
    }

    public function test_validation_and_isolation(): void
    {
        $this->fakeText(['x']);

        $this->enhance(['prompt' => ''])->assertStatus(422)->assertJsonValidationErrors('prompt');
        $this->enhance(['prompt' => 'ab'])->assertStatus(422);
        $this->enhance(['aspect_ratio' => '99:1'])->assertStatus(422);

        // مهمة تحسين لمتجر آخر لا تُقرأ
        $this->fakeText(['كوب قهوة على طاولة رخامية بإضاءة ناعمة وخلفية هادئة.']);
        $uuid = $this->enhance()->json('uuid');

        $other = User::create(['name' => 'ليلى', 'email' => 'other@example.com', 'password' => 'secret123']);
        $otherBrand = Brand::create(['user_id' => $other->id, 'name' => 'متجر آخر', 'credit_balance' => 10, 'credits_allowance' => 10, 'onboarding_completed' => true]);
        $other->update(['current_brand_id' => $otherBrand->id]);

        $this->actingAs($other)->getJson(route('api.jobs.show', $uuid))->assertNotFound();
    }

    public function test_the_toolbar_button_is_live_not_a_coming_soon_stub(): void
    {
        $html = $this->actingAs($this->user)->get(route('studio.index'))->getContent();

        // assertTrue برسالة قصيرة: assertStringContains تطبع الصفحة كلها (500KB) عند الفشل
        $this->assertFalse(str_contains($html, 'قريباً — تحسين البرومبت بالذكاء'), 'ما زال زر التحسين معطّلاً بوسم قريباً');
        $this->assertTrue(str_contains($html, '@click="enhance()"'), 'لا زر يستدعي enhance()');
        // الرابط يصل الواجهة داخل JSON.parse('…') مهرَّباً بعدد متغيّر من الشرطات المائلة
        $this->assertSame(1, preg_match('#enhanceUrl.{1,30}?http.{1,12}?localhost.{1,12}?studio.{1,12}?enhance#', $html), 'رابط التحسين لا يصل إعدادات الواجهة');
    }
}
