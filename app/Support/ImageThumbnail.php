<?php

namespace App\Support;

/**
 * مصغّرة JPEG لعرض الشبكات.
 *
 * صور النماذج تصل PNG بحجم 1–2 ميغابايت؛ عرضها كما هي في معرض من 60 صورة
 * يعني عشرات الميغابايتات وصوراً فارغة ريثما تنتهي — فالشبكة تعرض مصغّرة
 * (~40KB) والأصل يُفتح فقط في العارض والتنزيل.
 */
final class ImageThumbnail
{
    public const MAX_SIDE = 480;

    /**
     * @return array{contents: string, mime: string, width: int, height: int}|null
     *                                                                             null = لا GD، ملف غير صالح، أو الأصل أصغر أصلاً (لا فائدة من نسخة أخرى)
     */
    public static function make(string $contents, int $maxSide = self::MAX_SIDE): ?array
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            return null;
        }

        $info = @getimagesizefromstring($contents);

        if (! $info || $info[0] < 1 || $info[1] < 1 || max($info[0], $info[1]) <= $maxSide) {
            return null;
        }

        $source = @imagecreatefromstring($contents);

        if (! $source) {
            return null;
        }

        $scale = $maxSide / max($info[0], $info[1]);
        $width = max(1, (int) round($info[0] * $scale));
        $height = max(1, (int) round($info[1] * $scale));

        $thumb = imagecreatetruecolor($width, $height);

        // JPEG لا يحمل شفافية: الخلفية البيضاء تمنع اسوداد الصور الشفافة (PNG المرفوعة)
        imagefill($thumb, 0, 0, imagecolorallocate($thumb, 255, 255, 255));
        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $width, $height, $info[0], $info[1]);
        imagedestroy($source);

        ob_start();
        imagejpeg($thumb, null, 82);
        $jpeg = (string) ob_get_clean();
        imagedestroy($thumb);

        return $jpeg === ''
            ? null
            : ['contents' => $jpeg, 'mime' => 'image/jpeg', 'width' => $width, 'height' => $height];
    }

    /** مسار المصغّرة بجوار الأصل: .../uuid.png → .../uuid.thumb.jpg */
    public static function pathFor(string $originalPath): string
    {
        return preg_replace('/\.[A-Za-z0-9]+$/', '', $originalPath).'.thumb.jpg';
    }
}
