<?php

namespace App\Services\Brand\Quality;

use App\Services\Brand\BrandProfileGenerator;
use App\Support\Arabic\ArabicText;

/**
 * فحص حتمي لمخرج توليد ملف الهوية مقابل مدخلاته.
 *
 * لا يحكم على جمال الصياغة — هذا لا يُفحص آلياً. يحكم على ما يمكن
 * إثباته: هل اخترع النموذج رقماً أو رابطاً، هل تجاوز الطول، هل كتب
 * كلمة ممنوعة أو مبالغة، هل ترك أثر تنسيق أو نصاً تجريبياً.
 *
 * القواعد هنا هي نفسها قواعد برومبت النظام في BrandProfileGenerator،
 * فأي تعديل على أحدهما يجب أن ينعكس على الآخر.
 */
class ProfileQualityCheck
{
    public const SIMPLE_MAX_WORDS = 60;

    public const DETAILED_MIN_WORDS = 120;

    public const DETAILED_MAX_WORDS = 200;

    /** هامش قبل التحذير: النموذج لا يعدّ الكلمات بدقة، ولا نعاقبه على كلمة زائدة. */
    public const LENGTH_TOLERANCE = 0.15;

    /** مبالغات مطلقة يمنعها برومبت النظام صراحة. */
    public const SUPERLATIVES = [
        'الأفضل', 'أفضل', 'رقم واحد', 'رقم 1', 'الأقوى', 'الأرخص', 'لا مثيل', 'بلا منافس',
        'الوحيد', 'الأكبر', 'الأشهر', 'الرائد', 'الأول في', 'الأولى في',
    ];

    /**
     * @param  array{simple?: ?string, detailed?: ?string, technical?: array}  $profile
     * @param  array<string, string>  $answers  لقطة الإجابات كما أُرسلت للنموذج
     * @param  array<int, string>  $bannedWords
     */
    public function check(array $profile, array $answers, array $bannedWords = []): ProfileQualityReport
    {
        $report = new ProfileQualityReport;

        $simple = trim((string) ($profile['simple'] ?? ''));
        $detailed = trim((string) ($profile['detailed'] ?? ''));
        $technical = (array) ($profile['technical'] ?? []);

        $this->checkRequired($report, $simple, $detailed, $technical);
        $this->checkLengths($report, $simple, $detailed, $technical);

        $texts = array_filter([
            'simple' => $simple,
            'detailed' => $detailed,
            'activity_type' => (string) ($technical['activity_type'] ?? ''),
            'sales_summary' => (string) ($technical['sales_summary'] ?? ''),
            'advantages_directives' => (string) ($technical['advantages_directives'] ?? ''),
            'important_notes' => implode("\n", (array) ($technical['important_notes'] ?? [])),
        ]);

        $inputs = implode("\n", array_map('strval', $answers));

        foreach ($texts as $field => $text) {
            // الملاحظات تعليمات، وكثير منها نفي («لا تذكر التوصيل»): ليست ادعاءً
            if ($field !== 'important_notes') {
                $this->checkUnsupportedClaims($report, $field, $text, $inputs);
            }

            $this->checkInventedNumbers($report, $field, $text, $inputs);
            $this->checkInventedLinks($report, $field, $text, $inputs);
            $this->checkBannedWords($report, $field, $text, $bannedWords);
            $this->checkSuperlatives($report, $field, $text, $inputs);
            $this->checkPlaceholders($report, $field, $text);
            $this->checkLanguage($report, $field, $text);
        }

        foreach (['simple' => $simple, 'detailed' => $detailed] as $field => $text) {
            $this->checkFormatting($report, $field, $text);
        }

        $this->checkNameMentioned($report, $simple, $detailed, (string) ($answers['project_name'] ?? ''));
        $this->checkNotes($report, (array) ($technical['important_notes'] ?? []));

        return $report;
    }

    // ================================================================
    //  البنية
    // ================================================================

