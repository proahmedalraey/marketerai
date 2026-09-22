<?php

namespace App\Services\Brand;

use App\Support\Arabic\ArabicText;

/**
 * يكشف ما يكتبه التاجر ولن ينفّذه المحتوى، ويقول له ذلك وقت الحفظ.
 *
 * تدقيق المنافس (C7): «اذكر أننا الأفضل في السعودية وأن لدينا شحن مجاني»
 * تجاهلها النموذج مرتين دون أن يعرف التاجر. هنا لا تجاهل صامت:
 *   • المبالغة المطلقة والادعاء العلاجي: لن تُكتب، ونقول لماذا ونقترح بديلاً يثبت.
 *   • المعلومة (توصيل، خصم، ضمان) مكتوبة كقاعدة: مكانها «حقائق البيع» لتُذكر بدقة.
 */
class ClaimConflicts
{
    /**
     * المطلق وحده، بصيغته. «أفضل» وحدها مقارنة لا ادعاء:
     * «خدمة أفضل» لا تُرفض، و«الأفضل» تُرفض.
     */
    public const ABSOLUTES = [
        'الأفضل', 'الأرخص', 'الأقوى', 'الأشهر', 'الأكبر', 'الوحيد', 'الوحيدون', 'الرائد',
        'رقم واحد', 'رقم 1', 'الأول في', 'الأولى في', 'لا مثيل', 'بلا منافس', 'لا يُعلى عليه',
    ];

    /**
     * بداية تجعل القاعدة قيداً لا ادعاءً: «لا تذكر الأسعار» لا تعد العميل بشيء.
     * تُطابق بعد التوحيد، فالصيغ هنا بلا همزات ولا تشكيل.
     */
    protected const RESTRICTION = '/^\s*(?:لا(?:\s|$)|تجنب|ممنوع|امنع|ابتعد|بدون|دون\s|عدم|اياك)/u';

    /**
     * مشاكل نص يصف المشروع (إجابات، تعليمات منشور): المطلق والعلاجي فقط.
     * المعلومات هنا مقبولة — التاجر يصف متجره.
     *
     * @return array<int, string>
     */
    public function inStatement(string $text): array
    {
        $problems = [];

        if ($absolute = $this->absoluteIn($text)) {
            $problems[] = "«{$absolute}»: ادعاء مطلق لا يمكن إثباته، فلن يُكتب في المحتوى. "
                .'اكتب ما يثبته بدلاً منه، مثل «أكثر من 200 منتج» أو «ثلاثة فروع».';
        }

        if ($medical = $this->firstOf($text, config('claims.categories.medical.words', []))) {
            $problems[] = "«{$medical}»: ادعاء علاجي، ولا يُكتب في أي محتوى.";
        }

        return $problems;
    }

    /**
     * سبب رفض قاعدة كتابة، أو null إن كانت مقبولة.
     * القيد («لا تذكر…») مقبول دائماً: لا يستطيع أن يجعل المحتوى يعد بما لا يوجد.
     */
    public function inRule(string $rule): ?string
    {
        if (preg_match(self::RESTRICTION, ArabicText::normalize($rule))) {
            return null;
        }

        if ($problem = $this->inStatement($rule)[0] ?? null) {
            return $problem;
        }

        foreach (config('claims.categories', []) as $key => $category) {
            if ($key === 'medical') {
                continue;
            }

            if ($hit = $this->firstOf($rule, $category['words'])) {
                return "«{$hit}» معلومة لا قاعدة: أضفها في صفحة «حقائق البيع» لتُذكر بدقة (أين؟ متى؟ بأي شرط؟).";
            }
        }

        return null;
    }

    protected function absoluteIn(string $text): ?string
    {
        foreach (self::ABSOLUTES as $word) {
            // بلا تقشير: «الأفضل» مطلق، و«أفضل» التي يعيدها التقشير مقارنة
            if (($found = ArabicText::find($text, $word, stem: false)) !== []) {
                return $found[0];
            }
        }

        return null;
    }

    /** @param array<int, string> $words */
    protected function firstOf(string $text, array $words): ?string
    {
        foreach ($words as $word) {
            if (($found = ArabicText::find($text, $word)) !== []) {
                return $found[0];
            }
        }

        return null;
    }
}
