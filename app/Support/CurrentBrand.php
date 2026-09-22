<?php

namespace App\Support;

use App\Models\Brand;

/**
 * البراند الحالي للطلب أو للمهمة في الطابور.
 *
 * في الويب يُضبط من الوسيط، وفي الطوابير يُضبط في بداية كل Job،
 * لأن العامل لا يملك جلسة مستخدم.
 */
class CurrentBrand
{
    protected static ?Brand $brand = null;

    public static function set(?Brand $brand): void
    {
        static::$brand = $brand;
    }

    public static function get(): ?Brand
    {
        return static::$brand;
    }

    public static function id(): ?int
    {
        return static::$brand?->id;
    }

    public static function clear(): void
    {
        static::$brand = null;
    }

    /**
     * تنفيذ كتلة كود ضمن سياق براند محدد ثم استعادة السياق السابق.
     */
    public static function run(Brand $brand, callable $callback): mixed
    {
        $previous = static::$brand;
        static::$brand = $brand;

        try {
            return $callback();
        } finally {
            static::$brand = $previous;
        }
    }
}
