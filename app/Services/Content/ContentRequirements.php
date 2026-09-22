<?php

namespace App\Services\Content;

use App\Models\Brand;
use App\Models\Product;
use App\Models\Testimonial;

/**
 * ما يحتاجه قالب أو زاوية من بيانات حقيقية قبل أن يُستخدم.
 *
 * القالب الذي يطلب ما لا نملكه يدفع النموذج لاختراعه: «دليل اجتماعي»
 * بلا تجربة عميل حقيقية ينتهي بعميل وهمي. فيُعطَّل مع سبب يفهمه التاجر.
 */
class ContentRequirements
{
    public const REASONS = [
        'price' => 'يحتاج سعراً مسجّلاً للمنتج.',
        'testimonials' => 'يحتاج تجربة عميل حقيقية: أضفها من صفحة «حقائق البيع».',
    ];

    /**
     * سبب أول متطلب غير متوفر، أو null إن توفرت كلها.
     *
     * @param  array<int, string>  $requires
     */
    public static function unmet(array $requires, Brand $brand, ?Product $product): ?string
    {
        foreach ($requires as $requirement) {
            if (! self::met($requirement, $brand, $product)) {
                return self::REASONS[$requirement] ?? "يحتاج بيانات غير متوفرة ({$requirement}).";
            }
        }

        return null;
    }

    public static function met(string $requirement, Brand $brand, ?Product $product): bool
    {
        return match ($requirement) {
            'price' => $product?->price !== null,
            // بلا منتج (محتوى عام عن العلامة): أي تجربة للعلامة. مع منتج: تجاربه أو تجارب المتجر العامة
            'testimonials' => $brand->id !== null && Testimonial::forBrand($brand)
                ->when($product?->id, fn ($q) => $q->where(fn ($q) => $q
                    ->whereNull('product_id')->orWhere('product_id', $product->id)))
                ->exists(),
            default => false,
        };
    }

    /**
     * زوايا نسخ الدفعة المتاحة، بترتيب الإعدادات.
     *
     * @return array<int, string>
     */
    public static function anglesFor(Brand $brand, ?Product $product): array
    {
        return collect(config('content.angles', []))
            ->filter(fn ($angle) => self::unmet($angle['requires'] ?? [], $brand, $product) === null)
            ->keys()
            ->all();
    }
}