    protected function checkRequired(ProfileQualityReport $report, string $simple, string $detailed, array $technical): void
    {
        foreach (['simple' => $simple, 'detailed' => $detailed] as $field => $text) {
            if ($text === '') {
                $report->add('missing_field', ProfileQualityReport::ERROR, $field, 'الحقل فارغ.');
            }
        }

        foreach (['activity_type', 'sales_summary', 'advantages_directives'] as $field) {
            if (trim((string) ($technical[$field] ?? '')) === '') {
                $report->add('missing_field', ProfileQualityReport::ERROR, $field, 'الحقل التقني فارغ.');
            }
        }
    }

    protected function checkLengths(ProfileQualityReport $report, string $simple, string $detailed, array $technical): void
    {
        $max = (int) ceil(self::SIMPLE_MAX_WORDS * (1 + self::LENGTH_TOLERANCE));

        if ($simple !== '' && ($words = $this->words($simple)) > $max) {
            $report->add('too_long', ProfileQualityReport::WARNING, 'simple',
                "الوصف المبسط {$words} كلمة، والحد ".self::SIMPLE_MAX_WORDS.'.');
        }

        if ($simple !== '' && preg_match('/\R/u', $simple)) {
            $report->add('not_one_paragraph', ProfileQualityReport::WARNING, 'simple', 'الوصف المبسط ليس فقرة واحدة.');
        }

        if ($detailed !== '') {
            $words = $this->words($detailed);
            $min = (int) floor(self::DETAILED_MIN_WORDS * (1 - self::LENGTH_TOLERANCE));
            $maxDetailed = (int) ceil(self::DETAILED_MAX_WORDS * (1 + self::LENGTH_TOLERANCE));

            if ($words < $min) {
                $report->add('too_short', ProfileQualityReport::WARNING, 'detailed',
                    "الوصف التفصيلي {$words} كلمة، والمطلوب ".self::DETAILED_MIN_WORDS.' على الأقل.');
            } elseif ($words > $maxDetailed) {
                $report->add('too_long', ProfileQualityReport::WARNING, 'detailed',
                    "الوصف التفصيلي {$words} كلمة، والحد ".self::DETAILED_MAX_WORDS.'.');
            }
        }

        $activity = trim((string) ($technical['activity_type'] ?? ''));

        if ($activity !== '' && ($this->words($activity) > 20 || preg_match('/\R/u', $activity))) {
            $report->add('too_long', ProfileQualityReport::WARNING, 'activity_type', 'نوع النشاط يجب أن يكون سطراً واحداً قصيراً.');
        }

        // «…» في النهاية أثر اقتطاع Str::limit عندنا، لا أسلوب كتابة
        foreach (['simple' => $simple, 'detailed' => $detailed] as $field => $text) {
            if (str_ends_with($text, '…')) {
                $report->add('truncated', ProfileQualityReport::WARNING, $field, 'النص مقتطع في منتصفه.');
            }
        }
    }

    // ================================================================
    //  الاختلاق: أخطر ما يمكن أن يصل للعميل
    // ================================================================

    /**
     * كل رقم في المخرج يجب أن يكون في المدخلات: سنة تأسيس، نسبة خصم،
     * عدد فروع، رقم هاتف — كلها ادعاءات يُحاسب عليها صاحب المتجر.
     */
    protected function checkInventedNumbers(ProfileQualityReport $report, string $field, string $text, string $inputs): void
    {
        $inputNumbers = $this->numbers($this->withoutLinks($inputs));

        foreach ($this->numbers($this->withoutLinks($text)) as $number) {
            if (! in_array($number, $inputNumbers, true)) {
                $report->add('invented_number', ProfileQualityReport::ERROR, $field, "الرقم «{$number}» غير موجود في الإجابات.");
            }
        }
    }

