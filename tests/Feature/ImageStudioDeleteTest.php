<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\User;
use App\Support\CurrentBrand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * حذف صورة (البند 2.3 من مرحلة استوديو الصور الثانية): حذف فوري نهائي
 * بقرار المستخدم 2026-09-23 — لا حذف ناعم، يُمسح الملف من التخزين أيضاً.
 */
class ImageStudioDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->user = User::create(['name' => 'سارة', 'email' => 'delete@example.com', 'password' => 'secret123']);

        $this->brand = Brand::create([
            'user_id' => $this->user->id,
            'name' => 'محمصة الوادي',
            'credit_balance' => 100,
            'credits_allowance' => 100,
            'onboarding_completed' => true,
        ]);

        $this->user->update(['current_brand_id' => $this->brand->id]);
    }

    protected function asset(): MediaAsset
    {
        Storage::disk('public')->put('brands/1/media/to-delete.png', 'fake-bytes');

        return MediaAsset::create([
            'brand_id' => $this->brand->id,
            'kind' => 'image',
            'disk' => 'public',
            'path' => 'brands/1/media/to-delete.png',
            'prompt' => 'صورة للحذف',
        ]);
    }

    public function test_deleting_removes_the_record_and_the_file(): void
    {
        $asset = $this->asset();

        $this->actingAs($this->user)
            ->delete(route('studio.media.destroy', $asset))
            ->assertRedirect();

        $this->assertDatabaseMissing('media_assets', ['id' => $asset->id]);
        Storage::disk('public')->assertMissing('brands/1/media/to-delete.png');
    }

    public function test_deleting_another_brands_asset_is_not_found(): void
    {
        $otherUser = User::create(['name' => 'خالد', 'email' => 'other-del@example.com', 'password' => 'secret123']);
        $otherBrand = Brand::create([
            'user_id' => $otherUser->id,
            'name' => 'علامة أخرى',
            'credit_balance' => 100,
            'credits_allowance' => 100,
            'onboarding_completed' => true,
        ]);
        $otherUser->update(['current_brand_id' => $otherBrand->id]);

        Storage::disk('public')->put('brands/2/media/foreign.png', 'fake-bytes');

        $foreignAsset = MediaAsset::create([
            'brand_id' => $otherBrand->id,
            'kind' => 'image',
            'disk' => 'public',
            'path' => 'brands/2/media/foreign.png',
            'prompt' => 'صورة العلامة الأخرى',
        ]);

        // عملية جديدة: بلا براند متبقٍ يحجب الخلل (انظر BrandRouteBindingIsolationTest)
        CurrentBrand::clear();

        $this->actingAs($this->user)
            ->delete(route('studio.media.destroy', $foreignAsset))
            ->assertNotFound();

        $this->assertDatabaseHas('media_assets', ['id' => $foreignAsset->id]);
        Storage::disk('public')->assertExists('brands/2/media/foreign.png');
    }
}
