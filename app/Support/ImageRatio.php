<?php

namespace App\Support;

/**
 * قصّ الصورة من منتصفها إلى نسبة أبعاد مطلوبة.
 *
 * النماذج لا تدعم كل النسب (لا نموذج متاح يقبل 9:8 أو 27:16 مثلاً)، فيُطلب منها
 * أقرب نسبة مدعومة ثم تُقصّ النتيجة هنا — فيحصل التاجر على النسبة التي اختارها
 * بدل مربع افتراضي يُسجَّل باسم نسبة لم تُنتَج.
 */
final class ImageRatio
{
    /** نسبة "16:9" أو "9:19.5" → عرض/ارتفاع، أو null إن كانت غير صالحة. */
    public static function number(string $ratio): ?float
    {
        $parts = explode(':', $ratio);

        if (count($parts) !== 2 || (float) $parts[1] <= 0 || (float) $parts[0] <= 0) {
            return null;
        }

        return (float) $parts[0] / (float) $parts[1];
    }

    /**
     * @return array{contents: string, mime: string, width: int, height: int, from: string}|null
     *                                                                                           null = لا حاجة للقصّ (النسبة مطابقة ضمن 2%) أو تعذّر (لا GD، ملف غير صالح)
     */
    public static function crop(string $contents, string $ratio): ?array
    {
        $target = self::number($ratio);

        if ($target === null || ! function_exists('imagecreatefromstring')) {
            return null;
        }

        $info = @getimagesizefromstring($contents);

        if (! $info || $info[0] < 1 || $info[1] < 1) {
            return null;
        }

        [$width, $height] = $info;
        $current = $width / $height;

        if (abs($current - $target) / $target < 0.02) {
            return null;
        }

        if ($current > $target) {
            $newWidth = max(1, (int) round($height * $target));
            $newHeight = $height;
        } else {
            $newWidth = $width;
            $newHeight = max(1, (int) round($width / $target));
        }

        $source = @imagecreatefromstring($contents);

        if (! $source) {
            return null;
        }

        $cropped = imagecrop($source, [
            'x' => intdiv($width - $newWidth, 2),
            'y' => intdiv($height - $newHeight, 2),
            'width' => $newWidth,
            'height' => $newHeight,
        ]);

        imagedestroy($source);

        if (! $cropped) {
            return null;
        }

        $mime = in_array($info['mime'], ['image/jpeg', 'image/webp'], true) ? $info['mime'] : 'image/png';

        // بدونهما يُحفظ PNG الشفاف (إزالة الخلفية) بخلفية سوداء
        imagealphablending($cropped, false);
        imagesavealpha($cropped, true);

        ob_start();
        match ($mime) {
            'image/jpeg' => imagejpeg($cropped, null, 92),
            'image/webp' => imagewebp($cropped, null, 92),
            default => imagepng($cropped),
        };
        $output = (string) ob_get_clean();
        imagedestroy($cropped);

        return [
            'contents' => $output,
            'mime' => $mime,
            'width' => $newWidth,
            'height' => $newHeight,
            'from' => "{$width}x{$height}",
        ];
    }
}
