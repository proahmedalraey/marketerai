<?php

namespace App\Services\Import\Adapters;

use App\Services\Import\DTO\DiscoveredProduct;
use App\Services\Import\DTO\DiscoveryResult;
use App\Services\Import\Support\Fetcher;
use Illuminate\Support\Str;

/**
 * ووكومرس: واجهة المتجر (Store API) عامة ولا تحتاج مفاتيح،
 * بخلاف واجهة الإدارة wc/v3 التي تتطلب توثيقاً.
 */
class WooCommerceAdapter implements StoreAdapter
{
    protected const PAGE_SIZE = 100;
    protected const MAX_PAGES = 10;
    protected const ENDPOINT = '/wp-json/wc/store/v1/products';

    public function __construct(protected Fetcher $fetcher) {}

    public function name(): string
    {
        return 'ووكومرس';
    }

    public function detect(string $origin): bool
    {
        $data = $this->fetcher->getJson($origin.self::ENDPOINT.'?per_page=1');

        return is_array($data) && array_is_list($data);
    }

    public function discover(string $origin, int $cap): DiscoveryResult
    {
        $result = new DiscoveryResult(platform: $this->name(), store: parse_url($origin, PHP_URL_HOST) ?: $origin);

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $products = $this->fetcher->getJson($origin.self::ENDPOINT.'?per_page='.self::PAGE_SIZE."&page={$page}");

            if (! is_array($products) || $products === []) {
                break;
            }

            foreach ($products as $raw) {
                if (! is_array($raw)) {
                    continue;
                }

                $result->total++;

                if (count($result->items) < $cap) {
                    $result->items[] = $this->toProduct($raw);
                }
            }

            if (count($products) < self::PAGE_SIZE) {
                break;
            }
        }

        $result->truncated = $result->total > count($result->items);

        return $result;
    }

    protected function toProduct(array $raw): DiscoveredProduct
    {
        // الأسعار هنا أعداد صحيحة بوحدة أصغر، وعدد المنازل يأتي في قاموس منفصل
        $prices = $raw['prices'] ?? [];
        $minor = $prices['price'] ?? null;
        $decimals = (int) ($prices['currency_minor_unit'] ?? 2);

        return new DiscoveredProduct(
            url: (string) ($raw['permalink'] ?? ''),
            title: Str::limit($this->plain($raw['name'] ?? '') ?? '', 180, ''),
            externalId: isset($raw['id']) ? (string) $raw['id'] : null,
            summary: $this->plain($raw['short_description'] ?? null) ?: $this->plain($raw['description'] ?? null),
            price: is_numeric($minor) ? round(((float) $minor) / (10 ** $decimals), 2) : null,
            currency: $prices['currency_code'] ?? null,
            imageUrls: collect($raw['images'] ?? [])->pluck('src')->filter()->take(6)->values()->all(),
            sku: $raw['sku'] ?? null,
            category: collect($raw['categories'] ?? [])->pluck('name')->filter()->first(),
            brandName: null,
            compareAtPrice: is_numeric($regular = $prices['regular_price'] ?? null) ? round(((float) $regular) / (10 ** $decimals), 2) : null,
            stockStatus: isset($raw['is_in_stock']) ? ($raw['is_in_stock'] ? 'in_stock' : 'out_of_stock') : null,
            ratingValue: is_numeric($raw['average_rating'] ?? null) && (int) ($raw['review_count'] ?? 0) > 0 ? (float) $raw['average_rating'] : null,
            ratingCount: (int) ($raw['review_count'] ?? 0) ?: null,
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
