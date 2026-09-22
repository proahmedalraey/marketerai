<?php

namespace App\Services\Import\Adapters;

use App\Services\Import\DTO\DiscoveredProduct;
use App\Services\Import\DTO\DiscoveryResult;
use App\Services\Import\Support\Fetcher;
use Illuminate\Support\Str;

/**
 * شوبيفاي تنشر /products.json علناً لكل متجر.
 * الطلب الواحد يعطي 250 منتجاً ببياناتها كاملة، فلا نحتاج فتح صفحة لكل منتج.
 */
class ShopifyAdapter implements StoreAdapter
{
    protected const PAGE_SIZE = 250;
    protected const MAX_PAGES = 8;

    public function __construct(protected Fetcher $fetcher) {}

    public function name(): string
    {
        return 'شوبيفاي';
    }

    public function detect(string $origin): bool
    {
        $data = $this->fetcher->getJson($origin.'/products.json?limit=1');

        return is_array($data) && array_key_exists('products', $data);
    }

    public function discover(string $origin, int $cap): DiscoveryResult
    {
        $result = new DiscoveryResult(platform: $this->name(), store: parse_url($origin, PHP_URL_HOST) ?: $origin);

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $data = $this->fetcher->getJson($origin."/products.json?limit=".self::PAGE_SIZE."&page={$page}");
            $products = $data['products'] ?? null;

            if (! is_array($products) || $products === []) {
                break;
            }

            foreach ($products as $raw) {
                $result->total++;

                if (count($result->items) < $cap) {
                    $result->items[] = $this->toProduct($origin, $raw);
                }
            }

            if (count($products) < self::PAGE_SIZE) {
                break;
            }
        }

        $result->truncated = $result->total > count($result->items);

        return $result;
    }

    protected function toProduct(string $origin, array $raw): DiscoveredProduct
    {
        $variant = $raw['variants'][0] ?? [];

        return new DiscoveredProduct(
            url: $origin.'/products/'.($raw['handle'] ?? ''),
            title: Str::limit((string) ($raw['title'] ?? ''), 180, ''),
            externalId: isset($raw['id']) ? (string) $raw['id'] : null,
            summary: $this->plain($raw['body_html'] ?? null),
            price: isset($variant['price']) && is_numeric($variant['price']) ? (float) $variant['price'] : null,
            currency: null, // غير معلنة في هذه النقطة، فنترك عملة العلامة تحكم
            imageUrls: collect($raw['images'] ?? [])->pluck('src')->filter()->take(6)->values()->all(),
            sku: $variant['sku'] ?? null,
            category: $raw['product_type'] ?? null,
            brandName: $raw['vendor'] ?? null,
            // السعر قبل الخصم والتوفر معلنان في المتغيّر نفسه
            compareAtPrice: is_numeric($variant['compare_at_price'] ?? null) ? (float) $variant['compare_at_price'] : null,
            stockStatus: isset($variant['available']) ? ($variant['available'] ? 'in_stock' : 'out_of_stock') : null,
        );
    }

    protected function plain(?string $html): ?string
    {
        if (blank($html)) {
            return null;
        }

        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return $text !== '' ? Str::limit($text, 2000, '') : null;
    }
}
