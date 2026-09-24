<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Models\Brand;
use App\Models\GenerationJob;
use App\Models\MediaAsset;
use App\Models\MediaFolder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * مجلدات المعرض والتثبيت (البند 2.2 من مرحلة استوديو الصور الثانية).
 * حذف مجلد لا يحذف صوره — folder_id يعود فارغاً (nullOnDelete).
 */
class ImageStudioFoldersTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->user = User::create(['name' => 'سارة', 'email' => 'folders@example.com', 'password' => 'secret123']);

        $this->brand = Brand::create([
            'user_id' => $this->user->id,
            'name' => 'محمصة الوادي',
            'credit_balance' => 100,
            'credits_allowance' => 100,
            'onboarding_completed' => true,
        ]);

        $this->user->update(['current_brand_id' => $this->brand->id]);
        $this->actingAs($this->user);
    }

    protected function generatedAsset(): MediaAsset
    {
        $job = GenerationJob::create([
            'brand_id' => $this->brand->id,
            'user_id' => $this->user->id,
            'type' => 'image',
            'status' => JobStatus::Completed,
            'payload' => ['prompt' => 'صورة', 'aspect_ratio' => '1:1', 'quality' => '1k_medium', 'count' => 1],
        ]);

        return MediaAsset::create([
            'brand_id' => $this->brand->id,
            'generation_job_id' => $job->id,
            'kind' => 'image',
            'disk' => 'public',
            'path' => 'brands/1/media/a.png',
            'prompt' => 'صورة',
        ]);
    }

    public function test_creating_a_folder(): void
    {
        $this->post(route('studio.folders.store'), ['name' => 'حملة رمضان'])
            ->assertRedirect();

        $this->assertDatabaseHas('media_folders', ['brand_id' => $this->brand->id, 'name' => 'حملة رمضان']);
    }

    public function test_moving_an_asset_into_and_out_of_a_folder(): void
    {
        $asset = $this->generatedAsset();
        $folder = MediaFolder::create(['brand_id' => $this->brand->id, 'name' => 'حملة رمضان']);

        $this->post(route('studio.media.move', $asset), ['folder_id' => $folder->id])->assertRedirect();
        $this->assertSame($folder->id, $asset->fresh()->folder_id);

        $this->post(route('studio.media.move', $asset), [])->assertRedirect();
        $this->assertNull($asset->fresh()->folder_id);
    }

    public function test_the_gallery_can_be_filtered_by_folder(): void
    {
        $folder = MediaFolder::create(['brand_id' => $this->brand->id, 'name' => 'حملة رمضان']);
        $inFolder = $this->generatedAsset();
        $inFolder->update(['folder_id' => $folder->id]);
        $this->generatedAsset(); // خارج أي مجلد

        $response = $this->get(route('studio.index', ['folder' => $folder->id]));

        $response->assertOk();
        $gallery = $response->viewData('gallery');
        $this->assertCount(1, $gallery);
        $this->assertSame($inFolder->id, $gallery->first()->id);
    }

    public function test_deleting_a_folder_keeps_its_photos(): void
    {
        $folder = MediaFolder::create(['brand_id' => $this->brand->id, 'name' => 'حملة رمضان']);
        $asset = $this->generatedAsset();
        $asset->update(['folder_id' => $folder->id]);

        $this->delete(route('studio.folders.destroy', $folder))->assertRedirect();

        $this->assertDatabaseMissing('media_folders', ['id' => $folder->id]);
        $this->assertDatabaseHas('media_assets', ['id' => $asset->id]);
        $this->assertNull($asset->fresh()->folder_id);
    }

    public function test_pinning_and_unpinning_returns_json(): void
    {
        $asset = $this->generatedAsset();

        $this->postJson(route('studio.media.pin', $asset))
            ->assertOk()
            ->assertJson(['pinned' => true]);
        $this->assertTrue($asset->fresh()->is_pinned);

        $this->postJson(route('studio.media.pin', $asset))
            ->assertOk()
            ->assertJson(['pinned' => false]);
        $this->assertFalse($asset->fresh()->is_pinned);
    }

    public function test_the_gallery_can_be_filtered_by_pinned(): void
    {
        $pinned = $this->generatedAsset();
        $pinned->update(['is_pinned' => true]);
        $this->generatedAsset();

        $response = $this->get(route('studio.index', ['pinned' => 1]));

        $gallery = $response->viewData('gallery');
        $this->assertCount(1, $gallery);
        $this->assertSame($pinned->id, $gallery->first()->id);
    }

    public function test_moving_to_another_brands_folder_is_rejected(): void
    {
        $asset = $this->generatedAsset();

        $otherBrand = Brand::create([
            'user_id' => $this->user->id,
            'name' => 'علامة أخرى',
            'credit_balance' => 100,
            'credits_allowance' => 100,
            'onboarding_completed' => true,
        ]);
        $foreignFolder = MediaFolder::create(['brand_id' => $otherBrand->id, 'name' => 'مجلد غريب']);

        $this->post(route('studio.media.move', $asset), ['folder_id' => $foreignFolder->id])
            ->assertSessionHasErrors('folder_id');

        $this->assertNull($asset->fresh()->folder_id);
    }

    public function test_deleting_another_brands_folder_is_not_found(): void
    {
        $otherUser = User::create(['name' => 'خالد', 'email' => 'other-folder@example.com', 'password' => 'secret123']);
        $otherBrand = Brand::create([
            'user_id' => $otherUser->id,
            'name' => 'علامة أخرى',
            'credit_balance' => 100,
            'credits_allowance' => 100,
            'onboarding_completed' => true,
        ]);
        $foreignFolder = MediaFolder::create(['brand_id' => $otherBrand->id, 'name' => 'مجلد غريب']);

        $this->delete(route('studio.folders.destroy', $foreignFolder))->assertNotFound();

        $this->assertDatabaseHas('media_folders', ['id' => $foreignFolder->id]);
    }
}
