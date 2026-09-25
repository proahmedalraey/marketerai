<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Models\Brand;
use App\Models\GenerationJob;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\AI\AiManager;
use App\Services\AI\DTO\ImageRequest;
use App\Services\AI\DTO\ImageResponse;
use App\Services\AI\ProviderException;
use App\Services\Credits\CreditService;
use App\Services\Settings\AiSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * محاسبة نقاط الصور الكسرية (0.5 / 1.5 / 3.5 ...): الحجز والتسوية والاسترجاع
 * يجب أن تحفظ الكسر. كانت المتصلات (GenerateImageJob وImageGenerationService
 * والمهام العالقة) تحوّل المحجوز بـ(int) فيقصّ 0.5 إلى 0 — يُخصم من التاجر
 * ولا يُسجَّل، أو يُخصم ولا يُرجَع عند الفشل.
 */
class ImageStudioCreditsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['ai.media_disk' => 'public']);

        $this->user = User::create(['name' => 'سارة', 'email' => 'credits@example.com', 'password' => 'secret123']);

        $this->brand = Brand::create([
            'user_id' => $this->user->id,
            'name' => 'محمصة الوادي',
            'credit_balance' => 100,
            'credits_allowance' => 100,
            'onboarding_completed' => true,
        ]);

        $this->user->update(['current_brand_id' => $this->brand->id]);
    }

    /** مدير يُنتج صوراً أقل مما طُلب، أو يرفض الطلب كلياً. */
    protected function fakeProvider(?int $produce = null, bool $reject = false): void
    {
        $spy = new class(app(AiSettings::class)) extends AiManager
        {
            public ?int $produce = null;

            public bool $reject = false;

            public function generateImage(ImageRequest $request, ?GenerationJob $job = null, ?string $provider = null): ImageResponse
            {
                if ($this->reject) {
                    throw new ProviderException('رفض المزود الطلب', 'fake', 400);
                }

                $response = parent::generateImage($request, $job, $provider);

                if ($this->produce !== null) {
                    $response->images = array_slice($response->images, 0, $this->produce);
                }

                return $response;
            }
        };

        $spy->produce = $produce;
        $spy->reject = $reject;
        app()->instance(AiManager::class, $spy);
    }

    protected function generate(string $quality, int $count = 1)
    {
        return $this->actingAs($this->user)->post(route('studio.generate'), [
            'prompt' => 'كوب قهوة',
            'aspect_ratio' => '1:1',
            'quality' => $quality,
            'count' => $count,
            'use_brand_identity' => 0,
        ]);
    }

    public function test_a_half_credit_image_is_charged_half_a_credit(): void
    {
        $this->fakeProvider();

        $this->generate('1k_low')->assertRedirect();

        $job = GenerationJob::where('type', 'image')->sole();

        $this->assertSame(JobStatus::Completed, $job->status);
        $this->assertEquals(0.5, $job->credits_charged, 'المسجَّل يطابق المخصوم');
        $this->assertEquals(0, $job->credits_held, 'لا حجز معلّق بعد التسوية');
        $this->assertEquals(99.5, $this->brand->refresh()->credit_balance);
    }

    public function test_a_fractional_cost_is_charged_exactly(): void
    {
        $this->fakeProvider();

        $this->generate('4k_medium')->assertRedirect(); // 3.5

        $job = GenerationJob::where('type', 'image')->sole();

        $this->assertEquals(3.5, $job->credits_charged);
        $this->assertEquals(0, $job->credits_held);
        $this->assertEquals(96.5, $this->brand->refresh()->credit_balance);
    }

    public function test_a_partial_result_refunds_only_the_missing_images(): void
    {
        $this->fakeProvider(produce: 2);

        $this->generate('1k_low', 3)->assertRedirect(); // حجز 1.5، وصلت صورتان = 1.0

        $job = GenerationJob::where('type', 'image')->sole();

        $this->assertSame(2, MediaAsset::where('kind', 'image')->count());
        $this->assertEquals(1.0, $job->credits_charged);
        $this->assertEquals(99.0, $this->brand->refresh()->credit_balance, 'دُفع ثمن صورتين فقط');
    }

    public function test_a_rejected_request_refunds_the_whole_fractional_hold(): void
    {
        $this->fakeProvider(reject: true);

        $this->generate('1k_low')->assertRedirect();

        $job = GenerationJob::where('type', 'image')->sole();

        $this->assertSame(JobStatus::Failed, $job->status);
        $this->assertEquals(100, $this->brand->refresh()->credit_balance, 'فشل التوليد يُرجع كامل الحجز حتى 0.5');
        $this->assertEquals(0, $job->credits_held);
    }

    public function test_reaping_a_stuck_job_refunds_its_fractional_hold(): void
    {
        $this->fakeProvider();

        // حجز يدوي لمهمة عالقة: 3.5 نقطة (4k متوسطة)
        $job = GenerationJob::create([
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'type' => 'image',
            'status' => JobStatus::Processing,
            'payload' => ['prompt' => 'x', 'quality' => '4k_medium', 'aspect_ratio' => '1:1', 'count' => 1],
        ]);
        app(CreditService::class)->hold($this->brand, 'image.4k_medium', 1, $job);
        $this->assertEquals(96.5, $this->brand->refresh()->credit_balance);

        GenerationJob::withoutBrandScope()->whereKey($job->id)->update(['updated_at' => now()->subHour()]);

        $this->artisan('ai:reap-stuck-jobs')->assertSuccessful();

        $this->assertEquals(100, $this->brand->refresh()->credit_balance);
    }
}
