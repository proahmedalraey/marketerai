<?php

namespace App\Services\Content;

use App\Support\Arabic\ArabicText;

/**
 * دفتر الحقائق: كل ما يجوز أن يذكره المحتوى.
 *
 * يُبنى من نفس الطبقات التي قرأها النموذج (العلامة، إجابات التاجر، المنتج،
 * المناسبة، تعليماته الإضافية)، فما رآه النموذج هو بالضبط ما يُسمح له بذكره.
 * رقم أو كود أو خدمة في المخرج لا أصل لها هنا = اختلاق.
 */
final class ContentFacts
{
    /** @var array<int, string>|null */
    protected ?array $numbers = null;

    /** الفحص يسأل عن نفس الفئة لكل شريحة: الجواب لا يتغير. @var array<string, bool> */
    protected array $mentioned = [];

    /**
     * @param  array<int, string>  $testimonials  تجارب عملاء حقيقية أدخلها التاجر (البند 2.11)
     */
    public function __construct(
        public readonly string $text,
        public readonly array $testimonials = [],
    ) {}

    /** هل ذكر التاجر هذه الكلمة (بأي تصريف)؟ */
    public function mentions(string $word, bool $stem = true): bool
    {
        return $this->mentioned[($stem ? 's:' : 'l:').$word] ??= ArabicText::contains($this->text, $word, $stem);
    }

    /** @param array<int, string> $words */
    public function mentionsAny(array $words): bool
    {
        foreach ($words as $word) {
            if ($this->mentions($word)) {
                return true;
            }
        }

        return false;
    }

    /** نص حرفي كما ورد، بلا اعتبار لحالة الأحرف: للأكواد ورموز المنتجات. */
    public function containsLiteral(string $value): bool
    {
        return mb_stripos(ArabicText::digits($this->text), ArabicText::digits($value)) !== false;
    }

    public function hasNumber(string $number): bool
    {
        return in_array($number, $this->numbers ??= self::numbersIn($this->text), true);
    }

    public function hasTestimonials(): bool
    {
        return $this->testimonials !== [];
    }

    /**
     * الأرقام بصيغة قياسية للمقارنة: «14,200» و«١٤٢٠٠» رقم واحد،
     * و«75.00» في السعر المحفوظ هو «75» في النص.
     *
     * @return array<int, string>
     */
    public static function numbersIn(string $text): array
    {
        preg_match_all('/\d+(?:[.,]\d+)*/u', ArabicText::digits($text), $m);

        return array_values(array_unique(array_map([self::class, 'canonical'], $m[0])));
    }

    public static function canonical(string $number): string
    {
        // فاصلة الآلاف تسبق ثلاث خانات بالضبط؛ غيرها فاصلة عشرية
        $number = preg_replace('/[,](?=\d{3}(?:\D|$))/u', '', $number);
        $number = str_replace(',', '.', $number);

        if (str_contains($number, '.')) {
            $number = rtrim(rtrim($number, '0'), '.');
        }

        return preg_replace('/^0+(?=\d)/', '', $number);
    }
}
