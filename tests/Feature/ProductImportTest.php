<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\GenerationJob;
use App\Models\Product;
use App\Models\User;
use App\Services\Import\StoreCrawler;
use App\Services\Import\Support\Fetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ProductImportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'محمد', 'email' => 'import@example.com', 'password' => 'secret123',
        ]);

        $this->brand = Brand::create([
            'user_id' => $this->user->id,
            'name' => 'إمدادات القهوة',
            'industry' => 'توريد مستلزمات المقاهي',
            'audience' => 'أصحاب المقاهي في السعودية',
            'dialect' => 'saudi',
            'credit_balance' => 100,
            'credits_allowance' => 100,
            'onboarding_completed' => true,
        ]);

        $this->user->update(['current_brand_id' => $this->brand->id]);
    }

    // ================= حارس الشبكة =================

    public static function privateTargets(): array
    {
        return [
            ['http://127.0.0.1/products'],
            ['http://169.254.169.254/latest/meta-data/'],   // بيانات اعتماد السحابة
            ['http://10.1.2.3/shop'],
            ['http://192.168.1.10/'],
            ['ftp://example.com/x'],
            ['http://intranet/'],                            // اسم بلا نقطة
        ];
    }

    /** @dataProvider privateTargets */
    public function test_it_refuses_to_fetch_internal_addresses(string $url): void
    {
        $this->expectException(RuntimeException::class);

        app(Fetcher::class)->assertPublicUrl($url);
    }

    public function test_it_normalises_a_pasted_store_url(): void
    {
        $fetcher = app(Fetcher::class);

        $this->assertSame('https://shop.example.com', $fetcher->normalizeStoreUrl('shop.example.com'));
        $this->assertSame('https://shop.example.com', $fetcher->normalizeStoreUrl('https://shop.example.com/ar/page?x=1'));
    }

    // ================= الاكتشاف =================

    public function test_it_reads_a_shopify_store_in_one_request_per_page(): void
    {
        Http::fake([
            '*/products.json*' => Http::response([
                'products' => [[
                    'id' => 77, 'title' => 'حبوب إسبريسو', 'handle' => 'espresso',
                    'body_html' => '<p>حبوب <b>محمصة</b> وسط.</p>',
                    'product_type' => 'قهوة', 'vendor' => 'المحمصة',
                    'variants' => [['price' => '75.00', 'sku' => 'ESP-1']],
                    'images' => [['src' => 'https://cdn.example.com/a.jpg']],
                ]],
            ]),
        ]);

        $result = app(StoreCrawler::class)->discover('shop.example.com');

        $this->assertSame('شوبيفاي', $result->platform);
        $this->assertSame(1, $result->total);
        $this->assertCount(1, $result->items);

        $product = $result->items[0];
        $this->assertSame('حبوب إسبريسو', $product->title);
        $this->assertSame(75.0, $product->price);
        $this->assertSame('ESP-1', $product->sku);
        $this->assertSame('حبوب محمصة وسط.', $product->summary, 'وسوم HTML لم تُنظّف');
        $this->assertSame(['https://cdn.example.com/a.jpg'], $product->imageUrls);
    }

    public function test_it_falls_back_to_the_sitemap_and_reads_json_ld(): void
    {
        Http::fake([
            '*/products.json*' => Http::response('', 404),
            '*/wp-json/*' => Http::response('', 404),
            '*/robots.txt' => Http::response('', 404),
            '*/sitemap.xml' => Http::response(<<<'XML'
                <urlset>
                  <url><loc>https://salla.example.com/ar/about</loc></url>
                  <url><loc>https://salla.example.com/ar/latte-cup/p1031884614</loc></url>
                </urlset>
                XML),
            '*/p1031884614' => Http::response(<<<'HTML'
                <html><head><meta property="og:type" content="product">
                <script type="application/ld+json">
                {"@context":"https://schema.org","@type":"Product","name":"كوب لاتيه",
                 "description":"كوب سيراميك 240 مل","sku":"CUP-240",
                 "image":["https://cdn.example.com/cup.jpg"],
                 "offers":{"@type":"Offer","price":"35.5","priceCurrency":"SAR"}}
                </script>
                </head><body><h1>كوب لاتيه</h1></body></html>
                HTML),
        ]);

        $crawler = app(StoreCrawler::class);
        $result = $crawler->readBatch($crawler->discover('salla.example.com'));

        $this->assertSame('خريطة الموقع', $result->platform);
        $this->assertSame(1, $result->total, 'صفحة «عن المتجر» يجب ألا تُعدّ منتجاً');
        $this->assertCount(1, $result->items);

        $product = $result->items[0];
        $this->assertSame('كوب لاتيه', $product->title);
        $this->assertSame(35.5, $product->price);
        $this->assertSame('SAR', $product->currency);
        $this->assertSame('CUP-240', $product->sku);
        $this->assertSame('p1031884614', $product->externalId, 'المعرّف يُقرأ من آخر المسار');
    }

    public function test_a_page_without_product_data_is_dropped_not_queued_again(): void
    {
        Http::fake([
            '*/products.json*' => Http::response('', 404),
            '*/wp-json/*' => Http::response('', 404),
            '*/robots.txt' => Http::response('', 404),
            '*/sitemap.xml' => Http::response('<urlset><url><loc>https://x.example.com/product/broken</loc></url></urlset>'),
            '*/product/broken' => Http::response('', 500),
        ]);

        $crawler = app(StoreCrawler::class);
        $result = $crawler->readBatch($crawler->discover('x.example.com'));

        $this->assertCount(0, $result->items);
        $this->assertSame([], $result->pendingUrls, 'الرابط الفاشل يجب ألا يبقى في الانتظار فيعلق العدّاد');
    }

    // ================= الاستيراد والنقاط =================

    protected function fakeShopify(): void
    {
        Http::fake([
            '*/products.json*' => Http::response([
                'products' => [[
                    'id' => 77, 'title' => 'حبوب إسبريسو', 'handle' => 'espresso',
                    'body_html' => 'حبوب محمصة وسط.',
                    'variants' => [['price' => '75.00', 'sku' => 'ESP-1']],
                    'images' => [['src' => 'https://cdn.example.com/a.jpg']],
                ]],
            ]),
        ]);
    }

    protected function scan(): GenerationJob
    {
        $this->fakeShopify();

        $this->actingAs($this->user)
            ->post('/products/import/scan', ['store_url' => 'shop.example.com'])
            ->assertRedirect();

        return GenerationJob::withoutBrandScope()->where('type', 'store_scan')->latest('id')->firstOrFail();
    }

    public function test_scanning_is_free_and_stores_the_result(): void
    {
        $before = $this->brand->fresh()->credit_balance;

        $job = $this->scan();

        $this->assertSame('completed', $job->status->value, $job->error ?? '');
        $this->assertSame(1, $job->result['total']);
        $this->assertEquals($before, $this->brand->fresh()->credit_balance, 'الاكتشاف يجب أن يكون مجانياً');
    }

    public function test_importing_selected_products_charges_one_credit_each(): void
    {
        $scan = $this->scan();
        $key = $scan->result['items'][0]['external_id'];

        $before = $this->brand->fresh()->credit_balance;

        $this->actingAs($this->user)
            ->post('/products/import', ['job' => $scan->uuid, 'keys' => [$key]])
            ->assertRedirect();

        $product = Product::withoutBrandScope()->where('external_id', '77')->firstOrFail();

        $this->assertSame('shopify', $product->source);
        $this->assertSame(75.0, (float) $product->price);
        $this->assertNotNull($product->features, 'الإثراء لم يملأ المميزات');
        $this->assertStringContainsString('المميزات', $product->spec_sheet);
        $this->assertSame('https://cdn.example.com/a.jpg', $product->images()->first()->url());

        $this->assertEquals($before - 1, $this->brand->fresh()->credit_balance);
    }

    public function test_reimporting_the_same_product_updates_instead_of_duplicating(): void
    {
        $scan = $this->scan();
        $key = $scan->result['items'][0]['external_id'];

        foreach ([1, 2] as $_) {
            $this->actingAs($this->user)->post('/products/import', ['job' => $scan->uuid, 'keys' => [$key]]);
        }

        $this->assertSame(1, Product::withoutBrandScope()->where('external_id', '77')->count());
    }

    public function test_it_ignores_keys_that_were_not_in_the_scan(): void
    {
        $scan = $this->scan();

        $this->actingAs($this->user)
            ->post('/products/import', ['job' => $scan->uuid, 'keys' => ['منتج-ملفّق']])
            ->assertSessionHasErrors('keys');

        $this->assertSame(0, Product::withoutBrandScope()->count());
    }

    // ================= رابط واحد =================

    public function test_single_url_import_returns_fields_and_charges_one_credit(): void
    {
        Http::fake(['*/p999' => Http::response(
            '<html><head><meta property="og:title" content="سيروب فانيليا">'
            .'<meta property="og:description" content="عبوة لتر واحد">'
            .'<meta property="product:price:amount" content="55">'
            .'<meta property="og:image" content="https://cdn.example.com/s.jpg"></head><body></body></html>'
        )]);

        $before = $this->brand->fresh()->credit_balance;

        $response = $this->actingAs($this->user)
            ->postJson('/products/import/single', ['url' => 'https://shop.example.com/p999'])
            ->assertOk();

        $response->assertJsonPath('title', 'سيروب فانيليا');
        $response->assertJsonPath('price', '55');
        $this->assertNotEmpty($response->json('features'));
        $this->assertSame(['https://cdn.example.com/s.jpg'], $response->json('image_urls'));

        $this->assertEquals($before - 1, $this->brand->fresh()->credit_balance);
    }

    public function test_single_url_import_charges_nothing_when_the_page_is_unreadable(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $before = $this->brand->fresh()->credit_balance;

        $this->actingAs($this->user)
            ->postJson('/products/import/single', ['url' => 'https://shop.example.com/missing'])
            ->assertStatus(422);

        $this->assertEquals($before, $this->brand->fresh()->credit_balance);
    }

    // ================= أنماط سلة الحقيقية =================

    /**
     * أشكال الروابط مأخوذة من خريطة coffeesupplies.com.sa نفسها:
     * 1023 منتجاً بنسختين (ar/en)، و8 صفحات بنمط /p/، وتصنيفات بنمط /c<رقم>.
     */
    protected function fakeSallaStore(): void
    {
        $productPage = fn (string $name, string $price) => Http::response(
            '<html><head><meta property="og:type" content="product">'
            .'<script type="application/ld+json">'
            .json_encode([
                '@type' => 'Product', 'name' => $name,
                'offers' => ['@type' => 'Offer', 'price' => $price, 'priceCurrency' => 'SAR'],
            ], JSON_UNESCAPED_UNICODE)
            .'</script></head><body></body></html>'
        );

        Http::fake([
            '*/products.json*' => Http::response('', 404),
            '*/wp-json/*' => Http::response('', 404),
            '*/robots.txt' => Http::response("Sitemap: https://coffee.example.com/sitemap.xml\n"),
            '*/sitemap.xml' => Http::response(
                '<sitemapindex><sitemap><loc>https://coffee.example.com/ar/sitemap-1.xml</loc></sitemap></sitemapindex>'
            ),
            '*/ar/sitemap-1.xml' => Http::response(implode('', [
                '<urlset>',
                '<url><loc>https://coffee.example.com/ar/bisan-hazelnut/p454952899</loc></url>',
                '<url><loc>https://coffee.example.com/en/bisan-hazelnut-white-5kg/p454952899</loc></url>',
                '<url><loc>https://coffee.example.com/ar/mokado-blend/p1268016610</loc></url>',
                '<url><loc>https://coffee.example.com/en/mokado-blend/p1268016610</loc></url>',
                '<url><loc>https://coffee.example.com/ar/p/wholesale-orders</loc></url>',
                '<url><loc>https://coffee.example.com/en/p/wholesale-coffee-supplies</loc></url>',
                '<url><loc>https://coffee.example.com/ar/giffard/c357928555</loc></url>',
                '</urlset>',
            ])),
            '*/p454952899' => $productPage('حشوة كريمة البندق البيضاء من بيسان', '120'),
            '*/p1268016610' => $productPage('محمصة موكادو بلند سبريسو', '75'),
            // صفحة متجر: لا Product ولا سعر، وog:type=page
            '*/p/*' => Http::response(
                '<html><head><meta property="og:type" content="page">'
                .'<meta property="og:title" content="طلبات الجملة قهوة وأدوات باريستا">'
                .'<meta property="og:description" content="اطلب بالجملة من المورد الأول">'
                .'<script type="application/ld+json">{"@type":"WebPage","name":"طلبات الجملة"}</script>'
                .'</head><body></body></html>'
            ),
        ]);
    }

    public function test_store_pages_and_categories_are_not_treated_as_products(): void
    {
        $this->fakeSallaStore();

        $result = app(StoreCrawler::class)->discover('coffee.example.com');

        $urls = $result->pendingUrls;

        $this->assertCount(2, $urls, 'يجب أن يبقى منتجان فقط بعد استبعاد الصفحات والتصنيفات');

        foreach ($urls as $url) {
            $this->assertStringNotContainsString('/p/', $url, 'نمط /p/ هو صفحة في سلة لا منتج');
            $this->assertStringNotContainsString('/c357928555', $url, 'التصنيف ليس منتجاً');
        }
    }

    public function test_language_variants_of_one_product_collapse_into_a_single_row(): void
    {
        $this->fakeSallaStore();

        $result = app(StoreCrawler::class)->discover('coffee.example.com');

        $this->assertSame(2, $result->total, 'المنتج الواحد بنسختيه العربية والإنجليزية صفّ واحد');

        foreach ($result->pendingUrls as $url) {
            $this->assertStringContainsString('/ar/', $url, 'تُفضَّل النسخة العربية');
        }

        $read = app(StoreCrawler::class)->readBatch($result);

        // المفاتيح فريدة، وإلا تحرّك مربعا اختيار معاً في الواجهة
        $keys = array_map(fn ($item) => $item->key(), $read->items);
        $this->assertSame($keys, array_unique($keys));
    }

    public function test_a_store_page_that_slips_through_is_rejected_when_read(): void
    {
        $this->fakeSallaStore();

        // نتجاوز ترشيح الروابط عمداً لاختبار الحارس الثاني
        $page = app(StoreCrawler::class)->read('https://coffee.example.com/ar/p/wholesale-orders');

        $this->assertNull($page, 'صفحة بلا Product ولا سعر يجب ألا تُقرأ كمنتج');
    }

    public function test_it_reads_the_real_salla_product_shape(): void
    {
        $this->fakeSallaStore();

        $crawler = app(StoreCrawler::class);
        $result = $crawler->readBatch($crawler->discover('coffee.example.com'));

        $titles = array_map(fn ($i) => $i->title, $result->items);

        $this->assertContains('حشوة كريمة البندق البيضاء من بيسان', $titles);
        $this->assertContains('محمصة موكادو بلند سبريسو', $titles);

        $bisan = collect($result->items)->firstWhere('title', 'حشوة كريمة البندق البيضاء من بيسان');
        $this->assertSame(120.0, $bisan->price);
        $this->assertSame('SAR', $bisan->currency);
        $this->assertSame('p454952899', $bisan->externalId);
    }

    // ================= الوصف المختصر =================

    public function test_a_written_summary_survives_saving(): void
    {
        $this->actingAs($this->user)->post('/products', [
            'type' => 'good',
            'title' => 'سيروب فانيليا',
            'features' => "قوام ثابت\nبلا نكهة صناعية",
            'specifications' => 'عبوة 1 لتر',
            'summary' => 'وصف كتبه المستخدم بنفسه ولا يجوز استبداله.',
        ])->assertSessionHasNoErrors();

        $product = Product::where('title', 'سيروب فانيليا')->firstOrFail();

        $this->assertSame('وصف كتبه المستخدم بنفسه ولا يجوز استبداله.', $product->summary);

        // والتعديل لا يبتلعه أيضاً
        $this->actingAs($this->user)->put("/products/{$product->id}", [
            'type' => 'good',
            'title' => 'سيروب فانيليا',
            'features' => "قوام ثابت\nبلا نكهة صناعية",
            'specifications' => 'عبوة 1 لتر',
            'summary' => 'نص محرَّر بعد الحفظ.',
        ]);

        $this->assertSame('نص محرَّر بعد الحفظ.', $product->fresh()->summary);
    }

    public function test_a_blank_summary_is_still_derived_from_the_features(): void
    {
        $this->actingAs($this->user)->post('/products', [
            'type' => 'good',
            'title' => 'بلا وصف',
            'features' => 'أول سطر من المميزات يصلح وصفاً مختصراً.',
            'specifications' => 'ص',
        ])->assertSessionHasNoErrors();

        $this->assertStringContainsString(
            'أول سطر من المميزات',
            Product::where('title', 'بلا وصف')->firstOrFail()->summary
        );
    }

    public function test_regenerating_the_summary_costs_one_credit(): void
    {
        $before = $this->brand->fresh()->credit_balance;

        $response = $this->actingAs($this->user)->postJson('/products/summary', [
            'title' => 'حشوة كريمة البندق',
            'features' => "وزن 5 كجم\nمنتج أصلي",
            'audience' => 'أصحاب المقاهي',
        ])->assertOk();

        $this->assertNotEmpty($response->json('summary'));
        $this->assertEquals($before - 1, $this->brand->fresh()->credit_balance);
    }

    public function test_imported_image_urls_are_attached_without_downloading(): void
    {
        $this->actingAs($this->user)->post('/products', [
            'type' => 'good',
            'title' => 'منتج بصور مستوردة',
            'features' => 'م',
            'specifications' => 'ص',
            'image_urls' => ['https://cdn.example.com/1.jpg', 'https://cdn.example.com/2.jpg'],
        ])->assertSessionHasNoErrors();

        $product = Product::where('title', 'منتج بصور مستوردة')->firstOrFail();
        $images = $product->images;

        $this->assertCount(2, $images);
        $this->assertSame('https://cdn.example.com/1.jpg', $images[0]->url());
        $this->assertTrue($images[0]->is_reference, 'الصورة الأولى هي المرجع البصري');
        $this->assertSame('', $images[0]->path, 'لا ملف محلياً — الرابط هو المصدر');
    }

    public function test_the_brand_name_reaches_the_spec_sheet(): void
    {
        Http::fake(['*/p454952899' => Http::response(
            '<html><head><meta property="og:type" content="product">'
            .'<script type="application/ld+json">'
            .json_encode([
                '@type' => 'Product', 'name' => 'حشوة كريمة البندق البيضاء 5kg',
                'sku' => 'بيسان-حشوة-كريمة-البندق/p454952899',
                'brand' => ['@type' => 'Brand', 'name' => 'حشوات بيسان'],
                'offers' => ['price' => '120', 'priceCurrency' => 'SAR'],
            ], JSON_UNESCAPED_UNICODE)
            .'</script></head><body></body></html>'
        )]);

        $discovered = app(StoreCrawler::class)->read('https://coffee.example.com/ar/bisan/p454952899');

        $this->assertNull($discovered->sku, 'سلة تضع المسار في sku — يجب رفضه');
        $this->assertSame('حشوات بيسان', $discovered->brandName);

        $product = app(\App\Services\Import\ProductImporter::class)
            ->import($this->brand, $discovered, 'خريطة الموقع');

        $this->assertStringContainsString('حشوات بيسان', $product->spec_sheet);
        $this->assertStringNotContainsString('/p454952899', $product->spec_sheet);
    }

    public function test_a_failed_enrichment_still_returns_the_scraped_data_and_refunds(): void
    {
        Http::fake(['*/p777' => Http::response(
            '<html><head><meta property="og:type" content="product">'
            .'<meta property="og:title" content="سيروب فانيليا">'
            .'<meta property="product:price:amount" content="55">'
            .'<meta property="og:image" content="https://cdn.example.com/v.jpg"></head><body></body></html>'
        )]);

        // نُسقط خطوة الذكاء الاصطناعي عمداً
        $this->mock(\App\Services\Import\ProductEnricher::class, function ($mock) {
            $mock->shouldReceive('fieldsFor')->andThrow(new \RuntimeException('المزوّد لا يستجيب'));
        });

        $before = $this->brand->fresh()->credit_balance;

        $response = $this->actingAs($this->user)
            ->postJson('/products/import/single', ['url' => 'https://shop.example.com/p777'])
            ->assertOk();

        // البيانات المقروءة مجاناً يجب ألا تضيع لأن التحليل تعثّر
        $response->assertJsonPath('title', 'سيروب فانيليا');
        $this->assertSame(['https://cdn.example.com/v.jpg'], $response->json('image_urls'));
        $this->assertNotEmpty($response->json('warning'));

        $this->assertSame($before, $this->brand->fresh()->credit_balance, 'لم تُرجَع النقطة بعد فشل التحليل');
    }
}
