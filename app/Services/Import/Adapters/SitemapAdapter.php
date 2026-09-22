<?php

namespace App\Services\Import\Adapters;

use App\Services\Import\DTO\DiscoveryResult;
use App\Services\Import\Support\Fetcher;
use Illuminate\Support\Str;

/**
 * المسار العام: خريطة الموقع.
 *
 * يغطي سلة وزد وأغلب المتاجر التي لا تنشر واجهة منتجات عامة.
 * لا يقرأ الصفحات هنا — يجمع الروابط فقط، لأن قراءة ألفَي صفحة
 * في طلب واحد تُسقط المهمة، والمستخدم لا يحتاج إلا أول دفعة ليقرر.
 */
class SitemapAdapter implements StoreAdapter
{
    protected const CANDIDATES = ['/sitemap.xml', '/sitemap_index.xml', '/sitemap-index.xml', '/sitemap/sitemap.xml'];
    protected const MAX_CHILD_SITEMAPS = 6;

    public function __construct(protected Fetcher $fetcher) {}

    public function name(): string
    {
        return 'خريطة الموقع';
    }

    /** آخر محوّل في السلسلة، فلا يرفض أحداً. */
    public function detect(string $origin): bool
    {
        return true;
    }

    public function discover(string $origin, int $cap): DiscoveryResult
    {
        $result = new DiscoveryResult(platform: $this->name(), store: parse_url($origin, PHP_URL_HOST) ?: $origin);

        [$urls, $fromProductSitemap] = $this->collectUrls($origin);

        // خريطة مخصصة للمنتجات لا تحتاج ترشيحاً، والعامة تحتاجه
        $products = $fromProductSitemap
            ? $urls
            : array_values(array_filter($urls, fn ($url) => $this->looksLikeProduct($url)));

        // لا نمط واضح: نعيد كل شيء بدل أن نعيد لا شيء، والمستخدم يختار
        if ($products === [] && $urls !== []) {
            $products = $urls;
        }

        $products = $this->mergeLanguageVariants(array_unique($products));

        $result->total = count($products);
        $result->pendingUrls = array_slice($products, 0, $cap);
        $result->truncated = $result->total > count($result->pendingUrls);

        return $result;
    }

    // ==================================================================

    /** @return array{0: array<int, string>, 1: bool} */
    protected function collectUrls(string $origin): array
    {
        foreach ($this->sitemapCandidates($origin) as $sitemapUrl) {
            $xml = $this->fetcher->get($sitemapUrl);

            if (blank($xml)) {
                continue;
            }

            $locations = $this->locations($xml);

            if ($locations === []) {
                continue;
            }

            // فهرس خرائط لا خريطة: ننزل طبقة، ونفضّل ما اسمه يدل على المنتجات
            if ($this->isIndex($xml)) {
                $children = collect($locations)
                    ->sortByDesc(fn ($url) => $this->mentionsProducts($url) ? 1 : 0)
                    ->take(self::MAX_CHILD_SITEMAPS)
                    ->values();

                $named = $children->first(fn ($url) => $this->mentionsProducts($url));

                $urls = [];

                foreach ($children as $child) {
                    $childXml = $this->fetcher->get($child);

                    if (filled($childXml)) {
                        $urls = array_merge($urls, $this->locations($childXml));
                    }
                }

                if ($urls !== []) {
                    return [$urls, $named !== null];
                }

                continue;
            }

            return [$locations, $this->mentionsProducts($sitemapUrl)];
        }

        return [[], false];
    }

    /** @return array<int, string> */
    protected function sitemapCandidates(string $origin): array
    {
        $candidates = array_map(fn ($path) => $origin.$path, self::CANDIDATES);

        // robots.txt هو المكان الرسمي للإعلان عن الخريطة حين لا تكون في مكانها المعتاد
        if ($robots = $this->fetcher->get($origin.'/robots.txt')) {
            preg_match_all('/^\s*sitemap:\s*(\S+)/im', $robots, $matches);
            $candidates = array_merge($matches[1] ?? [], $candidates);
        }

        return array_values(array_unique($candidates));
    }

    protected function isIndex(string $xml): bool
    {
        return Str::contains($xml, '<sitemapindex');
    }

    /** @return array<int, string> */
    protected function locations(string $xml): array
    {
        preg_match_all('#<loc>\s*(.*?)\s*</loc>#is', $xml, $matches);

        return collect($matches[1] ?? [])
            ->map(fn ($url) => html_entity_decode(trim($url), ENT_QUOTES | ENT_XML1, 'UTF-8'))
            ->filter(fn ($url) => Str::startsWith($url, ['http://', 'https://']))
            ->values()
            ->all();
    }

    /**
     * هل يدل اسم الخريطة على أنها للمنتجات؟
     * لا نطابق «item» كنص حر لأن كلمة sitemap نفسها تحتويها.
     */
    protected function mentionsProducts(string $url): bool
    {
        return (bool) preg_match('#(product|[-_/]items?[-_./])#i', $url);
    }

    protected function looksLikeProduct(string $url): bool
    {
        $path = strtolower(parse_url($url, PHP_URL_PATH) ?: '');

        if ($path === '' || $path === '/') {
            return false;
        }

        // في سلة: /p/<slug> صفحة ثابتة، و/<slug>/c<رقم> تصنيف.
        // الفرق بينهما وبين المنتج شرطة مائلة واحدة، فنستبعدهما صراحة.
        if (preg_match('#/(p|c)/#', $path) || preg_match('#/c\d+/?$#', $path)) {
            return false;
        }

        // المنتج: المعرّف رقمي في آخر المسار مثل /p1268016610
        if (preg_match('#/p\d{3,}/?$#', $path)) {
            return true;
        }

        return Str::contains($path, ['/product/', '/products/', '/item/', '/dp/']);
    }

    /**
     * المتاجر متعددة اللغات تنشر المنتج الواحد برابط لكل لغة،
     * والمعرّف في آخر المسار واحد. بلا دمج تظهر النسختان صفَّين،
     * ويحمل الصفّان المفتاح نفسه فيتحرك المربعان معاً عند التحديد.
     *
     * @param  array<int, string>  $urls
     * @return array<int, string>
     */
    protected function mergeLanguageVariants(array $urls): array
    {
        $locale = app()->getLocale();
        $byId = [];

        foreach ($urls as $url) {
            $path = parse_url($url, PHP_URL_PATH) ?: '';

            if (! preg_match('#/(p\d{3,})/?$#', $path, $matches)) {
                $byId[$url] = $url;   // لا معرّف: يبقى كما هو

                continue;
            }

            $id = $matches[1];
            $preferred = str_starts_with(ltrim($path, '/'), $locale.'/');

            // نفضّل نسخة لغة الواجهة، وإلا نُبقي أول ما وصل
            if (! isset($byId[$id]) || $preferred) {
                $byId[$id] = $url;
            }
        }

        return array_values($byId);
    }
}
