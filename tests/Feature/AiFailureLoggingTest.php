<?php

namespace Tests\Feature;

use App\Models\AiUsageLog;
use App\Models\Brand;
use App\Models\GenerationJob;
use App\Models\User;
use App\Services\AI\AiManager;
use App\Services\AI\DTO\TextRequest;
use App\Services\AI\ProviderException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiFailureLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected GenerationJob $job;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.text_provider' => 'openrouter',
            'ai.providers.openrouter.api_key' => 'sk-or-v1-test',
            'ai.retry.times' => 2,
            'ai.retry.sleep_ms' => 10,
        ]);

        $user = User::create(['name' => 'م', 'email' => 'f@example.com', 'password' => 'secret123']);
        $brand = Brand::create([
            'user_id' => $user->id, 'name' => 'علامة', 'industry' => 'قهوة', 'audience' => 'مقاهي',
            'tone' => 'ودود', 'dialect' => 'saudi', 'onboarding_completed' => true,
        ]);

        $this->job = GenerationJob::create([
            'uuid' => (string) Str::uuid(), 'brand_id' => $brand->id, 'user_id' => $user->id,
            'type' => 'content', 'status' => 'processing', 'payload' => [],
            'started_at' => now()->subSeconds(30), 'finished_at' => now(),
        ]);
    }

    protected function ok(): array
    {
        return [
            'model' => 'google/gemini-3.6-flash',
            'choices' => [['message' => ['content' => 'نص'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3, 'cost' => 0.001],
        ];
    }

    protected function ask(): void
    {
        app(AiManager::class)->generateText(new TextRequest(system: 's', prompt: 'p'), $this->job);
    }

    public function test_a_failed_attempt_then_success_leaves_both_rows(): void
    {
        Http::fake(['openrouter.ai/*' => Http::sequence()
            ->push(['error' => ['code' => 503, 'message' => 'overloaded']], 503)
            ->push($this->ok())]);

        $this->ask();

        $logs = AiUsageLog::where('generation_job_id', $this->job->id)->orderBy('id')->get();

        $this->assertCount(2, $logs);

        $this->assertFalse($logs[0]->succeeded);
        $this->assertSame(1, $logs[0]->attempt);
        $this->assertSame(503, $logs[0]->status_code);
        $this->assertSame(10, $logs[0]->waited_ms);
        $this->assertStringContainsString('503', $logs[0]->error);

        $this->assertTrue($logs[1]->succeeded);
        $this->assertSame(2, $logs[1]->attempt);
        $this->assertSame(0, (int) $logs[1]->waited_ms);
    }

    public function test_the_last_failure_is_logged_without_a_wait(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['error' => ['code' => 503]], 503)]);

        try {
            $this->ask();
            $this->fail('كان يجب أن يُرمى استثناء');
        } catch (ProviderException) {
        }

        $logs = AiUsageLog::where('generation_job_id', $this->job->id)->orderBy('id')->get();

        $this->assertCount(3, $logs);
        $this->assertSame([1, 2, 3], $logs->pluck('attempt')->all());
        // الانتظار يُسجَّل بعد كل فشل يليه إعادة، لا بعد الأخير
        $this->assertSame([10, 20, 0], $logs->pluck('waited_ms')->map(fn ($v) => (int) $v)->all());
        $this->assertTrue($logs->every(fn ($l) => ! $l->succeeded));
    }

    public function test_a_non_retryable_error_is_logged_once(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['error' => ['code' => 401]], 401)]);

        try {
            $this->ask();
            $this->fail('كان يجب أن يُرمى استثناء');
        } catch (ProviderException) {
        }

        $logs = AiUsageLog::where('generation_job_id', $this->job->id)->get();

        $this->assertCount(1, $logs);
        $this->assertSame(401, $logs[0]->status_code);
    }

    public function test_a_connection_timeout_is_logged_and_still_raised(): void
    {
        Http::fake(['openrouter.ai/*' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);

        try {
            $this->ask();
            $this->fail('كان يجب أن يُرمى استثناء');
        } catch (ConnectionException) {
        }

        $log = AiUsageLog::where('generation_job_id', $this->job->id)->sole();

        $this->assertFalse($log->succeeded);
        $this->assertNull($log->status_code);
        $this->assertStringContainsString('timed out', $log->error);
    }

    public function test_a_clean_call_is_one_successful_row_on_attempt_one(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response($this->ok())]);

        $this->ask();

        $log = AiUsageLog::where('generation_job_id', $this->job->id)->sole();

        $this->assertTrue($log->succeeded);
        $this->assertSame(1, $log->attempt);
    }

    public function test_latency_command_shows_where_the_time_went(): void
    {
        Http::fake(['openrouter.ai/*' => Http::sequence()
            ->push(['error' => ['code' => 503]], 503)
            ->push($this->ok())]);

        $this->ask();

        $this->artisan('ai:latency', ['--jobs' => 5])
            ->expectsOutputToContain('503')
            ->expectsOutputToContain('ضاعت في محاولات فاشلة')
            ->assertSuccessful();
    }
}
