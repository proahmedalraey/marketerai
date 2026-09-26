<?php

namespace App\Services\AI\DTO;

class ImageRequest
{
    public function __construct(
        public string $prompt,
        public string $aspectRatio = '1:1',
        public string $quality = 'standard_1k',
        public int $count = 1,
        /** مسار محلي أو رابط صورة مرجعية للمنتج (image-to-image) */
        public ?string $referenceImage = null,
        public ?string $seed = null,
        public string $operation = 'image.standard_1k',
        /** معرّف نموذج محدد لهذا الطلب (OpenRouter فقط الآن). null = نموذج المزود الافتراضي من الإعدادات */
        public ?string $model = null,
    ) {}

    public function dimensions(): array
    {
        $ratio = config("ai.aspect_ratios.{$this->aspectRatio}", ['w' => 1024, 'h' => 1024]);

        // استوديو الصور الجديد يرسل مفاتيح جودة مركّبة (مثل "1k_medium") غير موجودة
        // في quality_tiers القديمة (تبقى لصفحة الكاروسيل) — نجرّب المصفوفة الجديدة كبديل.
        $target = (int) (
            config("ai.quality_tiers.{$this->quality}.size")
            ?? config("ai.image_quality_matrix.{$this->quality}.size", 1024)
        );
        $scale = $target / max($ratio['w'], $ratio['h']);

        return [
            'width' => (int) round($ratio['w'] * $scale),
            'height' => (int) round($ratio['h'] * $scale),
        ];
    }
}
