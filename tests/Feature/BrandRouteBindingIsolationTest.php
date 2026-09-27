<?php

namespace Tests\Feature;

use App\Enums\ContentStatus;
use App\Enums\JobStatus;
use App\Http\Middleware\EnsureBrandIsReady;
use App\Models\Brand;
use App\Models\BrandLogo;
use App\Models\ContentItem;
use App\Models\GenerationJob;
use App\Models\MediaAsset;
use App\Models\MediaFolder;
use App\Models\Offer;
use App\Models\Product;
use App\Models\Testimonial;
use App\Models\User;
use App\Support\CurrentBrand;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * عزل المستأجر عند ربط النماذج بالمسار ({mediaAsset}، {contentItem}، {product}...).
 *
 * كان EnsureBrandIsReady خارج قائمة أولوية الوسائط، فيعمل بعد SubstituteBindings:
 * في عملية PHP جديدة يكون CurrentBrand فارغاً لحظة الربط، فلا يضيف نطاق BelongsToBrand
 * أي شرط ويُجلب صف أي علامة — حذف مستخدمٌ صورة علامة غيره بمعرّفها.
 *
 * CurrentBrand ثابت على مستوى العملية ويبقى فيه براند طلب أو اختبار سابق فيُخفي الخلل،
 * لذا يُفرَّغ قبل كل طلب هنا محاكاةً لعملية جديدة.
 */
class BrandRouteBindingIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Brand $ownerBrand;

    protected User $intruder;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        [$this->owner, $this->ownerBrand] = $this->merchant('owner@example.com', 'محمصة الوادي');
        [$this->intruder] = $this->merchant('intruder@example.com', 'علامة أخرى');
    }

    /** @return array{User, Brand} */
    protected function merchant(string $email, string $brandName): array
    {
        $user = User::create(['name' => $brandName, 'email' => $email, 'password' => 'secret123']);

        $brand = Brand::create([
            'user_id' => $user->id,
            'name' => $brandName,
            'credit_balance' => 100,
            'credits_allowance' => 100,
            'onboarding_completed' => true,
        ]);

        $user->update(['current_brand_id' => $brand->id]);

        return [$user, $brand];
    }

    /** أول طلب في عملية PHP جديدة: لا براند متبقٍ من طلب سابق. */
    protected function freshRequestAs(User $user): static
    {
        CurrentBrand::clear();

        return $this->actingAs($user);
    }

    public function test_brand_is_resolved_after_auth_and_before_route_bindings(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array('brand.ready', $route->gatherMiddleware(), true));

        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $stack = Route::gatherRouteMiddleware($route);
            $brand = array_search(EnsureBrandIsReady::class, $stack, true);

            $this->assertLessThan($brand, array_search(Authenticate::class, $stack, true), $route->uri());
            $this->assertLessThan(array_search(SubstituteBindings::class, $stack, true), $brand, $route->uri());
        }
    }

    public function test_media_asset_routes_do_not_resolve_another_brands_image(): void
    {
        $folder = MediaFolder::create(['brand_id' => $this->ownerBrand->id, 'name' => 'حملة رمضان']);
        Storage::disk('public')->put('brands/1/media/owned.png', 'fake-bytes');

        $asset = MediaAsset::create([
            'brand_id' => $this->ownerBrand->id,
            'folder_id' => $folder->id,
            'kind' => 'image',
            'disk' => 'public',
            'path' => 'brands/1/media/owned.png',
            'prompt' => 'صورة المالك',
        ]);

        $this->freshRequestAs($this->intruder)->delete(route('studio.media.destroy', $asset))->assertNotFound();
        $this->freshRequestAs($this->intruder)->post(route('studio.media.pin', $asset))->assertNotFound();
        $this->freshRequestAs($this->intruder)->post(route('studio.media.move', $asset), ['folder_id' => null])->assertNotFound();
        $this->freshRequestAs($this->intruder)->post(route('studio.regenerate', $asset))->assertNotFound();

        $this->assertDatabaseHas('media_assets', ['id' => $asset->id, 'is_pinned' => false, 'folder_id' => $folder->id]);
        Storage::disk('public')->assertExists('brands/1/media/owned.png');

        $this->freshRequestAs($this->owner)->post(route('studio.media.pin', $asset))->assertOk()->assertJson(['pinned' => true]);
        $this->freshRequestAs($this->owner)->post(route('studio.media.move', $asset), ['folder_id' => null])->assertRedirect();
        $this->assertDatabaseHas('media_assets', ['id' => $asset->id, 'is_pinned' => true, 'folder_id' => null]);

        $this->freshRequestAs($this->owner)->delete(route('studio.media.destroy', $asset))->assertRedirect();
        $this->assertDatabaseMissing('media_assets', ['id' => $asset->id]);
        Storage::disk('public')->assertMissing('brands/1/media/owned.png');
    }

    public function test_content_item_routes_do_not_resolve_another_brands_content(): void
    {
        $item = ContentItem::create([
            'brand_id' => $this->ownerBrand->id,
            'goal' => 'direct_sales',
            'platform' => 'instagram',
            'format' => 'post',
            'language' => 'ar',
            'body' => ['caption' => 'نص المالك'],
            'caption' => 'نص المالك',
            'status' => ContentStatus::Draft,
            'in_plan' => false,
        ]);

        $this->freshRequestAs($this->intruder)->get(route('content.show', $item))->assertNotFound();
        $this->freshRequestAs($this->intruder)->put(route('content.update', $item), ['caption' => 'نص الدخيل'])->assertNotFound();
        $this->freshRequestAs($this->intruder)->delete(route('content.destroy', $item))->assertNotFound();
        $this->freshRequestAs($this->intruder)->post(route('content.retry', $item))->assertNotFound();
        $this->freshRequestAs($this->intruder)->post(route('content.add-to-plan', $item))->assertNotFound();
        $this->freshRequestAs($this->intruder)->post(route('content.slides.rewrite', [$item, 0]))->assertNotFound();
        $this->freshRequestAs($this->intruder)->post(route('studio.carousel', $item))->assertNotFound();
        $this->freshRequestAs($this->intruder)->post(route('studio.attach', $item))->assertNotFound();

        $this->assertDatabaseHas('content_items', ['id' => $item->id, 'caption' => 'نص المالك', 'in_plan' => false]);

        $this->freshRequestAs($this->owner)->put(route('content.update', $item), ['caption' => 'نص معدّل'])->assertRedirect();
        $this->assertDatabaseHas('content_items', ['id' => $item->id, 'caption' => 'نص معدّل']);

        $this->freshRequestAs($this->owner)->delete(route('content.destroy', $item))->assertRedirect();
        $this->assertDatabaseMissing('content_items', ['id' => $item->id]);
    }

    public function test_product_routes_do_not_resolve_another_brands_product(): void
    {
        $product = Product::create([
            'brand_id' => $this->ownerBrand->id,
            'type' => 'good',
            'title' => 'حبوب يرقاشيفي',
            'price' => 75,
            'currency' => 'SAR',
            'is_primary' => false,
        ]);

        $this->freshRequestAs($this->intruder)->get(route('products.edit', $product))->assertNotFound();
        $this->freshRequestAs($this->intruder)->put(route('products.update', $product), ['title' => 'منتج الدخيل'])->assertNotFound();
        $this->freshRequestAs($this->intruder)->delete(route('products.destroy', $product))->assertNotFound();
        $this->freshRequestAs($this->intruder)->post(route('products.primary', $product))->assertNotFound();
        $this->freshRequestAs($this->intruder)->post(route('products.duplicate', $product))->assertNotFound();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'title' => 'حبوب يرقاشيفي', 'is_primary' => false]);
        $this->assertDatabaseCount('products', 1);

        $this->freshRequestAs($this->owner)->delete(route('products.destroy', $product))->assertRedirect();
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }

    public function test_store_fact_routes_do_not_resolve_another_brands_offer_or_testimonial(): void
    {
        $offer = Offer::create([
            'brand_id' => $this->ownerBrand->id,
            'title' => 'خصم اليوم الوطني',
            'type' => 'percent',
            'value' => 15,
            'coupon_code' => 'KSA96',
            'ends_at' => now()->addWeek()->toDateString(),
            'is_active' => true,
        ]);

        $testimonial = Testimonial::create([
            'brand_id' => $this->ownerBrand->id,
            'author_name' => 'سارة',
            'body' => 'تجربة رائعة مع الطحن',
            'display_as' => 'first_name',
            'consented_at' => now(),
        ]);

        $this->freshRequestAs($this->intruder)->patch(route('store.offers.toggle', $offer))->assertNotFound();
        $this->freshRequestAs($this->intruder)->delete(route('store.offers.destroy', $offer))->assertNotFound();
        $this->freshRequestAs($this->intruder)->delete(route('store.testimonials.destroy', $testimonial))->assertNotFound();

        $this->assertDatabaseHas('offers', ['id' => $offer->id, 'is_active' => true]);
        $this->assertDatabaseHas('testimonials', ['id' => $testimonial->id]);

        $this->freshRequestAs($this->owner)->patch(route('store.offers.toggle', $offer))->assertRedirect();
        $this->assertDatabaseHas('offers', ['id' => $offer->id, 'is_active' => false]);

        $this->freshRequestAs($this->owner)->delete(route('store.offers.destroy', $offer))->assertRedirect();
        $this->freshRequestAs($this->owner)->delete(route('store.testimonials.destroy', $testimonial))->assertRedirect();
        $this->assertDatabaseMissing('offers', ['id' => $offer->id]);
        $this->assertDatabaseMissing('testimonials', ['id' => $testimonial->id]);
    }

    public function test_folder_logo_and_job_routes_do_not_resolve_another_brands_rows(): void
    {
        $folder = MediaFolder::create(['brand_id' => $this->ownerBrand->id, 'name' => 'حملة رمضان']);
        $logo = BrandLogo::create(['brand_id' => $this->ownerBrand->id, 'path' => 'brands/1/logos/main.png', 'label' => 'الأساسي', 'sort' => 0]);
        $job = GenerationJob::create(['brand_id' => $this->ownerBrand->id, 'type' => 'import', 'status' => JobStatus::Queued, 'payload' => []]);

        $this->freshRequestAs($this->intruder)->delete(route('studio.folders.destroy', $folder))->assertNotFound();
        $this->freshRequestAs($this->intruder)->patch(route('brand.logos.update', $logo), ['label' => 'شعار الدخيل'])->assertNotFound();
        $this->freshRequestAs($this->intruder)->delete(route('brand.logos.destroy', $logo))->assertNotFound();
        $this->freshRequestAs($this->intruder)->get(route('api.jobs.show', $job))->assertNotFound();
        $this->freshRequestAs($this->intruder)->post(route('products.import.more', $job))->assertNotFound();

        $this->assertDatabaseHas('media_folders', ['id' => $folder->id]);
        $this->assertDatabaseHas('brand_logos', ['id' => $logo->id, 'label' => 'الأساسي']);

        $this->freshRequestAs($this->owner)->getJson(route('api.jobs.show', $job))->assertOk()->assertJson(['uuid' => $job->uuid]);
        $this->freshRequestAs($this->owner)->delete(route('studio.folders.destroy', $folder))->assertRedirect();
        $this->assertDatabaseMissing('media_folders', ['id' => $folder->id]);
    }
}
