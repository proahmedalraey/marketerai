<?php

namespace App\Services\Import\Support;

use App\Services\Import\DTO\DiscoveredProduct;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Str;

/**
 * قارئ صفحة منتج واحدة.
 *
 * الترتيب مقصود: JSON-LD أولاً لأنه بيانات مُصرَّح بها لا تخميناً من التصميم،
 * ثم OpenGraph، ثم وسوم الصفحة. سلة وزد وشوبيفاي ووكومرس كلها تُخرج JSON-LD،
 * فهذا المسار وحده يغطي أغلب المتاجر دون محوّل خاص لكل منصة.
 */
class HtmlProductParser
{
    public function parse(string $html, string $url): ?DiscoveredProduct
    {
        $document = $this->load($html);

        if (! $document) {
            return null;
        }

        $xpath = new DOMXPath($document);

        $product = new DiscoveredProduct(url: $url);

        $isProduct = $this->applyJsonLd($product, $xpath);
        $this->applyMetaTags($product, $xpath);

        /*
         * حارس ثانٍ بعد ترشيح الروابط.
         * صفحات المتجر (سياسة الشحن، طلبات الجملة) لها عنوان وصورة ووصف،
         * فتبدو منتجاً لأي قارئ يعتمد على الوسوم العامة وحدها.
         * الفرق الحقيقي: المنتج يعلن نفسه Product أو og:type=product أو يحمل سعراً.
         */
        $ogType = strtolower((string) $this->metaValue($xpath, 'og:type'));

        $isProduct = $isProduct
            || str_contains($ogType, 'product')
            || $product->price !== null;

        if (! $isProduct) {
            return null;
        }

        $this->applyFallbacks($product, $xpath);
        $this->detectInstallments($product, $html);

        $product->imageUrls = $this->withoutLogos(
            $this->absolutize($product->imageUrls, $url),
            $this->absolutize($this->logoUrls($xpath), $url),
        );
        $product->externalId ??= $this->idFromUrl($url);

        return $product->isUsable() ? $product : null;
    }

    // ==================================================================

    protected function load(string $html): ?DOMDocument
    {
        if (blank(trim($html))) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);

