<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Models\Brand;
use App\Models\GenerationJob;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * إعادة التوليد (البند 2.4 من مرحلة استوديو الصور الثانية): لا بيانات جديدة،
 * فقط إعادة تشغيل نفس حمولة المهمة الأصلية المخزَّنة في generation_jobs.payload.
 */
class ImageStudioRegenerateTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['ai.media_disk' => 'public']);

        $this->user = User::create(['name' => 'سارة', 'email' => 'regen@example.com', 'password' => 'secret123']);

        $this->brand = Brand::create([
            'user_id' => $this->user->id,
            'name' => 'محمصة الوادي',
            'credit_balance' => 100,
            'credits_allowance' => 100,
            'onboarding_completed' => true,
        ]);

        $this->user->update(['current_brand_id' => $this->brand->id]);
    }

    protected function originalAsset(array $payload = []): MediaAsset
    {
        $job = GenerationJob::create([
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'type' => 'image',
            'status' => JobStatus::Completed,
            'payload' => array_merge([
                'prompt' => 'كوب قهوة على طاولة خشبية',
                'aspect_ratio' => '1:1',
                'quality' => '1k_medium',
                'count' => 1,
                'content_item_id' => null,
                'slide_index' => null,
                'use_brand_identity' => false,
                'product_id' => null,
                'reference_asset_id' => null,
            ], $payload),
        ]);

        return MediaAsset::create([
            'brand_id' => $this->brand->id,
            'generation_job_id' => $job->id,
            'kind' => 'image',
            'disk' => 'public',
            'path' => 'brands/1/media/original.png',
            'prompt' => 'كوب قهوة على طاولة خشبية',
            'meta' => ['quality' => '1k_medium', 'aspect_ratio' => '1:1'],
        ]);
    }

    public function test_regenerating_reuses_the_original_payload_and_charges_a_new_credit(): void
    {
        $asset = $this->originalAsset();

        $response = $this->actingAs($this->user)->post(route('studio.regenerate', $asset));

        $newJob = GenerationJob::where('type', 'image')->latest('id')->first();
        $response->assertRedirect(route('studio.index', ['job' => $newJob->uuid]));

        $this->assertNotSame($asset->generation_job_id, $newJob->id, 'مهمة جديدة، لا إعادة استخدام القديمة');
        $this->assertSame('كوب قهوة على طاولة خشبية', $newJob->payload['prompt']);
        $this->assertSame('1:1', $newJob->payload['aspect_ratio']);
        $this->assertSame('1k_medium', $newJob->payload['quality']);

        // تكلفة image.1k_medium = 1 نقطة (config/credits.php)
        $this->assertEquals(99, $this->brand->refresh()->credit_balance);

        $this->assertSame(2, MediaAsset::where('kind', 'image')->count(), 'صورة جديدة إلى جانب الأصلية لا بديلاً عنها');
    }

    public function test_regenerating_another_brands_asset_is_not_found(): void
    {
        $otherUser = User::create(['name' => 'خالد', 'email' => 'other@example.com', 'password' => 'secret123']);
        $otherBrand = Brand::create([
            'user_id' => $otherUser->id,
            'name' => 'علامة أخرى',
            'credit_balance' => 100,
            'credits_allowance' => 100,
            'onboarding_completed' => true,
        ]);
        $otherUser->update(['current_brand_id' => $otherBrand->id]);

        $foreignAsset = $this->originalAssetFor($otherBrand, $otherUser);

        $this->actingAs($this->user)
            ->post(route('studio.regenerate', $foreignAsset))
            ->assertNotFound();
    }

    protected function originalAssetFor(Brand $brand, User $user): MediaAsset
    {
        $job = GenerationJob::create([
            'brand_id' => $brand->id,
            'user_id' => $user->id,
            'type' => 'image',
            'status' => JobStatus::Completed,
            'payload' => ['prompt' => 'صورة العلامة الأخرى', 'aspect_ratio' => '1:1', 'quality' => '1k_medium', 'count' => 1],
        ]);

        return MediaAsset::create([
            'brand_id' => $brand->id,
            'generation_job_id' => $job->id,
            'kind' => 'image',
            'disk' => 'public',
            'path' => 'brands/2/media/original.png',
            'prompt' => 'صورة العلامة الأخرى',
        ]);
    }
}
