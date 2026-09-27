<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\GenerationJob;
use App\Models\MediaAsset;
use App\Models\User;
use App\Support\CurrentBrand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * عزل العلامات في ربط النماذج بالمسار ({contentItem}، {mediaAsset}…).
 *
 * CurrentBrand ثابت على مستوى العملية ويضبطه الوسيط brand.ready. لو جاء ربط النموذج
 * (SubstituteBindings) قبله، لجاء النطاق العام فارغاً في كل طلب إنتاج (عملية جديدة)
 * فيُفتح محتوى علامة أخرى بمعرّفه. الاختبارات تعيش في عملية واحدة فكان يخفيه براند
 * الطلب السابق — لذا نفرّغه قبل كل طلب هنا كما في عملية جديدة.
 */
class BrandIsolationBindingTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $intruder;

    protected ContentItem $item;

    protected MediaAsset $asset;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        [$this->owner, $brand] = $this->account('owner@example.com', 'متجر المالك');
        [$this->intruder] = $this->account('intruder@example.com', 'متجر آخر');

        $this->item = ContentItem::withoutBrandScope()->create([
            'brand_id' => $brand->id, 'goal' => 'engagement', 'platform' => 'instagram', 'format' => 'carousel',
            'caption' => 'سر المالك', 'status' => 'ready',
            'body' => ['caption' => 'سر المالك', 'slides' => [['role' => 'hook', 'text' => 'أ'], ['role' => 'pull', 'text' => 'ب'], ['role' => 'ask', 'text' => 'ج']]],
        ]);

        $job = GenerationJob::withoutBrandScope()->create(['brand_id' => $brand->id, 'type' => 'image', 'status' => JobStatus::Completed, 'payload' => []]);
        Storage::disk('public')->put('brands/owner/a.png', 'x');
        $this->asset = MediaAsset::withoutBrandScope()->create([
            'brand_id' => $brand->id, 'generation_job_id' => $job->id, 'kind' => 'image', 'disk' => 'public',
            'path' => 'brands/owner/a.png', 'mime' => 'image/png', 'bytes' => 1, 'width' => 1, 'height' => 1, 'prompt' => 'x',
        ]);
    }

    /** @return array{0: User, 1: Brand} */
    protected function account(string $email, string $name): array
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => 'secret123']);
        $brand = Brand::create(['user_id' => $user->id, 'name' => $name, 'credit_balance' => 10, 'credits_allowance' => 10, 'onboarding_completed' => true]);
        $user->update(['current_brand_id' => $brand->id]);

        return [$user, $brand];
    }

    /** طلب كأنه في عملية جديدة: لا براند متبقٍ من طلب سابق. */
    protected function fresh(): static
    {
        CurrentBrand::clear();

        return $this->actingAs($this->intruder);
    }

    public function test_another_brands_content_cannot_be_opened_by_id(): void
    {
        $this->fresh()->get(route('content.show', $this->item))->assertNotFound();
        $this->fresh()->getJson(route('studio.carousels.show', $this->item))->assertNotFound();
    }

    public function test_another_brands_content_cannot_be_changed_by_id(): void
    {
        $this->fresh()->put(route('content.update', $this->item), ['caption' => 'اختراق'])->assertNotFound();
        $this->fresh()->putJson(route('studio.carousels.update', $this->item), ['slides' => [['text' => 'اختراق'], ['text' => 'ب'], ['text' => 'ج']]])->assertNotFound();

        $this->assertSame('سر المالك', $this->item->fresh()->caption);
    }

    public function test_another_brands_image_cannot_be_deleted_by_id(): void
    {
        $this->fresh()->delete(route('studio.media.destroy', $this->asset))->assertNotFound();

        $this->assertDatabaseHas('media_assets', ['id' => $this->asset->id]);
        Storage::disk('public')->assertExists('brands/owner/a.png');
    }

    public function test_the_owner_still_reaches_their_own_content(): void
    {
        CurrentBrand::clear();
        $this->actingAs($this->owner)->get(route('content.show', $this->item))->assertOk();
    }
}
