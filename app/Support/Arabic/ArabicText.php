<?php

namespace App\Support\Arabic;

/**
 * مطابقة الكلمات العربية كما يكتبها الناس، لا كما في القاموس.
 *
 * «تجربة» الممنوعة تصل إلى النص بصيغ «التجربة» و«بتجربتك» و«تجاربنا»،
 * والمطابقة الحرفية تُفلتها كلها (هذا ما رصده تدقيق المنافس: الكلمة الممنوعة
 * في كل مخرج). هنا نوحّد الكتابة، ونقشّر السوابق واللواحق الشائعة من كلمات
 * النص، ثم نقارنها بالصيغ الأساسية للكلمة المطلوبة.
 *
 * ليس محللاً صرفياً: قد يطابق فعلاً نادراً يشارك الاسم جذعه («تجرّب»).
 * هذا مقبول في فحص نتيجته طلب تصحيح أو تنبيه، لا حذف تلقائي.
 */
class ArabicText
{
    /** الأطول أولاً: «وبال» قبل «و». */
    protected const PREFIXES = ['وبال', 'وال', 'بال', 'كال', 'فال', 'لل', 'ال', 'و', 'ف', 'ب', 'ل', 'ك'];

    /**
     * الأطول أولاً. التاء المربوطة تُكتب مفتوحة قبل الضمير («تجربتك»)،
     * فتُقشّر معه لتعود الكلمة إلى جذعها.
     */
    protected const SUFFIXES = [
        'تهما', 'تكما', 'تهم', 'تكم', 'تنا', 'تها', 'ته', 'تك', 'تي',
        'هما', 'كما', 'يه', 'ات', 'ون', 'ين', 'ان', 'هم', 'هن', 'كم', 'كن', 'نا', 'ها',
        'ه', 'ك', 'ي', 'ا',
    ];

    /**
     * ما يُقشَّر من الكلمة المطلوبة نفسها: التاء المربوطة والنسبة وألف التنوين فقط.
     * الكلمة المطلوبة تُكتب بصيغتها الأساسية، وتقشير «ون» منها يجعل «كوبون» «كوب».
     */
    protected const TARGET_SUFFIXES = ['يه', 'ه', 'ي', 'ا'];

    /** أقصر جذع نقبله بعد التقشير: ما دونه يطابق كلمات لا علاقة لها ببعض. */
    protected const MIN_STEM = 3;

    /**
     * توحيد الكتابة للمقارنة: بلا تشكيل ولا تطويل، همزات الألف ألفاً،
     * التاء المربوطة هاءً، الألف المقصورة ياءً، والأرقام الهندية لاتينية.
     */
    public static function normalize(string $text): string
    {
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text);

