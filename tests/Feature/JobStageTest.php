<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Models\Brand;
use App\Models\GenerationJob;
use App\Models\User;
use App\Services\AI\AiManager;
use App\Services\AI\DTO\TextRequest;
use App\Support\JobStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class JobStageTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'ai.text_provider' => 'openrouter',
            'ai.providers.openrouter.api_key' => 'sk-or-v1-test',
            'ai.retry.times' => 0,
        ]);

        $this->user = User::create(['name' => 'م', 'email' => 's@example.com', 'password' => 'secret123']);
        $this->brand = Brand::create([
            'user_id' => $this->user->id, 'name' => 'علامة', 'industry' => 'قهوة', 'audience' => 'مقاهي',
            'tone' => 'ودود', 'dialect' => 'saudi', 'onboarding_completed' => true,
        ]);
        $this->user->update(['current_brand_id' => $this->brand->id]);
    }

    protected function job(JobStatus $status = JobStatus::Processing): GenerationJob
    {
        return GenerationJob::create([
            'uuid' => (string) Str::uuid(), 'brand_id' => $this->brand->id, 'user_id' => $this->user->id,
            'type' => 'content', 'status' => $status, 'payload' => [],
        ])->fresh(); // قيم الأعمدة الافتراضية (children_total = 0) لا تصل النموذج إلا بعد إعادة تحميله
    }

    protected function ok(): array
    {
        return [
            'model' => 'm',
            'choices' => [['message' => ['content' => 'نص'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ];
    }

    public function test_operations_map_to_stages(): void
    {
        $this->assertSame('writing', JobStage::forOperation('content.carousel'));
        $this->assertSame('writing', JobStage::forOperation('brand.profile'));
        $this->assertSame('writing', JobStage::forOperation('product.spec_sheet'));
        $this->assertSame('fixing', JobStage::forOperation('content.correction'));
        $this->assertSame('proofreading', JobStage::forOperation('content.proofread'));
        $this->assertSame('drawing', JobStage::forOperation('image.standard_1k'));
        $this->assertNull(JobStage::forOperation('settings.test'));

        $this->assertSame('checking', JobStage::after('content.carousel'));
        $this->assertSame('checking', JobStage::after('content.correction'));
        $this->assertSame('saving', JobStage::after('content.proofread'));
        $this->assertSame('saving', JobStage::after('brand.profile'));
        $this->assertNull(JobStage::after('settings.test'));
    }

    public function test_the_stage_follows_the_calls_a_job_makes(): void
    {
        $job = $this->job();
        $seen = [];

        Http::fake(function () use ($job, &$seen) {
            $seen[] = JobStage::current($job);

            return Http::response($this->ok());
        });

        $ai = app(AiManager::class);

        $ai->generateText(new TextRequest(system: 's', prompt: 'p', operation: 'content.carousel'), $job);
        $afterWrite = JobStage::current($job);

        $ai->generateText(new TextRequest(system: 's', prompt: 'p', operation: 'content.proofread'), $job);
        $afterProof = JobStage::current($job);

        // أثناء الطلب: الكتابة ثم المراجعة. وبعد كل منهما: ما يفعله النظام تالياً
        $this->assertSame(['writing', 'proofreading'], $seen);
        $this->assertSame('checking', $afterWrite);
        $this->assertSame('saving', $afterProof);
    }

    public function test_labels_and_progress_follow_the_job_state(): void
    {
        $queued = $this->job(JobStatus::Queued);
        $this->assertSame('في الانتظار', JobStage::label($queued));
        $this->assertSame(5, $queued->progress());

        $running = $this->job();
        $this->assertSame('قيد التنفيذ', JobStage::label($running));
        $this->assertSame(50, $running->progress());

        JobStage::set($running, 'proofreading');
        $this->assertSame('يراجع الإملاء واللغة', JobStage::label($running));
        $this->assertSame(80, $running->progress());

        $done = $this->job(JobStatus::Completed);
        JobStage::set($done, 'saving');
        $this->assertNull(JobStage::label($done));
        $this->assertSame(100, $done->progress());
    }

    public function test_progress_only_moves_forward_through_a_content_pipeline(): void
    {
        $job = $this->job();
        $values = [];

        foreach (['writing', 'checking', 'proofreading', 'saving'] as $stage) {
            JobStage::set($job, $stage);
            $values[] = $job->progress();
        }

        $sorted = $values;
        sort($sorted);

        $this->assertSame($sorted, $values);
    }

    public function test_an_unknown_or_missing_job_never_breaks_a_call(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response($this->ok())]);

        // بلا مهمة: لا مرحلة ولا خطأ
        $response = app(AiManager::class)->generateText(new TextRequest(system: 's', prompt: 'p', operation: 'content.post'));

        $this->assertSame('نص', $response->raw);
    }

    public function test_the_status_endpoint_reports_the_stage(): void
    {
        $job = $this->job();
        JobStage::set($job, 'checking');

        $this->actingAs($this->user)->getJson("/api/jobs/{$job->uuid}")
            ->assertOk()
            ->assertJsonPath('stage', 'يفحص الأرقام والادعاءات')
            ->assertJsonPath('progress', 55);
    }
}
