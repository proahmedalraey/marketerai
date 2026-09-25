<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Models\Brand;
use App\Models\GenerationJob;
use App\Models\User;
use App\Support\JobSummary;
use App\Support\QueueHealth;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QueueHealthTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        QueueHealth::forget();
        // الاختبارات تعمل بطابور sync؛ الفحص يخص طابور قاعدة البيانات
        config(['queue.default' => 'database']);

        $this->user = User::create(['name' => 'محمد', 'email' => 'q@example.com', 'password' => 'secret123']);
        $this->brand = Brand::create([
            'user_id' => $this->user->id, 'name' => 'علامة', 'industry' => 'قهوة',
            'audience' => 'مقاهي', 'tone' => 'ودود', 'dialect' => 'saudi', 'onboarding_completed' => true,
        ]);
        $this->user->update(['current_brand_id' => $this->brand->id]);
    }

    protected function job(JobStatus $status = JobStatus::Queued, int $ageSeconds = 120): GenerationJob
    {
        $job = GenerationJob::create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'type' => 'brand_profile',
            'status' => $status,
            'payload' => [],
        ]);

        $job->created_at = now()->subSeconds($ageSeconds);
        $job->save();

        return $job->fresh();
    }

    public function test_old_queued_job_with_no_worker_is_stalled(): void
    {
        $this->assertTrue(QueueHealth::isStalled($this->job()));
    }

    public function test_a_reserved_job_means_a_worker_is_alive_and_busy(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'content', 'payload' => '{}', 'attempts' => 1,
            'reserved_at' => time(), 'available_at' => time(), 'created_at' => time(),
        ]);

        $this->assertFalse(QueueHealth::isStalled($this->job()));
    }

    public function test_recent_worker_activity_covers_the_gap_between_two_jobs(): void
    {
        QueueHealth::beat();

        $this->assertFalse(QueueHealth::isStalled($this->job()));
    }

    public function test_stale_heartbeat_does_not_hide_a_dead_worker(): void
    {
        Cache::put(QueueHealth::HEARTBEAT_KEY, time() - 300, 3600);

        $this->assertTrue(QueueHealth::isStalled($this->job()));
    }

    public function test_a_fresh_job_is_not_stalled_yet(): void
    {
        $this->assertFalse(QueueHealth::isStalled($this->job(ageSeconds: 5)));
    }

    public function test_only_queued_jobs_can_be_stalled(): void
    {
        $this->assertFalse(QueueHealth::isStalled($this->job(JobStatus::Processing)));
        $this->assertFalse(QueueHealth::isStalled($this->job(JobStatus::Completed)));
    }

    public function test_drivers_other_than_database_are_never_reported(): void
    {
        config(['queue.default' => 'sync']);

        $this->assertFalse(QueueHealth::isStalled($this->job()));
    }

    public function test_worker_events_leave_a_heartbeat(): void
    {
        $this->assertNull(Cache::get(QueueHealth::HEARTBEAT_KEY));

        event(new JobProcessing('database', \Mockery::mock(Job::class)->shouldIgnoreMissing()));
        $this->assertNotNull(Cache::get(QueueHealth::HEARTBEAT_KEY));

        Cache::forget(QueueHealth::HEARTBEAT_KEY);

        event(new JobProcessed('database', \Mockery::mock(Job::class)->shouldIgnoreMissing()));
        $this->assertNotNull(Cache::get(QueueHealth::HEARTBEAT_KEY));
    }

    public function test_status_endpoint_and_panel_summary_carry_the_flag(): void
    {
        $job = $this->job();

        $this->actingAs($this->user)->getJson("/api/jobs/{$job->uuid}")
            ->assertOk()
            ->assertJsonPath('stalled', true);

        $this->assertTrue(JobSummary::collect(collect([$job]))[0]['stalled']);

        // بعد أن يبدأ عامل: العلامة تزول في الاستطلاع التالي
        QueueHealth::beat();
        QueueHealth::forget();

        $this->actingAs($this->user)->getJson("/api/jobs/{$job->uuid}")->assertJsonPath('stalled', false);
    }
}