        $document = new DOMDocument;
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $document : null;
    }

    /** @return bool هل وُجدت عقدة Product صريحة؟ */
    protected function applyJsonLd(DiscoveredProduct $product, DOMXPath $xpath): bool
    {
        $found = false;

        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $node) {
            $decoded = json_decode(trim($node->textContent), true);

            if (! is_array($decoded)) {
                continue;
            }

            $node = $this->findProductNode($decoded);

            if (! $node) {
                continue;
            }

            $product->title = $product->title ?: $this->text($node['name'] ?? '');
            $product->summary ??= $this->text($node['description'] ?? null);
            $product->sku ??= $this->cleanSku($node['sku'] ?? $node['mpn'] ?? null);
            $product->externalId ??= $this->scalar($node['productID'] ?? $node['@id'] ?? null);
            $product->category ??= $this->text($node['category'] ?? null);
            $product->brandName ??= $this->text(is_array($node['brand'] ?? null) ? ($node['brand']['name'] ?? null) : ($node['brand'] ?? null));

            foreach ((array) ($node['image'] ?? []) as $image) {
                $value = is_array($image) ? ($image['url'] ?? null) : $image;

                if (is_string($value) && filled($value)) {
                    $product->imageUrls[] = $value;
                }
            }

            $offer = $this->firstOffer($node['offers'] ?? null);

            if ($offer) {
                $price = $offer['price'] ?? $offer['lowPrice'] ?? null;

                if (is_numeric($price)) {
                    $product->price ??= (float) $price;
                }

                $product->currency ??= $this->scalar($offer['priceCurrency'] ?? null);
                $product->stockStatus ??= $this->stockStatus($offer['availability'] ?? null);
                $product->compareAtPrice ??= $this->listPrice($offer['priceSpecification'] ?? null);
                $product->saleEndsAt ??= $this->date($offer['priceValidUntil'] ?? null);
            }

            $rating = $node['aggregateRating'] ?? null;

            if (is_array($rating) && is_numeric($rating['ratingValue'] ?? null)) {
                $count = $rating['reviewCount'] ?? $rating['ratingCount'] ?? null;

                $product->ratingValue ??= round((float) $rating['ratingValue'], 2);
                $product->ratingCount ??= is_numeric($count) ? (int) $count : null;
            }

            $found = true;

            if (filled($product->title)) {
                return true;
            }
        }

        return $found;
    }

    /** JSON-LD قد يأتي كعنصر، أو مصفوفة، أو داخل @graph. */
    protected function findProductNode(array $data): ?array
    {
        $candidates = isset($data['@graph']) && is_array($data['@graph'])
            ? $data['@graph']
            : (array_is_list($data) ? $data : [$data]);

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $types = (array) ($candidate['@type'] ?? []);

            foreach ($types as $type) {
                if (is_string($type) && Str::contains(strtolower($type), 'product')) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    protected function firstOffer(mixed $offers): ?array
    {
        if (! is_array($offers)) {
            return null;
        }

        if (isset($offers['price']) || isset($offers['lowPrice']) || isset($offers['priceCurrency'])) {
            return $offers;
        }

        foreach ($offers as $offer) {
            if (is_array($offer)) {
                return $offer;
            }
        }

        return null;
    }

    protected function metaValue(DOMXPath $xpath, string $key): ?string
    {
        return $this->text(
            $xpath->query("//meta[@property='{$key}' or @name='{$key}']/@content")?->item(0)?->nodeValue
        );
    }

    protected function applyMetaTags(DiscoveredProduct $product, DOMXPath $xpath): void
    {
        $meta = fn (string $key) => $this->metaValue($xpath, $key);

        $product->title = $product->title ?: (string) $meta('og:title');
        $product->summary ??= $meta('og:description') ?: $meta('description');

        if ($image = $meta('og:image')) {
            $product->imageUrls[] = $image;
        }

        if ($product->price === null && is_numeric($price = $meta('product:price:amount'))) {
            $product->price = (float) $price;
        }

        $product->currency ??= $meta('product:price:currency');

        // بعض المنصات تعلن السعر الأصلي في وسم مستقل
        if ($product->compareAtPrice === null && is_numeric($original = $meta('product:original_price:amount'))) {
            $product->compareAtPrice = (float) $original;
        }
    }

    protected function applyFallbacks(DiscoveredProduct $product, DOMXPath $xpath): void
    {
        if (blank($product->title)) {
            $product->title = (string) $this->text($xpath->query('//title')?->item(0)?->textContent);
        }

        if (blank($product->title)) {
            $product->title = (string) $this->text($xpath->query('//h1')?->item(0)?->textContent);
        }

        $product->title = Str::limit($product->title, 180, '');
        $product->summary = $product->summary ? Str::limit($product->summary, 2000, '') : null;
        $product->imageUrls = array_values(array_unique(array_filter($product->imageUrls)));
    }

    /** @param array<int, string> $urls */
    protected function absolutize(array $urls, string $base): array
    {
        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');

        return collect($urls)
            ->map(function (string $url) use ($origin) {
                if (Str::startsWith($url, ['http://', 'https://'])) {
                    return $url;
                }

                return Str::startsWith($url, '//')
                    ? 'https:'.$url
                    : $origin.'/'.ltrim($url, '/');
            })
            ->unique()
            ->take(6)
            ->values()
            ->all();
    }

    // ================================================================
    //  حقائق البيع (البند 2.4 في docs/sahal-audit-plan.md)
    // ================================================================

    /** «https://schema.org/InStock» و«InStock» و«OutOfStock» ← حالة المخزون عندنا. */
    protected function stockStatus(mixed $availability): ?string
    {
        $value = strtolower(basename((string) $this->scalar($availability)));

        return match (true) {
            $value === '' => null,
            str_contains($value, 'outofstock'), str_contains($value, 'soldout'), str_contains($value, 'discontinued') => 'out_of_stock',
            str_contains($value, 'preorder'), str_contains($value, 'backorder'), str_contains($value, 'presale') => 'preorder',
            str_contains($value, 'instock'), str_contains($value, 'limitedavailability'), str_contains($value, 'onlineonly') => 'in_stock',
            default => null,
        };
    }

    /** السعر قبل الخصم: مواصفة سعر من نوع ListPrice أو StrikethroughPrice. */
    protected function listPrice(mixed $specifications): ?float
    {
        if (! is_array($specifications)) {
            return null;
        }

        $specifications = array_is_list($specifications) ? $specifications : [$specifications];

        foreach ($specifications as $spec) {
            $type = strtolower((string) (is_array($spec) ? ($spec['priceType'] ?? '') : ''));

            if ((str_contains($type, 'listprice') || str_contains($type, 'strikethrough')) && is_numeric($spec['price'] ?? null)) {
                return (float) $spec['price'];
            }
        }

        return null;
    }

    protected function date(mixed $value): ?string
    {
        $value = $this->scalar($value);

        return $value && preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $m) ? $m[0] : null;
    }

    /**
     * ويدجت التقسيط في الصفحة يعني أن المتجر يقسّط عبره. الدفعات أربع
     * افتراضاً (ما تعرضه صفحات سلة: «4 دفعات × 18.75»)، ويعدّلها التاجر.
     */
    protected function detectInstallments(DiscoveredProduct $product, string $html): void
    {
        foreach (['tabby', 'tamara'] as $provider) {
            if (preg_match('/\b'.$provider.'\b/i', $html)) {
                $product->installments[] = ['provider' => $provider, 'count' => 4];
            }
        }
    }

    // ================================================================
    //  الصور: الشعار ليس صورة منتج
    // ================================================================

    /**
     * شعار المتجر كما تعلنه الصفحة: logo في JSON-LD، وأيقونات الموقع.
     * صورة من ثلاث في تدقيق المنافس (P3) كانت الشعار، فصارت «مرجعاً بصرياً» للمنتج.
     *
     * @return array<int, string>
     */
    protected function logoUrls(DOMXPath $xpath): array
    {
        $logos = [];

        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $node) {
            $this->collectLogos(json_decode(trim($node->textContent), true), $logos);
        }

        foreach ($xpath->query('//link[contains(@rel, "icon")]/@href') ?: [] as $href) {
            $logos[] = $href->nodeValue;
        }

        return array_values(array_filter($logos));
    }

    /**
     * كل قيمة logo في العقدة وما تحتها: نصاً مباشراً أو كائن ImageObject ({"url": "…"}).
     *
     * @param  array<int, string>  $logos
     */
    protected function collectLogos(mixed $node, array &$logos): void
    {
        if (! is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            if ($key === 'logo') {
                $url = is_array($value) ? ($value['url'] ?? null) : $value;

                if (is_string($url) && $url !== '') {
                    $logos[] = $url;
                }

                continue;
            }

            $this->collectLogos($value, $logos);
        }
    }

    /**
     * @param  array<int, string>  $images
     * @param  array<int, string>  $logos
     * @return array<int, string>
     */
    protected function withoutLogos(array $images, array $logos): array
    {
        return array_values(array_filter($images, fn (string $url) => ! in_array($url, $logos, true)
            && ! preg_match('#/[^/?]*logo[^/?]*(\?|$)|/logos?/#i', $url)));
    }

    /** سلة وزد يضعان معرّف المنتج في آخر المسار مثل p1031BB614. */
    protected function idFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $last = trim(basename($path));

        return preg_match('/^p?[0-9A-Za-z]{4,}$/', $last) ? Str::limit($last, 100, '') : null;
    }

    protected function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return $clean !== '' ? $clean : null;
    }

    /**
     * سلة تضع مسار الرابط كاملاً في حقل sku، فيتسرب إلى ورقة المرجع كضجيج.
     * الرمز الحقيقي قصير وبلا شرطات مائلة ولا مسافات.
     */
    protected function cleanSku(mixed $value): ?string
    {
        $sku = $this->scalar($value);

        if (blank($sku) || mb_strlen($sku) > 48 || preg_match('#[/\\\s]#u', $sku)) {
            return null;
        }

        return $sku;
    }

    protected function scalar(mixed $value): ?string
    {
        return is_scalar($value) ? trim((string) $value) : null;
    }
}
