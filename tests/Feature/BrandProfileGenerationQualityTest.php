<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Jobs\GenerateBrandProfileJob;
use App\Models\Brand;
use App\Models\BrandProfile;
use App\Models\GenerationJob;
use App\Models\User;
use App\Services\AI\Prompts\PromptBuilder;
use App\Services\Brand\BrandProfileGenerator;
use App\Services\Brand\Quality\ProfileQualityCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Support\ProfileFixtures;
use Tests\Support\ScriptedAiManager;
use Tests\TestCase;

/**
 * جودة التوليد عبر المسار الكامل: من حفظ الإجابات، إلى ما يُرسل للنموذج،
 * إلى ما يُحفظ ويُعرض ويُحقن في برومبت المحتوى.
 *
 * النموذج هنا «مكتوب مسبقاً»: نتحكم في رده لنختبر كيف يتعامل النظام
 * مع رد مثالي ورد رديء — ما لا يتيحه المزود الوهمي ولا الحقيقي.
 */
class BrandProfileGenerationQualityTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Brand $brand;

    protected ScriptedAiManager $ai;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ai = ScriptedAiManager::install();

        $this->user = User::create(['name' => 'محمد', 'email' => 'quality@example.com', 'password' => 'secret123']);

        $this->brand = Brand::create([
            'user_id' => $this->user->id,
            'name' => 'امدادات القهوة',
            'banned_words' => ['رخيص'],
            'credit_balance' => 100,
            'credits_allowance' => 100,
            'onboarding_completed' => true,
        ]);

        $this->user->update(['current_brand_id' => $this->brand->id]);
    }

    /** يحفظ الإجابات (فيولّد أول ملف مجاناً) بالرد المحدد. */
    protected function answerWith(array|string $reply, array $answers = []): void
    {
        $this->ai->replyWith($reply);

        $this->actingAs($this->user)
            ->put('/brand/profile/answers', ProfileFixtures::answers($answers))
            ->assertSessionHasNoErrors();
    }

    protected function active(): BrandProfile
    {
        return BrandProfile::forBrand($this->brand)->active()->firstOrFail();
    }

    protected function qualityOf(BrandProfile $profile): \App\Services\Brand\Quality\ProfileQualityReport
    {
        return (new ProfileQualityCheck)->check(
            ['simple' => $profile->simple, 'detailed' => $profile->detailed, 'technical' => $profile->technical],
            (array) $profile->answers,
            (array) $this->brand->refresh()->banned_words,
        );
    }

    // ================================================================
    //  ما يُرسل للنموذج
    // ================================================================

    public function test_the_request_carries_every_answer_and_every_rule(): void
    {
        $this->answerWith(ProfileFixtures::modelOutput());

        $request = $this->ai->lastRequest();
        $answers = ProfileFixtures::answers();

        foreach (['project_name', 'store_url', 'one_liner', 'audience'] as $key) {
            $this->assertStringContainsString($answers[$key], $request->prompt, "الإجابة {$key} لم تصل للنموذج");
        }

        foreach (explode("\n", $answers['advantages']) as $advantage) {
            $this->assertStringContainsString($advantage, $request->prompt);
        }

        // السؤال بنصه كما رآه المستخدم، والسؤال غير المُجاب معلَن لا محذوف
        $this->assertStringContainsString('في سطر واحد، اوصف لي ماذا تبيع؟', $request->prompt);
        $this->assertStringContainsString('ج: '.BrandProfile::UNANSWERED, $request->prompt);

        $this->assertStringContainsString('رخيص', $request->system, 'الكلمات الممنوعة لم تصل');
        $this->assertStringContainsString('لا تُضف أي معلومة غير واردة', $request->system);
        $this->assertStringContainsString('60 كلمة', $request->system);

        $this->assertSame(
            ['simple', 'detailed', 'activity_type', 'sales_summary', 'advantages_directives', 'important_notes'],
            array_keys($request->schema['properties']),
        );
        $this->assertSame(BrandProfileGenerator::OPERATION, $request->operation);
    }

    public function test_the_dialect_is_not_sent_because_descriptions_are_in_standard_arabic(): void
    {
        $this->brand->update(['dialect' => 'egyptian']);

        $this->answerWith(ProfileFixtures::modelOutput());

        $this->assertStringNotContainsString('مصرية', $this->ai->lastRequest()->system);
        $this->assertStringContainsString('فصحى', $this->ai->lastRequest()->system);
    }

    public function test_service_projects_are_asked_in_service_wording(): void
    {
        $this->answerWith(ProfileFixtures::modelOutput(), ['type' => 'service']);

        $prompt = $this->ai->lastRequest()->prompt;

        $this->assertStringContainsString('نوع المشروع: خدمة', $prompt);
        $this->assertStringContainsString('وش الخدمة اللي تقدمها', $prompt);
        $this->assertStringNotContainsString('ماذا تبيع', $prompt);
    }

    public function test_regeneration_sends_the_current_answers_not_the_old_snapshot(): void
    {
        $this->answerWith(ProfileFixtures::modelOutput());

        $this->actingAs($this->user)->put('/brand/profile/answers', ProfileFixtures::answers(['one_liner' => 'نبيع حبوب قهوة مختصة فقط']));

        $this->ai->replyWith(ProfileFixtures::modelOutput());
        $this->actingAs($this->user)->post('/brand/profile/regenerate');

        $this->assertStringContainsString('نبيع حبوب قهوة مختصة فقط', $this->ai->lastRequest()->prompt);
    }

    // ================================================================
    //  ما يُحفظ من الرد
    // ================================================================

    public function test_a_realistic_reply_is_saved_and_passes_the_quality_check(): void
    {
        $this->answerWith(ProfileFixtures::modelOutput());

        $report = $this->qualityOf($this->active());

        $this->assertSame([], $report->issues(), json_encode($report->issues(), JSON_UNESCAPED_UNICODE));
    }

    public function test_the_model_cannot_override_facts(): void
    {
        $this->answerWith(ProfileFixtures::modelOutput([
            'project_name' => 'اسم اخترعه النموذج',
            'store_url' => 'https://invented.example',
            'project_type' => 'خدمة',
        ]));

        $technical = $this->active()->technicalFor($this->brand->refresh());

        $this->assertSame('امدادات القهوة', $technical['project_name']);
        $this->assertSame('https://coffeesupplies.com.sa/ar', $technical['store_url']);
        $this->assertSame('سلع', $technical['project_type']);
    }

    public function test_notes_returned_as_a_numbered_string_become_a_clean_list(): void
    {
        $this->answerWith(ProfileFixtures::modelOutput([
            'important_notes' => "1. ركّز على الكافيهات والمطاعم.\n2) أبرز التوصيل المجاني.\n- اذكر الجملة والتجزئة.\n• "
                .BrandProfileGenerator::BASELINE_NOTE,
        ]));

        $this->assertSame([
            ...BrandProfileGenerator::FIXED_NOTES,
            'ركّز على الكافيهات والمطاعم.',
            'أبرز التوصيل المجاني.',
            'اذكر الجملة والتجزئة.',
        ], $this->active()->constraints(), 'الترقيم يُنزع والقيود الثابتة لا تتكرر');
    }

    public function test_json_wrapped_in_prose_and_a_code_fence_is_still_read(): void
    {
        $json = json_encode(ProfileFixtures::modelOutput(), JSON_UNESCAPED_UNICODE);

        $this->answerWith("بكل سرور، هذا الملف المطلوب:\n```json\n{$json}\n```\nأتمنى أن يفيدك.");

        $this->assertSame(ProfileFixtures::simple(), $this->active()->simple);
    }

    // ================================================================
    //  الفشل: لا يدفع المستخدم مقابل خطأ عندنا
    // ================================================================

    public function test_an_unreadable_reply_fails_the_job_and_refunds_a_paid_regeneration(): void
    {
        $this->answerWith(ProfileFixtures::modelOutput());

        $this->ai->replyWith('عذراً، لا أستطيع إكمال هذا الطلب.');
        $this->actingAs($this->user)->post('/brand/profile/regenerate');

        $job = GenerationJob::withoutBrandScope()->latest('id')->firstOrFail();

        $this->assertSame(JobStatus::Failed, $job->status);
        $this->assertSame(100, $this->brand->refresh()->credit_balance, 'النقطتان أُرجعتا');
        $this->assertSame(1, BrandProfile::forBrand($this->brand)->count(), 'لا نسخة فارغة');
    }

    public function test_a_provider_error_fails_the_job_and_refunds(): void
    {
        $this->answerWith(ProfileFixtures::modelOutput());

        // الطابور الحقيقي غير متزامن: نحجز ونشغّل المهمة يدوياً كما يفعل العامل
        Queue::fake();
        $this->actingAs($this->user)->post('/brand/profile/regenerate');
        $this->assertSame(98, $this->brand->refresh()->credit_balance, 'الحجز قبل التشغيل');

        $job = GenerationJob::withoutBrandScope()->latest('id')->firstOrFail();
        $worker = new GenerateBrandProfileJob($job->id);

        $this->ai->replyWith(new RuntimeException('انقطع الاتصال بالمزود'));

        try {
            $worker->handle(app(BrandProfileGenerator::class));
            $this->fail('كان يجب أن يرمي الخطأ');
        } catch (RuntimeException $e) {
            $worker->failed($e);
        }

        $this->assertSame(JobStatus::Failed, $job->refresh()->status);
        $this->assertSame(100, $this->brand->refresh()->credit_balance);
    }

    // ================================================================
    //  بوابة الجودة وقت التشغيل
    // ================================================================

    protected function badOutput(): array
    {
        return ProfileFixtures::modelOutput([
            'simple' => 'امدادات القهوة تخدم المقاهي منذ 2016 بأسعار رخيصة، وهي الأفضل في المملكة.',
        ]);
    }

    public function test_a_clean_reply_needs_one_request_only(): void
    {
        $this->answerWith(ProfileFixtures::modelOutput());

        $this->assertCount(1, $this->ai->requests);
        $this->assertSame(1, $this->active()->quality['attempts']);
        $this->assertSame(100, $this->active()->quality['score']);
    }

    public function test_invented_facts_trigger_one_named_correction_and_the_fix_is_saved(): void
    {
        $this->ai->replyWith($this->badOutput());
        $this->answerWith(ProfileFixtures::modelOutput());

        $this->assertCount(2, $this->ai->requests);

        // التصحيح يسمّي المشاكل بعينها، ولا يقول «أعد المحاولة» فقط
        $correction = $this->ai->requests[1]->prompt;
        $this->assertStringContainsString('## تصحيح مطلوب', $correction);
        $this->assertStringContainsString('2016', $correction);
        $this->assertStringContainsString('رخيص', $correction);
        $this->assertSame(0.3, $this->ai->requests[1]->temperature);

        $profile = $this->active();
        $this->assertSame(ProfileFixtures::simple(), $profile->simple);
        $this->assertTrue($profile->quality['passes']);
        $this->assertSame(2, $profile->quality['attempts']);
        $this->assertSame([], $profile->visibleIssues());

        $this->assertSame(100, $this->brand->refresh()->credit_balance, 'التصحيح على حسابنا: الأول مجاني ولم يُخصم شيء');
    }

    public function test_a_paid_regeneration_is_charged_once_even_with_a_correction(): void
    {
        $this->answerWith(ProfileFixtures::modelOutput());

        $this->ai->replyWith($this->badOutput(), ProfileFixtures::modelOutput());
        $this->actingAs($this->user)->post('/brand/profile/regenerate');

        $this->assertSame(98, $this->brand->refresh()->credit_balance);
    }

    public function test_if_the_correction_fails_too_the_problems_are_shown_on_the_page(): void
    {
        $this->ai->replyWith($this->badOutput());
        $this->answerWith($this->badOutput());

        $profile = $this->active();
        $codes = array_column($profile->visibleIssues(), 'code');

        $this->assertFalse($profile->quality['passes']);
        $this->assertContains('invented_number', $codes);
        $this->assertContains('banned_word', $codes);

        $html = $this->actingAs($this->user)->get('/brand/profile')->getContent();
        $this->assertStringContainsString('راجع هذه النقاط قبل استخدام الأوصاف', $html);
        $this->assertStringContainsString('الرقم «2016» غير موجود في الإجابات', $html);
        $this->assertStringContainsString('وطلبنا تصحيحه مرة', $html);
    }

    public function test_a_failed_correction_request_keeps_the_first_draft(): void
    {
        $this->ai->replyWith($this->badOutput(), new RuntimeException('نفدت الحصة'));

        $this->actingAs($this->user)->put('/brand/profile/answers', ProfileFixtures::answers());

        $job = GenerationJob::withoutBrandScope()->latest('id')->firstOrFail();

        $this->assertSame(JobStatus::Completed, $job->status, 'المسودة الأولى صالحة للعرض: لا تُسقط المهمة');
        $this->assertStringContainsString('منذ 2016', $this->active()->simple);
        $this->assertNotEmpty($this->active()->visibleIssues());
    }

    public function test_a_manual_edit_clears_the_warning(): void
    {
        $this->ai->replyWith($this->badOutput());
        $this->answerWith($this->badOutput());

        $this->actingAs($this->user)->put('/brand/profile', ['field' => 'simple', 'simple' => 'امدادات القهوة مورد لمستلزمات المقاهي.']);

        $this->assertNull($this->active()->quality);
        $this->assertStringNotContainsString(
            'راجع هذه النقاط',
            $this->actingAs($this->user)->get('/brand/profile')->getContent(),
        );
    }

    public function test_an_overlong_reply_is_cut_at_the_end_of_a_sentence(): void
    {
        $this->answerWith(ProfileFixtures::modelOutput([
            'simple' => str_repeat('امدادات القهوة وجهة متكاملة لمستلزمات المقاهي والمطاعم. ', 20),
        ]));

        $simple = $this->active()->simple;

        // كان Str::limit يقطع عند الحرف 800 ويضع «…» في منتصف الجملة
        $this->assertStringEndsWith('والمطاعم.', $simple);
        $this->assertLessThanOrEqual(800, mb_strlen($simple));
        $this->assertFalse($this->qualityOf($this->active())->has('truncated'));
    }

    public function test_a_run_on_reply_is_cut_at_a_whole_word(): void
    {
        $runOn = str_repeat('امدادات القهوة تورد مستلزمات المقاهي والمطاعم ', 30);

        $this->answerWith(ProfileFixtures::modelOutput(['simple' => $runOn]));

        $simple = $this->active()->simple;
        $kept = mb_substr($simple, 0, -1);

        $this->assertStringEndsWith('…', $simple, 'لا نهاية جملة: «…» علامة صادقة على القطع');
        $this->assertStringStartsWith($kept, $runOn);
        $this->assertSame(' ', mb_substr($runOn, mb_strlen($kept), 1), 'القطع عند كلمة كاملة');
    }

    // ================================================================
    //  الأثر على برومبت المحتوى
    // ================================================================

    public function test_the_content_prompt_after_generation_is_clean(): void
    {
        $this->answerWith(ProfileFixtures::modelOutput());

        $builder = PromptBuilder::make()->forBrand($this->brand->refresh());
        $prompt = $builder->prompt();

        $this->assertStringNotContainsString('Array', $prompt.$builder->system());
        $this->assertStringContainsString('ملخص المبيعات: سيروب وصوص', $prompt);
        $this->assertStringContainsString('أبرز التوصيل المجاني للأنشطة التجارية داخل المدينة.', $builder->system());
    }
}
