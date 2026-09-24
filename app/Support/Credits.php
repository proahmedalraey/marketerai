<?php

namespace App\Support;

/**
 * تنسيق عرض النقاط في الواجهة.
 *
 * الرصيد والتكلفة كسريان الآن (مصفوفة دقة×جودة استوديو الصور تبدأ من 0.5)،
 * لكن أغلب القيم لا تزال صحيحة — نُظهر الخانة العشرية فقط حين تحمل معنى
 * بدل "12.0" في كل مكان.
 */
final class Credits
{
    public static function format(float|int|string $value): string
    {
        $value = round((float) $value, 1);

        return $value == floor($value)
            ? number_format($value, 0)
            : number_format($value, 1);
    }
}
