<?php

namespace App\Services\Import\DTO;

use Illuminate\Support\Str;

/**
 * منتج كما قرأناه من المتجر، قبل أن يصير سجلاً عندنا.
 *
 * نمر بهذا الشكل الوسيط لسببين: المحوّلات المختلفة تُخرج بنى مختلفة جداً،
 * والمستخدم يراجع ويختار قبل الحفظ، فنحتاج شكلاً قابلاً للتسلسل في نتيجة المهمة.
 */
class DiscoveredProduct
{
    public function __construct(
        public string $url,
        public string $title = '',
        public ?string $externalId = null,
        public ?string $summary = null,
        public ?float $price = null,
        public ?string $currency = null,
        /** @var array<int, string> */
        public array $imageUrls = [],
        public ?string $sku = null,
        public ?string $category = null,
        public ?string $brandName = null,
        // حقائق البيع كما تعلنها صفحة المنتج (البند 2.4 في خطة التدقيق)
        public ?float $compareAtPrice = null,
        public ?string $saleEndsAt = null,
        public ?string $stockStatus = null,
        public ?float $ratingValue = null,
        public ?int $ratingCount = null,
        /** @var array<int, array{provider: string, count: int}> */
        public array $installments = [],
    ) {}

    /**
     * حقول حقائق البيع بأسماء أعمدة المنتج. الفارغ لا يُرسل،
     * فلا يمسح إعادةُ الاستيراد ما أدخله التاجر بنفسه.
     *
     * @return array<string, mixed>
     */
    public function salesFacts(): array
    {
        $compare = $this->compareAtPrice !== null && $this->price !== null && $this->compareAtPrice > $this->price;

        return array_filter([
            'compare_at_price' => $compare ? $this->compareAtPrice : null,
            'sale_ends_at' => $compare ? $this->saleEndsAt : null,
            'stock_status' => $this->stockStatus,
            'rating_value' => $this->ratingCount ? $this->ratingValue : null,
            'rating_count' => $this->ratingValue !== null ? $this->ratingCount : null,
            'installments' => $this->installments ?: null,
        ], fn ($value) => $value !== null);
    }

    public function isUsable(): bool
    {
        return filled($this->title);
    }

    /** مفتاح ثابت للمنتج داخل المتجر، يمنع تكرار الاستيراد. */
    public function key(): string
    {
        return $this->externalId
            ?: $this->sku
            ?: Str::limit(md5($this->url), 32, '');
    }

    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'title' => $this->title,
            'external_id' => $this->externalId,
            'summary' => $this->summary,
            'price' => $this->price,
            'currency' => $this->currency,
            'image_urls' => array_values(array_slice($this->imageUrls, 0, 6)),
            'sku' => $this->sku,
            'category' => $this->category,
            'brand_name' => $this->brandName,
            'compare_at_price' => $this->compareAtPrice,
            'sale_ends_at' => $this->saleEndsAt,
            'stock_status' => $this->stockStatus,
            'rating_value' => $this->ratingValue,
            'rating_count' => $this->ratingCount,
            'installments' => $this->installments,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            url: (string) ($data['url'] ?? ''),
            title: (string) ($data['title'] ?? ''),
            externalId: $data['external_id'] ?? null,
            summary: $data['summary'] ?? null,
            price: isset($data['price']) ? (float) $data['price'] : null,
            currency: $data['currency'] ?? null,
            imageUrls: (array) ($data['image_urls'] ?? []),
            sku: $data['sku'] ?? null,
            category: $data['category'] ?? null,
            brandName: $data['brand_name'] ?? null,
            compareAtPrice: isset($data['compare_at_price']) ? (float) $data['compare_at_price'] : null,
            saleEndsAt: $data['sale_ends_at'] ?? null,
            stockStatus: $data['stock_status'] ?? null,
            ratingValue: isset($data['rating_value']) ? (float) $data['rating_value'] : null,
            ratingCount: isset($data['rating_count']) ? (int) $data['rating_count'] : null,
            installments: (array) ($data['installments'] ?? []),
        );
    }
}