        return mb_strtolower(self::digits(strtr($text, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ة' => 'ه', 'ى' => 'ي',
        ])));
    }

    /** «٧٥» و«۷۵» و«75» رقم واحد. الفاصلة العشرية العربية نقطة. */
    public static function digits(string $text): string
    {
        return strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٫' => '.', '٬' => ',',
        ]);
    }

    /**
     * كلمات النص كما وردت (بتشكيلها)، لتُعرض في الرسائل بصيغتها الأصلية.
     *
     * @return array<int, string>
     */
    public static function tokens(string $text): array
    {
        preg_match_all('/[\p{L}\p{M}\p{N}]+/u', $text, $m);

        return $m[0];
    }

    /**
     * صيغ الكلمة المطلوبة في النص، كما وردت فيه.
     *
     * $stem = false للكلمات التي يغيّر التقشير معناها: «الحين» العامية
     * جذعها «حين» الفصيحة، فتُطابق حرفياً (مع واو العطف أو فائه فقط).
     *
     * @return array<int, string>
     */
    public static function find(string $text, string $target, bool $stem = true): array
    {
        $target = trim($target);

        if ($target === '') {
            return [];
        }

        if (preg_match('/\s/u', $target)) {
            return self::findPhrase($text, $target);
        }

        $forms = $stem ? self::forms($target) : [self::normalize($target)];
        $found = [];

        foreach (self::tokens($text) as $surface) {
            $variants = $stem
                ? self::variants(self::normalize($surface))
                : self::conjunctionVariants(self::normalize($surface));

            if (array_intersect($variants, $forms) !== []) {
                $found[] = $surface;
            }
        }

        return array_values(array_unique($found));
    }

    public static function contains(string $text, string $target, bool $stem = true): bool
    {
        return self::find($text, $target, $stem) !== [];
    }

    /**
     * أول كلمة من القائمة ترد في النص، أو null.
     *
     * @param  array<int, string>  $targets
     */
    public static function firstOf(string $text, array $targets, bool $stem = true): ?string
    {
        foreach ($targets as $target) {
            if (self::contains($text, $target, $stem)) {
                return $target;
            }
        }

        return null;
    }

    /**
     * كلمة الموضوع (ممنوعة أو دالة على ادعاء) بصيغها الأساسية:
     * كما هي، وبلا «ال»، وبلا تاء مربوطة أو ياء نسبة أو ألف تنوين.
     * والاسم الرباعي المختوم بتاء مربوطة يُضاف جمع تكسيره («تجربة» ← «تجارب»).
     *
     * @return array<int, string>
     */
    public static function forms(string $word): array
    {
        $word = self::normalize($word);

        if (str_starts_with($word, 'ال') && mb_strlen($word) - 2 >= self::MIN_STEM) {
            $word = mb_substr($word, 2);
        }

        $forms = [$word, ...self::stripSuffixes($word, self::TARGET_SUFFIXES)];

        if (str_ends_with($word, 'ه')) {
            foreach ($forms as $form) {
                if (mb_strlen($form) === 4 && preg_match('/^\p{Arabic}+$/u', $form)) {
                    $forms[] = mb_substr($form, 0, 2).'ا'.mb_substr($form, 2);
                }
            }
        }

        return array_values(array_unique($forms));
    }

    /**
     * كل ما قد تكون عليه كلمة من النص بعد تقشيرها: حتى سابقتين
     * («وبالكوبون») ثم لاحقة واحدة («تجربتك»). كل لاحقة محتملة تُجرَّب،
     * لا الأطول وحدها: «حصرية» قد تكون «حصر» + «ية» أو «حصري» + «ة».
     *
     * @return array<int, string>
     */
    public static function variants(string $token): array
    {
        $bases = [$token];

        if (($once = self::stripPrefix($token)) !== null) {
            $bases[] = $once;

            if (($twice = self::stripPrefix($once)) !== null) {
                $bases[] = $twice;
            }
        }

        $variants = $bases;

        foreach ($bases as $base) {
            array_push($variants, ...self::stripSuffixes($base, self::SUFFIXES));
        }

        return array_values(array_unique($variants));
    }

    /** @return array<int, string> */
    protected static function conjunctionVariants(string $token): array
    {
        $variants = [$token];

        if (preg_match('/^[وف](.{2,})$/u', $token, $m)) {
            $variants[] = $m[1];
        }

        return $variants;
    }

    protected static function stripPrefix(string $word): ?string
    {
        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($word, $prefix) && mb_strlen($word) - mb_strlen($prefix) >= self::MIN_STEM) {
                return mb_substr($word, mb_strlen($prefix));
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $suffixes
     * @return array<int, string>
     */
    protected static function stripSuffixes(string $word, array $suffixes): array
    {
        $stripped = [];

        foreach ($suffixes as $suffix) {
            if (str_ends_with($word, $suffix) && mb_strlen($word) - mb_strlen($suffix) >= self::MIN_STEM) {
                $stripped[] = mb_substr($word, 0, mb_strlen($word) - mb_strlen($suffix));
            }
        }

        return $stripped;
    }

    /**
     * عبارة من أكثر من كلمة: مطابقة بعد التوحيد، مع سابقة اختيارية
     * على أولها و«ال» اختيارية على كل كلماتها («بالكمية المحدودة»).
     * تُعاد بصيغتها الموحّدة.
     *
     * @return array<int, string>
     */
    protected static function findPhrase(string $text, string $phrase): array
    {
        $words = array_map(
            fn ($w) => preg_quote(preg_replace('/^ال(?=.{3,})/u', '', $w), '/'),
            preg_split('/\s+/u', self::normalize($phrase)) ?: [],
        );
        $first = array_shift($words);

        $pattern = '/(?<![\p{L}\p{N}])(?:[وفبلك]{0,2}(?:ال)?)'.$first
            .implode('', array_map(fn ($w) => '\s+(?:ال)?'.$w, $words))
            .'(?![\p{L}\p{N}])/u';

        preg_match_all($pattern, self::normalize($text), $m);

        return array_values(array_unique($m[0]));
    }
}
