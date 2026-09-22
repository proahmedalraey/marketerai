<?php

namespace App\Services\Import;

use App\Models\Brand;
use App\Models\Product;
use App\Services\Import\DTO\DiscoveredProduct;
use App\Services\Products\SpecSheetBuilder;
use Illuminate\Support\Str;

/**
 * يحوّل ما قرأناه من المتجر إلى سجل عندنا.
 *
 * لا يستدعي الذكاء الاصطناعي: الحفظ يجب أن ينجح حتى لو فشل الإثراء لاحقاً،
 * وإلا خسر المستخدم المنتج والنقطة معاً.
 */
class ProductImporter
{
    public function __construct(protected SpecSheetBuilder $specSheets) {}

    public function import(Brand $brand, DiscoveredProduct $discovered, string $platform): Product
    {
        $source = $this->sourceKey($platform);

        $product = Product::withoutBrandScope()
            ->where('brand_id', $brand->id)
            ->where('source', $source)
            ->where('external_id', $discovered->key())
            ->first() ?? new Product;

        $product->fill([
            'brand_id' => $brand->id,
            'type' => 'good',
            'title' => Str::limit($discovered->title, 180, '') ?: 'منتج بلا اسم',
            'summary' => $discovered->summary,
            // الوصف الخام يسكن المميزات مؤقتاً حتى يعيد الإثراء ترتيبه
            'features' => $product->features ?: $discovered->summary,
            'audience' => $product->audience ?: $brand->audience,
            'price' => $discovered->price,
            'currency' => strtoupper($discovered->currency ?: ($brand->products()->value('currency') ?: 'SAR')),
            'sku' => $discovered->sku ? Str::limit($discovered->sku, 64, '') : null,
            'category' => $discovered->category ? Str::limit($discovered->category, 120, '') : null,
            // العلامة المصنّعة إشارة قوية للنموذج وتظهر في الورقة المرجعية
            'brand_name' => $discovered->brandName ? Str::limit($discovered->brandName, 120, '') : $product->brand_name,
            // حقائق البيع من صفحة المنتج نفسها: ما أغفله المنافس في تدقيقه (P3)
            ...$discovered->salesFacts(),
            'source' => $source,
            'external_id' => $discovered->key(),
            'synced_at' => now(),
            'is_active' => true,
        ]);

        $product->save();

        $this->syncImages($product, $discovered);

        $product->update(['spec_sheet' => $this->specSheets->build($product->fresh()->load('images'))]);

        return $product->refresh();
    }

    /**
     * نخزّن رابط الصورة لا الملف.
     * التنزيل يضاعف زمن الاستيراد ومساحة التخزين، ومولّد الصور
     * يقبل الرابط مرجعاً بصرياً كما يقبل المسار المحلي.
     */
    protected function syncImages(Product $product, DiscoveredProduct $discovered): void
    {
        if ($discovered->imageUrls === []) {
            return;
        }

        $existing = $product->images()->pluck('original_url')->filter()->all();
        $position = $product->images()->max('position') ?? -1;

        foreach (array_slice($discovered->imageUrls, 0, 6) as $url) {
            if (in_array($url, $existing, true)) {
                continue;
            }

            $product->images()->create([
                'disk' => 'public',
                'path' => '',
                'original_url' => Str::limit($url, 2000, ''),
                'position' => ++$position,
                'is_reference' => $position === 0,
            ]);
        }
    }

    protected function sourceKey(string $platform): string
    {
        return match (true) {
            str_contains($platform, 'شوبيفاي') => 'shopify',
            str_contains($platform, 'ووكومرس') => 'woocommerce',
            default => 'web',
        };
    }
}