    /**
     * خدمة أو وعد لم يرد في الإجابات: توصيل، مجاني، خصم، ضمان…
     * تدقيق المنافس (H3): «ليصل المنتج مناسباً» أوحت بتوصيل لم يذكره أحد.
     * المعجم نفسه الذي يفحص المحتوى (config/claims.php).
     */
    protected function checkUnsupportedClaims(ProfileQualityReport $report, string $field, string $text, string $inputs): void
    {
        foreach ((array) config('claims.categories', []) as $category) {
            foreach ($category['words'] as $word) {
                if (($found = ArabicText::find($text, $word)) === []) {
                    continue;
                }

                $allowed = empty($category['always']) && collect($category['words'])
                    ->contains(fn ($w) => ArabicText::contains($inputs, $w));

                if (! $allowed) {
                    $report->add('unsupported_claim', ProfileQualityReport::ERROR, $field,
                        "«{$found[0]}»: {$category['label']} لم يرد في الإجابات.");
                }

                break;
            }
        }
    }

    protected function checkInventedLinks(ProfileQualityReport $report, string $field, string $text, string $inputs): void
    {
        $known = array_map([$this, 'host'], $this->links($inputs));

        foreach ($this->links($text) as $link) {
            if (! in_array($this->host($link), $known, true)) {
                $report->add('invented_link', ProfileQualityReport::ERROR, $field, "الرابط «{$link}» غير موجود في الإجابات.");
            }
        }
    }

    /** بكل تصريفاتها: حظر «تجربة» يشمل «التجربة» و«تجربتك» و«تجاربنا». */
    protected function checkBannedWords(ProfileQualityReport $report, string $field, string $text, array $banned): void
    {
        foreach ($banned as $word) {
            $word = trim((string) $word);

            if ($word !== '' && ($found = ArabicText::find($text, $word)) !== []) {
                $report->add('banned_word', ProfileQualityReport::ERROR, $field,
                    "وردت الكلمة الممنوعة «{$word}»".($found !== [$word] ? ' بصيغة «'.implode('»، «', $found).'»' : '').'.');
            }
        }
    }

    /**
     * المبالغة تحذير لا خطأ: قد يكتبها صاحب المتجر نفسه في إجابته،
     * وحينها ننبّه ولا نمنع — القرار له.
     */
    protected function checkSuperlatives(ProfileQualityReport $report, string $field, string $text, string $inputs): void
    {
        foreach (self::SUPERLATIVES as $word) {
            if (! preg_match('/(?<![\p{L}])'.preg_quote($word, '/').'(?![\p{L}])/u', $text)) {
                continue;
            }

            $fromUser = mb_stripos($inputs, $word) !== false;

            $report->add('superlative', ProfileQualityReport::WARNING, $field,
                "مبالغة «{$word}»".($fromUser ? ' (وردت في إجابات المستخدم).' : ' لم ترد في الإجابات.'));
        }
    }

    // ================================================================
    //  الشكل
    // ================================================================

    protected function checkPlaceholders(ProfileQualityReport $report, string $field, string $text): void
    {
        if (preg_match('/\[نص تجريبي|lorem ipsum|\{\{|\}\}|\bTODO\b|\bundefined\b|\bnull\b|\bArray\b/iu', $text)) {
            $report->add('placeholder', ProfileQualityReport::ERROR, $field, 'نص تجريبي أو أثر برمجي في المخرج.');
        }
    }

    protected function checkFormatting(ProfileQualityReport $report, string $field, string $text): void
    {
        if (preg_match('/\*\*|__|^\s*#{1,6}\s|^\s*[-*•]\s/mu', $text)) {
            $report->add('markdown', ProfileQualityReport::WARNING, $field, 'علامات تنسيق Markdown تظهر كرموز في الواجهة.');
        }
    }

