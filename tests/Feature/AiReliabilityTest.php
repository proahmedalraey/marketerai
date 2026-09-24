<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Jobs\GenerateBrandProfileJob;
use App\Models\Brand;
use App\Models\BrandProfile;
use App\Models\GenerationJob;
use App\Models\User;
use App\Services\AI\AiManager;
use App\Services\AI\DTO\TextRequest;
use App\Services\AI\ProviderException;
use App\Services\Brand\BrandProfileGenerator;
use App\Services\Credits\CreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProfileFixtures;
use Tests\TestCase;

/**
 * سلوك النظام حين يتعثر المزود. الأجسام هنا منسوخة من ردود Gemini
 * الحقيقية التي ظهرت أثناء فحص 2026-09-21 (حصة مجانية 20 طلباً يومياً).
 */
class AiReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected const GEMINI_DAILY_QUOTA = [
        'error' => [
            'code' => 429,
            'message' => 'You exceeded your current quota. * Quota exceeded for metric: generate_content_free_tier_requests, limit: 20',
            'status' => 'RESOURCE_EXHAUSTED',
            'details' => [
                ['@type' => 'type.googleapis.com/google.rpc.QuotaFailure', 'violations' => [[
                    'quotaMetric' => 'generativelanguage.googleapis.com/generate_content_free_tier_requests',
                    'quotaId' => 'GenerateRequestsPerDayPerProjectPerModel-FreeTier',
                    'quotaValue' => '20',
                ]]],
                ['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => '35.458252165s'],
            ],
        ],
    ];

    protected const GEMINI_PER_MINUTE = [
        'error' => [
            'code' => 429,
            'status' => 'RESOURCE_EXHAUSTED',
            'details' => [
                ['@type' => 'type.googleapis.com/google.rpc.QuotaFailure', 'violations' => [[
                    'quotaId' => 'GenerateRequestsPerMinutePerProjectPerModel',
                ]]],
                ['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => '2s'],
            ],
        ],
    ];

    protected const GEMINI_OVERLOADED = [
        'error' => ['code' => 503, 'message' => 'This model is currently experiencing high demand.', 'status' => 'UNAVAILABLE'],
    ];

    protected function useGemini(): void
    {
        config([
            'ai.text_provider' => 'gemini',
            'ai.providers.gemini.api_key' => 'test-key',
            'ai.retry.times' => 2,
            'ai.retry.sleep_ms' => 1,
        ]);
    }

    protected function geminiOk(): array
    {
        return ['candidates' => [['content' => ['parts' => [
            ['text' => json_encode(ProfileFixtures::modelOutput(), JSON_UNESCAPED_UNICODE)],
        ]]]]];
    }

    // ================================================================
    //  تصنيف الخطأ
    // ================================================================

    public function test_a_daily_quota_is_exhausted_and_never_retried(): void
    {
        $e = ProviderException::fromStatus('gemini', 429, json_encode(self::GEMINI_DAILY_QUOTA));

        $this->assertTrue($e->quotaExhausted);
        $this->assertFalse($e->retryable);
        $this->assertSame(36, $e->retryAfterSeconds);
        $this->assertStringNotContainsString('{', $e->userMessage(), 'الرسالة للمستخدم بلا JSON');
    }

    public function test_a_per_minute_limit_is_retried_after_the_delay_it_asks_for(): void
    {
        $e = ProviderException::fromStatus('gemini', 429, json_encode(self::GEMINI_PER_MINUTE));

        $this->assertFalse($e->quotaExhausted);
        $this->assertTrue($e->retryable);
        $this->assertSame(2, $e->retryAfterSeconds);
    }

    public function test_a_long_requested_wait_fails_fast_instead_of_holding_the_worker(): void
    {
        $e = ProviderException::fromStatus('anthropic', 429, '{"error":{"type":"rate_limit_error"}}', '60');

        $this->assertFalse($e->retryable);
        $this->assertSame(60, $e->retryAfterSeconds);
    }

    public function test_other_statuses(): void
    {
        $this->assertTrue(ProviderException::fromStatus('gemini', 503, json_encode(self::GEMINI_OVERLOADED))->retryable);
        $this->assertFalse(ProviderException::fromStatus('gemini', 400, '{}')->retryable);
        $this->assertTrue(ProviderException::fromStatus('openai', 429, '{"error":{"code":"insufficient_quota"}}')->quotaExhausted);
    }

    // ================================================================
    //  عدد الطلبات الفعلية
    // ================================================================

    public function test_an_exhausted_quota_costs_exactly_one_request(): void
    {
        $this->useGemini();
        Http::fake(['*' => Http::response(self::GEMINI_DAILY_QUOTA, 429)]);

        try {
            app(AiManager::class)->generateText(new TextRequest('system', 'prompt'));
            $this->fail('كان يجب أن يفشل');
        } catch (ProviderException $e) {
            $this->assertTrue($e->quotaExhausted);
        }

        // قبل الإصلاح: ثلاث محاولات هنا، ومضاعفتها في الطابور = ستة طلبات
        Http::assertSentCount(1);
    }

    public function test_a_temporary_overload_is_retried_then_succeeds(): void
    {
        $this->useGemini();
        Http::fakeSequence()
            ->push(self::GEMINI_OVERLOADED, 503)
            ->push($this->geminiOk(), 200);

        $response = app(AiManager::class)->generateText(new TextRequest('system', 'prompt', ['type' => 'object']));

        $this->assertSame(ProfileFixtures::simple(), $response->data['simple']);
        Http::assertSentCount(2);
    }

    // ================================================================
    //  مهمة ملف الهوية
    // ================================================================

    protected function brand(): array
    {
        $user = User::create(['name' => 'م', 'email' => 'rel@example.com', 'password' => 'secret123']);
        $brand = Brand::create([
            'user_id' => $user->id, 'name' => 'امدادات القهوة', 'audience' => 'أصحاب المقاهي',
            'description' => 'نبيع مستلزمات المقاهي', 'selling_points' => ['توصيل مجاني'],
            'credit_balance' => 100, 'credits_allowance' => 100, 'onboarding_completed' => true,
        ]);
        $user->update(['current_brand_id' => $brand->id]);

        // نسخة سابقة تجعل إعادة التوليد مدفوعة
        BrandProfile::createVersion($brand, ['simple' => 'سابق', 'detailed' => 'سابق', 'answers' => $brand->profileAnswers()]);

        return [$user, $brand];
    }

    public function test_a_regeneration_on_an_exhausted_quota_fails_once_refunds_and_explains(): void
    {
        $this->useGemini();
        Http::fake(['*' => Http::response(self::GEMINI_DAILY_QUOTA, 429)]);
        [$user, $brand] = $this->brand();

        $this->actingAs($user)->post('/brand/profile/regenerate')->assertRedirect();

        $job = GenerationJob::withoutBrandScope()->where('type', 'brand_profile')->firstOrFail();

        $this->assertSame(JobStatus::Failed, $job->status);
        $this->assertStringContainsString('نفدت الحصة', $job->error);
        $this->assertEquals(100, $brand->refresh()->credit_balance);
        Http::assertSentCount(1);

        // الرسالة التي تصل للصفحة عبر الاستطلاع
        $this->actingAs($user)->getJson("/api/jobs/{$job->uuid}")
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('error', $job->error);
    }

    // ================================================================
    //  المهام العالقة
    // ================================================================

    public function test_stuck_jobs_are_failed_and_refunded_but_fresh_ones_are_left_alone(): void
    {
        [, $brand] = $this->brand();
        $credits = app(CreditService::class);

        $stuck = GenerationJob::create(['brand_id' => $brand->id, 'type' => 'brand_profile', 'status' => JobStatus::Queued, 'payload' => []]);
        $credits->hold($brand, BrandProfileGenerator::OPERATION, 1, $stuck);
        $stuck->forceFill(['updated_at' => now()->subMinutes(20)])->saveQuietly();

        $child = GenerationJob::create(['brand_id' => $brand->id, 'type' => 'image', 'status' => JobStatus::Queued, 'parent_id' => $stuck->id, 'payload' => []]);

        $fresh = GenerationJob::create(['brand_id' => $brand->id, 'type' => 'content', 'status' => JobStatus::Queued, 'payload' => []]);

        $this->assertEquals(98, $brand->refresh()->credit_balance);

        $this->artisan('ai:reap-stuck-jobs')->assertSuccessful();

        $this->assertSame(JobStatus::Failed, $stuck->refresh()->status);
        $this->assertSame(JobStatus::Failed, $child->refresh()->status);
        $this->assertSame(JobStatus::Queued, $fresh->refresh()->status);
        $this->assertEquals(100, $brand->refresh()->credit_balance, 'أُرجعت النقاط مرة واحدة');
    }

    public function test_a_worker_returning_after_the_reaper_does_not_run_the_job(): void
    {
        $this->useGemini();
        Http::fake();
        [, $brand] = $this->brand();

        $job = GenerationJob::create(['brand_id' => $brand->id, 'type' => 'brand_profile', 'status' => JobStatus::Queued, 'payload' => ['answers' => $brand->profileAnswers()]]);
        $job->forceFill(['updated_at' => now()->subMinutes(20)])->saveQuietly();

        $this->artisan('ai:reap-stuck-jobs');

        (new GenerateBrandProfileJob($job->id))->handle(app(BrandProfileGenerator::class));

        Http::assertNothingSent();
        $this->assertSame(1, BrandProfile::forBrand($brand)->count());
    }
}
