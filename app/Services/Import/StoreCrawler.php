<?php

namespace App\Services\Import;

use App\Services\Import\Adapters\ShopifyAdapter;
use App\Services\Import\Adapters\SitemapAdapter;
use App\Services\Import\Adapters\StoreAdapter;
use App\Services\Import\Adapters\WooCommerceAdapter;
use App\Services\Import\DTO\DiscoveredProduct;
use App\Services\Import\DTO\DiscoveryResult;
use App\Services\Import\Support\Fetcher;
use App\Services\Import\Support\HtmlProductParser;

/**
 * الاستراتيجية الهجينة: نجرّب نقطة المنصة أولاً لأنها أدق وأسرع،
 * وإن لم نتعرّف على منصة نسقط إلى خريطة الموقع التي تعمل مع أي متجر.
 */
class StoreCrawler
{
    /** سقف المرشّحين المعروضين. المتاجر الكبيرة تتجاوز الألفين، والمستخدم لا يراجعها كلها. */
    public const CANDIDATE_CAP = 250;

    /** كم صفحة نقرأ في الدفعة الواحدة. كل صفحة طلب شبكة مستقل. */
    public const READ_BATCH = 12;

    public function __construct(
        protected Fetcher $fetcher,
        protected HtmlProductParser $parser,
    ) {}

    /** @return array<int, StoreAdapter> */
    protected function adapters(): array
    {
        return [
            new ShopifyAdapter($this->fetcher),
            new WooCommerceAdapter($this->fetcher),
            new SitemapAdapter($this->fetcher),
        ];
    }

    public function discover(string $storeUrl): DiscoveryResult
    {
        $origin = $this->fetcher->normalizeStoreUrl($storeUrl);
        $this->fetcher->assertPublicUrl($origin);

        foreach ($this->adapters() as $adapter) {
            if ($adapter->detect($origin)) {
                return $adapter->discover($origin, self::CANDIDATE_CAP);
            }
        }

        return new DiscoveryResult(platform: 'غير معروف', store: parse_url($origin, PHP_URL_HOST) ?: $origin);
    }

    /**
     * يقرأ دفعة من الروابط المنتظرة وينقلها إلى المقروء.
     *
     * الرابط الذي يفشل يُسقط نهائياً ولا يُعاد إلى الانتظار،
     * وإلا علقت الواجهة على «متبقٍ 3» لا تنقص أبداً.
     */
    public function readBatch(DiscoveryResult $result, int $limit = self::READ_BATCH): DiscoveryResult
    {
        $batch = array_slice($result->pendingUrls, 0, $limit);
        $result->pendingUrls = array_slice($result->pendingUrls, count($batch));

        foreach ($batch as $url) {
            if ($product = $this->read($url)) {
                $result->items[] = $product;
            }
        }

        return $result;
    }

    public function read(string $url): ?DiscoveredProduct
    {
        try {
            $this->fetcher->assertPublicUrl($url);
        } catch (\Throwable) {
            return null;
        }

        $html = $this->fetcher->get($url);

        return blank($html) ? null : $this->parser->parse($html, $url);
    }
}