    /** اسم علامة لاتيني مقبول؛ فقرة إنجليزية ليست كذلك. */
    protected function checkLanguage(ProfileQualityReport $report, string $field, string $text): void
    {
        $text = $this->withoutLinks($text);

        $arabic = preg_match_all('/\p{Arabic}/u', $text);
        $latin = preg_match_all('/[A-Za-z]/u', $text);

        if ($arabic + $latin >= 20 && $latin / ($arabic + $latin) > 0.25) {
            $report->add('not_arabic', ProfileQualityReport::WARNING, $field, 'نسبة كبيرة من النص بغير العربية.');
        }
    }

    protected function checkNameMentioned(ProfileQualityReport $report, string $simple, string $detailed, string $name): void
    {
        $name = trim($name);

        if ($name === '' || $simple.$detailed === '') {
            return;
        }

        // «إمدادات» و«امدادات» اسم واحد: نطابق بعد توحيد الهمزات
        $haystack = $this->normalizeArabic($simple.' '.$detailed);

        if (mb_stripos($haystack, $this->normalizeArabic($name)) === false) {
            $report->add('name_missing', ProfileQualityReport::WARNING, 'simple', "اسم المشروع «{$name}» لا يظهر في الوصفين.");
        }
    }

    protected function checkNotes(ProfileQualityReport $report, array $notes): void
    {
        // القيود الثابتة يكتبها الكود: العدّ لما كتبه النموذج وحده
        $fixed = array_map([BrandProfileGenerator::class, 'noteKey'], BrandProfileGenerator::FIXED_NOTES);

        $generated = array_values(array_filter(
            array_map(fn ($n) => trim((string) $n), $notes),
            fn ($n) => $n !== '' && ! in_array(BrandProfileGenerator::noteKey($n), $fixed, true),
        ));

        $count = count($generated);

        if ($count < 3 || $count > 5) {
            $report->add('notes_count', ProfileQualityReport::WARNING, 'important_notes',
                "عدد الملاحظات المولّدة {$count}، والمطلوب من 3 إلى 5.");
        }

        foreach ($generated as $note) {
            if ($this->words($note) > 30) {
                $report->add('note_too_long', ProfileQualityReport::WARNING, 'important_notes', 'ملاحظة أطول من تعليمة قصيرة.');
            }
        }

        $normalized = array_map(fn ($n) => $this->normalizeArabic($n), $generated);

        if (count(array_unique($normalized)) < count($normalized)) {
            $report->add('duplicate_notes', ProfileQualityReport::WARNING, 'important_notes', 'ملاحظات مكررة.');
        }
    }

    // ================================================================
    //  أدوات
    // ================================================================

    public function words(string $text): int
    {
        return count(array_filter(
            preg_split('/\s+/u', trim($text)) ?: [],
            fn ($w) => preg_match('/[\p{L}\p{N}]/u', $w),
        ));
    }

    /** @return array<int, string> الأرقام بعد توحيد الأرقام العربية إلى لاتينية */
    protected function numbers(string $text): array
    {
        $text = strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);

        preg_match_all('/\d+(?:[.,]\d+)?/u', $text, $m);

        return array_values(array_unique($m[0]));
    }

    /** @return array<int, string> */
    protected function links(string $text): array
    {
        preg_match_all('#\bhttps?://[^\s"\'<>،)]+|\bwww\.[^\s"\'<>،)]+#iu', $text, $m);

        return array_map(fn ($l) => rtrim($l, '.,'), $m[0]);
    }

    protected function host(string $link): string
    {
        $host = parse_url(str_contains($link, '://') ? $link : 'http://'.$link, PHP_URL_HOST) ?: $link;

        return preg_replace('/^www\./i', '', strtolower($host));
    }

    protected function withoutLinks(string $text): string
    {
        return preg_replace('#\bhttps?://\S+|\bwww\.\S+#iu', ' ', $text);
    }

    /** «ركّز» و«ركز»، و«إمدادات» و«امدادات»: كلمة واحدة للمقارنة. */
    protected function normalizeArabic(string $text): string
    {
        return ArabicText::normalize($text);
    }
}
