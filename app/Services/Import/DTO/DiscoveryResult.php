<?php

namespace App\Services\Import\DTO;

/**
 * حصيلة مسح متجر.
 *
 * نفصل «المقروء» عن «المنتظر» لأن المسارين مختلفان في الكلفة:
 * منصات مثل شوبيفاي تعطي بيانات المنتج كاملة في طلب واحد لكل 250 منتجاً،
 * بينما المتاجر العامة تعطي روابط فقط، وكل رابط يكلّف طلباً منفصلاً.
 */
class DiscoveryResult
{
    public function __construct(
        public string $platform,
        public string $store,
        public int $total = 0,
        /** @var array<int, DiscoveredProduct> */
        public array $items = [],
        /** @var array<int, string> */
        public array $pendingUrls = [],
        public bool $truncated = false,
    ) {}

    public function toArray(): array
    {
        return [
            'platform' => $this->platform,
            'store' => $this->store,
            'total' => $this->total,
            'truncated' => $this->truncated,
            'items' => array_map(fn (DiscoveredProduct $p) => $p->toArray(), $this->items),
            'pending_urls' => array_values($this->pendingUrls),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            platform: $data['platform'] ?? 'sitemap',
            store: $data['store'] ?? '',
            total: (int) ($data['total'] ?? 0),
            items: array_map(fn ($item) => DiscoveredProduct::fromArray($item), $data['items'] ?? []),
            pendingUrls: $data['pending_urls'] ?? [],
            truncated: (bool) ($data['truncated'] ?? false),
        );
    }
}
