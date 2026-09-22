<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UiRenderSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Brand $brand;
    protected Product $product;
    protected ContentItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'محمد', 'email' => 'ui@example.com', 'password' => 'secret123',
        ]);

        $this->brand = Brand::create([
            'user_id' => $this->user->id,
            'name' => 'إمدادات القهوة',
            'industry' => 'توريد مستلزمات المقاهي',
            'audience' => 'أصحاب المقاهي في السعودية',
            'tone' => 'خبير ودود',
            'dialect' => 'saudi',
            'colors' => [
                ['hex' => '#6F4E37', 'name' => 'بني القهوة', 'role' => 'primary'],
                ['hex' => '#C8A27A', 'name' => null, 'role' => 'accent'],
            ],
            'onboarding_completed' => true,
        ]);

        $this->user->update(['current_brand_id' => $this->brand->id]);

        $this->product = Product::create([
            'brand_id' => $this->brand->id,
            'type' => 'good',
            'title' => 'حبوب إسبريسو',
            'summary' => 'حبوب محمصة وسط من إثيوبيا.',
            'features' => "تحميص وسط
نكهة حمضية فاكهية",
            'specifications' => 'وزن 250 جرام',
            'audience' => 'أصحاب المقاهي',
            'price' => 75,
            'currency' => 'SAR',
            'is_primary' => true,
            'is_active' => true,
        ]);

        $this->item = ContentItem::create([
            'brand_id' => $this->brand->id,
            'product_id' => $this->product->id,
            'goal' => 'direct_sales',
            'platform' => 'instagram',
            'format' => 'carousel',
            'template' => 'marketing_carousel',
            'caption' => 'كابشن تجريبي للاختبار.',
            'body' => [
                'slides' => [
                    ['role' => 'hook', 'text' => 'نص الشريحة الأولى'],
                    ['role' => 'ask', 'text' => 'نص الشريحة الأخيرة'],
                ],
                'hashtags' => ['قهوة', 'باريستا'],
            ],
            'status' => 'ready',
        ]);
    }

    public static function guestPages(): array
    {
        return [['/login'], ['/register']];
    }

    /** @dataProvider guestPages */
    public function test_guest_pages_render(string $uri): void
    {
        $this->get($uri)->assertOk();
    }

    public function test_authenticated_pages_render(): void
    {
        $pages = [
            '/dashboard',
            '/brand/profile',
            '/brand/identity',
            '/products',
            '/store/facts',
            '/products?add=1',
            "/products?edit={$this->product->id}",
            '/products?q=%D8%A5%D8%B3%D8%A8%D8%B1%D9%8A%D8%B3%D9%88&type=good',
            '/content/generator',
            '/content/generator?job=00000000-0000-0000-0000-000000000000',
            '/content/plan',
            '/content/plan?status=ready&platform=instagram&format=carousel',
            "/content/{$this->item->id}",
            '/studio',
            '/studio?job=00000000-0000-0000-0000-000000000000',
        ];

        foreach ($pages as $uri) {
            $response = $this->actingAs($this->user)->get($uri);

            $this->assertSame(200, $response->status(), "فشل عرض {$uri}");

            $html = $response->getContent();

            $this->assertStringNotContainsString('Undefined', $html, "متغير غير معرّف في {$uri}");
            $this->assertStringNotContainsString('<x-', $html, "مكوّن لم يُحل في {$uri}");
        }
    }

    public function test_no_emoji_icons_remain_in_rendered_pages(): void
    {
        $emoji = ['🏷️', '📦', '✍️', '🗓️', '🖼️', '🏠', '📝', '📑', '🎬', '🧵'];

        foreach (['/dashboard', '/products', '/content/plan', '/studio'] as $uri) {
            $html = $this->actingAs($this->user)->get($uri)->getContent();

            foreach ($emoji as $char) {
                $this->assertStringNotContainsString($char, $html, "بقي إيموجي {$char} في {$uri}");
            }
        }
    }

    public function test_product_pages_include_modal_entry_points(): void
    {
        $html = $this->actingAs($this->user)->get('/products')->getContent();

        $this->assertStringContainsString('productsIndex(', $html, 'مكوّن ألبين غير مركّب');
        $this->assertStringContainsString('ماذا تريد إضافته؟', $html, 'نافذة اختيار النوع غائبة');
        $this->assertStringContainsString('products/bulk', $html, 'شريط التحديد الجماعي غائب');
    }

    public function test_legacy_create_and_edit_routes_redirect_into_the_modal(): void
    {
        $this->actingAs($this->user)->get('/products/create')
            ->assertRedirect(route('products.index', ['add' => 1]));

        $this->actingAs($this->user)->get("/products/{$this->product->id}/edit")
            ->assertRedirect(route('products.index', ['edit' => $this->product->id]));
    }

    public function test_it_stores_a_good_and_builds_a_spec_sheet(): void
    {
        $this->actingAs($this->user)->post('/products', [
            'type' => 'good',
            'title' => 'سيروب فانيليا',
            'features' => "قوام ثابت
بلا نكهة صناعية",
            'specifications' => 'عبوة 1 لتر',
            'audience' => 'المقاهي المختصة',
            'price' => '55',
            'currency' => 'sar',
            'is_active' => '1',
        ])->assertRedirect(route('products.index'));

        $product = \App\Models\Product::where('title', 'سيروب فانيليا')->firstOrFail();

        $this->assertSame('SAR', $product->currency);
        $this->assertNotNull($product->summary, 'الوصف المختصر لم يُشتق');
        $this->assertStringContainsString('المميزات', $product->spec_sheet);
        $this->assertStringContainsString('الجمهور المستهدف', $product->spec_sheet);
        $this->assertNull($product->deliverables, 'التسليمات حقل خدمة ولا يجب أن تتسرب للسلعة');
    }

    public function test_it_stores_a_service_without_specifications(): void
    {
        $this->actingAs($this->user)->post('/products', [
            'type' => 'service',
            'title' => 'استشارة تسويقية',
            'features' => 'جلسة تحليل لحسابك ومنتجاتك.',
            'deliverables' => 'تقرير مكتوب وخطة شهر.',
            'audience' => 'أصحاب المتاجر الصغيرة',
        ])->assertRedirect(route('products.index'));

        $service = \App\Models\Product::where('title', 'استشارة تسويقية')->firstOrFail();

        $this->assertNull($service->specifications, 'المواصفات حقل سلعة ولا يجب أن تتسرب للخدمة');
        $this->assertStringContainsString('ما يحصل عليه العميل', $service->spec_sheet);
    }

    public function test_service_validation_requires_deliverables_not_specifications(): void
    {
        $this->actingAs($this->user)
            ->post('/products', ['type' => 'service', 'title' => 'خدمة', 'features' => 'وصف', 'audience' => 'جمهور'])
            ->assertSessionHasErrors('deliverables');

        // المواصفات اختيارية للسلعة: الإلزام كان يجبر التاجر على «غير متوفرة» (تدقيق المنافس P2)
        $this->actingAs($this->user)
            ->post('/products', ['type' => 'good', 'title' => 'سلعة', 'features' => 'مميزات', 'audience' => 'جمهور'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('products.index'));
    }

    public function test_blank_audience_inherits_the_brand_audience(): void
    {
        $this->actingAs($this->user)->post('/products', [
            'type' => 'good',
            'title' => 'بلا جمهور',
            'features' => 'مميزات',
            'specifications' => 'مواصفات',
        ])->assertSessionHasNoErrors();

        $product = \App\Models\Product::where('title', 'بلا جمهور')->firstOrFail();

        $this->assertSame($this->brand->audience, $product->audience);
        $this->assertStringContainsString($this->brand->audience, $product->spec_sheet);
    }

    public function test_spec_sheet_falls_back_to_brand_audience_for_legacy_rows(): void
    {
        // صف سبق وجود الحقل: لا جمهور مخزّن، ومع ذلك يجب أن يصل للبرومبت
        $legacy = \App\Models\Product::create([
            'brand_id' => $this->brand->id, 'type' => 'good', 'title' => 'قديم',
            'features' => 'م', 'specifications' => 'ص', 'audience' => null, 'is_active' => true,
        ]);

        $sheet = app(\App\Services\Products\SpecSheetBuilder::class)->build($legacy);

        $this->assertStringContainsString($this->brand->audience, $sheet);
    }

    public function test_primary_toggle_moves_the_reference(): void
    {
        $other = \App\Models\Product::create([
            'brand_id' => $this->brand->id, 'type' => 'good', 'title' => 'منتج آخر',
            'features' => 'س', 'specifications' => 'ص', 'audience' => 'ع', 'is_active' => true,
        ]);

        $this->actingAs($this->user)->post("/products/{$other->id}/primary");

        $this->assertTrue($other->fresh()->is_primary);
        $this->assertFalse($this->product->fresh()->is_primary, 'المرجع الأساسي يجب أن يكون واحداً');

        $this->actingAs($this->user)->post("/products/{$other->id}/primary");
        $this->assertFalse($other->fresh()->is_primary, 'الضغط مجدداً يلغي المرجعية');
    }

    public function test_bulk_actions_apply_to_selected_only(): void
    {
        $keep = \App\Models\Product::create([
            'brand_id' => $this->brand->id, 'type' => 'good', 'title' => 'يبقى',
            'features' => 'س', 'specifications' => 'ص', 'audience' => 'ع', 'is_active' => true,
        ]);

        $this->actingAs($this->user)
            ->post('/products/bulk', ['action' => 'deactivate', 'ids' => [$this->product->id]]);

        $this->assertFalse($this->product->fresh()->is_active);
        $this->assertTrue($keep->fresh()->is_active, 'العنصر غير المحدد تأثر');

        $this->actingAs($this->user)
            ->post('/products/bulk', ['action' => 'delete', 'ids' => [$this->product->id]]);

        $this->assertNull(\App\Models\Product::find($this->product->id));
        $this->assertNotNull($keep->fresh());
    }

    public function test_duplicate_creates_an_editable_copy(): void
    {
        $this->actingAs($this->user)->post("/products/{$this->product->id}/duplicate");

        $copy = \App\Models\Product::where('title', 'like', '%نسخة%')->firstOrFail();

        $this->assertFalse($copy->is_primary, 'النسخة لا ترث المرجعية');
        $this->assertSame($this->product->features, $copy->features);
    }
}
