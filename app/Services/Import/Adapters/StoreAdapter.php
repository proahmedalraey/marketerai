<?php

namespace App\Services\Import\Adapters;

use App\Services\Import\DTO\DiscoveryResult;

interface StoreAdapter
{
    /** اسم المنصة كما يُعرض للمستخدم. */
    public function name(): string;

    /** هل يتعرّف هذا المحوّل على المتجر؟ يُستدعى مرة واحدة بطلب خفيف. */
    public function detect(string $origin): bool;

    /** اكتشاف المنتجات، بحد أقصى $cap مرشّحاً. */
    public function discover(string $origin, int $cap): DiscoveryResult;
}
