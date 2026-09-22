<?php

namespace App\Services\Content\Quality;

use App\Services\Brand\Quality\ProfileQualityCheck;
use App\Services\Content\ContentFacts;
use App\Services\Quality\QualityReport;
use App\Support\Arabic\ArabicText;

/**
 * فحص حتمي لمنشور مولّد مقابل دفتر الحقائق.
 *
 * كل فحص هنا يقابل اختلاقاً رصده تدقيق المنافس (docs/sahal-audit-plan.md §4):
 * أسعار سوق وإنتاجية مخترعة، تقييمات وأعداد عملاء، شهادة عميل وهمي،
 * كود خصم، توصيل لم يُذكر، كلمة ممنوعة بتصريف آخر، لهجة متسربة.
 *
 * الخطأ = ما يضر التاجر إن نُشر، فيُطلب تصحيحه. التحذير = يُعرض عليه ليقرر.
 */
class ContentQualityCheck
{
    /**
     * العدد الصحيح حتى هذا الحد يعدّ عناصر المنشور نفسه («3 خطوات»)،
     * لا ادعاءً عن المنتج — ما لم تتبعه وحدة أو عملة أو نسبة.
     */
    public const SMALL_COUNT = 10;

    /** ما يجعل الرقم ادعاءً حتى لو كان صغيراً («5 ريالات»، «3 أيام»). النص موحّد مسبقاً. */
    protected const UNIT_AFTER = '/^\s*(?:%|٪|ر\.?\s?س|ريال|دولار|ساع|يوم|ايام|دقيق|ثاني|اسبوع|شهر|سنه|سنو|سنين|كوب|اكواب|كجم|كيلو|كغ|جرام|غرام|جم|مل(?!\p{L})|لتر|عميل|عملاء|زبون|زبائن|فرع|فروع|طلب|قطع|حب|مره|مرات|اضعاف|×)/u';

    /** أول ما يُكتب في الواجهة عن كل حقل. */
    public const FIELD_LABELS = [
        'caption' => 'الكابشن',
        'script' => 'السكربت',
        'hook' => 'الافتتاحية',
        'hashtags' => 'الهاشتاقات',
        'content' => 'المحتوى',
        'title' => 'العنوان',
    ];

    /** الحقول المرقّمة: «scene:2» ← «المشهد 2». */
    protected const NUMBERED_LABELS = [
        'slide' => 'الشريحة',
        'scene' => 'المشهد',
        'screen' => 'نص الشاشة',
        'frame' => 'الإطار',
        'tweet' => 'التغريدة',
        'section' => 'القسم',
    ];

    /**
     * @param  array{caption?: ?string, slides?: array, script?: ?string, hook?: ?string, hashtags?: array}  $content  مخرج ContentSchema::normalize
     * @param  array{platform?: ?string, format?: ?string, slides?: int, dialect?: ?string, banned_words?: array}  $options
     */
    public function check(array $content, ContentFacts $facts, array $options = []): QualityReport
    {
        $report = new QualityReport;

        foreach ($this->texts($content) as $field => $text) {
            $this->checkPlaceholders($report, $field, $text);

            $text = $this->checkRatings($report, $field, $text, $facts);

            $this->checkNumbers($report, $field, $text, $facts);
            $this->checkCodes($report, $field, $text, $facts);
            $this->checkClaims($report, $field, $text, $facts);
            $this->checkTestimonials($report, $field, $text, $facts);
            $this->checkCrowd($report, $field, $text, $facts);
            $this->checkBannedWords($report, $field, $text, (array) ($options['banned_words'] ?? []));

            // الهاشتاق وسم لا جملة: المبالغة واللهجة والتعريب لا تُحاسب فيه
            if ($field !== 'hashtags') {
                $this->checkSuperlatives($report, $field, $text);
                $this->checkPuffery($report, $field, $text, $facts);
                // قوائم اللهجات عربية: النص الإنجليزي لا «يتسرّب» إليها
                if (($options['language'] ?? 'ar') !== 'en') {
                    $this->checkDialect($report, $field, $text, $options['dialect'] ?? null);
                }
                $this->checkLatinNames($report, $field, $text, $facts);
            }
        }

        $this->checkLength($report, $content, $options['platform'] ?? null);
        $this->checkSlideCount($report, $content, $options['format'] ?? null, (int) ($options['slides'] ?? 0));

        return $report;
    }

    public static function fieldLabel(string $field): string
    {
        if (preg_match('/^(\w+):(\d+)$/', $field, $m) && isset(self::NUMBERED_LABELS[$m[1]])) {
            return self::NUMBERED_LABELS[$m[1]].' '.$m[2];
        }

        return self::FIELD_LABELS[$field] ?? $field;
    }

    // ================================================================
    //  الأرقام: أخطر الاختلاق لأنه يبدو موثوقاً
    // ================================================================

    /**
     * التقييم يُفحص قبل الأرقام ويُزال من النص، فلا يُبلَّغ عنه مرتين.
     * «4.9/5» و«4.8 من 5» و«5 نجوم». (C4·V3)
     */
    protected function checkRatings(QualityReport $report, string $field, string $text, ContentFacts $facts): string
    {
        $text = ArabicText::digits($text);

        $pattern = '/(\d+(?:[.,]\d+)?)\s*\/\s*(?:5|10)(?!\d)|(\d+[.,]\d+)\s*من\s*(?:5|10)(?!\d)|(\d+(?:[.,]\d+)?)\s*(?:نجوم|نجمات|نجمة|نجمه)/u';

        return preg_replace_callback($pattern, function ($m) use ($report, $field, $facts) {
            $value = ContentFacts::canonical($m[1] ?: ($m[2] ?? '') ?: ($m[3] ?? ''));

            if (! $facts->hasNumber($value)) {
                $report->add('invented_rating', QualityReport::ERROR, $field,
                    "التقييم «{$m[0]}» غير موجود في بيانات المنتج.");
            }

            return ' ';
        }, $text);
    }

    /**
     * كل رقم يجب أن يكون في الحقائق: «20 ريالاً يومياً» و«تكفيك 15 كوباً»
     * و«98%» كلها أرقام بلا أصل. (C4·V2، C4·V3)
     */
    protected function checkNumbers(QualityReport $report, string $field, string $text, ContentFacts $facts): void
    {
        $text = ArabicText::digits($text);

        // ترقيم القوائم في أول السطر ورموز الأرقام التعبيرية ليست أرقاماً عن المنتج
        $text = preg_replace('/^\s*\d+\s*[.)\-–:]\s+/mu', ' ', $text);
        $text = preg_replace('/\d\x{FE0F}?\x{20E3}/u', ' ', $text);

        // «V60» اسم أداة و«KSA96» كود: يفحصهما فحص الأكواد لا الأرقام
        $text = preg_replace('/(?<![\p{L}\p{N}])[A-Za-z][A-Za-z0-9-]*\d[A-Za-z0-9-]*/u', ' ', $text);

        preg_match_all('/\d+(?:[.,]\d+)*/u', $text, $matches, PREG_OFFSET_CAPTURE);

        $reported = [];

        foreach ($matches[0] as [$raw, $offset]) {
            $number = ContentFacts::canonical($raw);

            if ($facts->hasNumber($number) || isset($reported[$number])) {
                continue;
            }

            $after = ArabicText::normalize(mb_strcut($text, $offset + strlen($raw), 40));

            if (ctype_digit($number) && (int) $number <= self::SMALL_COUNT && ! preg_match(self::UNIT_AFTER, $after)) {
                continue;
            }

            $reported[$number] = true;
            $label = preg_match('/^\s*[%٪]/u', $after) ? "النسبة «{$raw}%»" : "الرقم «{$raw}»";

            $report->add('invented_number', QualityReport::ERROR, $field, "{$label} غير موجود في البيانات.");
        }
    }

    /**
     * كود خصم لم يعطه التاجر قد يحاول عميل حقيقي استخدامه. (C6: KSA96)
     */
    protected function checkCodes(QualityReport $report, string $field, string $text, ContentFacts $facts): void
    {
        $text = ArabicText::digits($text);

        preg_match_all('/(?<![A-Za-z0-9])[A-Za-z]{2,}[A-Za-z0-9]*\d[A-Za-z0-9]*(?![A-Za-z0-9])/u', $text, $mixed);
        preg_match_all('/(?:كود|كوبون|code|coupon)\s*(?:ال)?(?:خصم)?\s*[:：«"]?\s*([A-Za-z0-9]{3,})/iu', $text, $labelled);

        $codes = array_unique([...$mixed[0], ...$labelled[1]]);

        foreach ($codes as $code) {
            if (! $facts->containsLiteral($code)) {
                $report->add('invented_code', QualityReport::ERROR, $field, "الكود «{$code}» غير موجود في البيانات.");
            }
        }
    }

    // ================================================================
    //  الادعاءات: خدمة أو عرض لم يذكره التاجر
    // ================================================================

    /** «وتصلك بطزاجة» توصيل لم يُذكر (C1)، و«نطحنها لك مجاناً» خدمة مجانية لم تُذكر (C4·V2). */
    protected function checkClaims(QualityReport $report, string $field, string $text, ContentFacts $facts): void
    {
        foreach (config('claims.categories', []) as $category) {
            $hit = $this->firstHit($text, $category['words']);

            if ($hit === null) {
                continue;
            }

            if (! empty($category['always'])) {
                $report->add('unsupported_claim', QualityReport::ERROR, $field,
                    "«{$hit}»: {$category['label']} ممنوع في المحتوى.");
            } elseif (! $facts->mentionsAny($category['words'])) {
                $report->add('unsupported_claim', QualityReport::ERROR, $field,
                    "«{$hit}»: {$category['label']} لم يرد في بيانات المتجر أو المنتج.");
            }
        }
    }

    /**
     * «عبدالمجيد، عميل موثق» شهادة كاملة لشخص لا وجود له. (C4·V3)
     *
     * بلا تجارب مسجّلة: أي شهادة اختلاق. ومعها: الاقتباس المنسوب لقائل
     * يجب أن يطابق تجربة مسجّلة بنصها — إعادة صياغة كلام العميل تقويل له.
     */
    protected function checkTestimonials(QualityReport $report, string $field, string $text, ContentFacts $facts): void
    {
        if ($facts->hasTestimonials()) {
            $this->checkQuotes($report, $field, $text, $facts);

            return;
        }

        $hit = $this->firstHit($text, config('claims.testimonial_markers', []));

        // اقتباس يتبعه اسم: «…» — عبدالمجيد
        if ($hit === null && preg_match('/[«"“][^»"”]{8,}[»"”]\s*[-–—]\s*\p{Arabic}{2,}/u', $text, $m)) {
            $hit = mb_substr($m[0], -30);
        }

        if ($hit !== null) {
            $report->add('invented_testimonial', QualityReport::ERROR, $field,
                "شهادة عميل («{$hit}») ولا توجد تجارب عملاء حقيقية مسجّلة.");
        }
    }

    protected function checkQuotes(QualityReport $report, string $field, string $text, ContentFacts $facts): void
    {
        preg_match_all('/[«"“]([^»"”]{8,})[»"”]\s*[-–—]\s*\p{Arabic}/u', $text, $m);

        $squash = fn ($s) => preg_replace('/[^\p{L}\p{N}]+/u', ' ', ArabicText::normalize($s));
        $known = array_map($squash, $facts->testimonials);

        foreach ($m[1] as $quote) {
            $needle = trim($squash($quote));

            if (! collect($known)->contains(fn ($body) => str_contains($body, $needle))) {
                $report->add('invented_testimonial', QualityReport::ERROR, $field,
                    'الاقتباس «'.mb_substr($quote, 0, 40).'» لا يطابق نص أي تجربة عميل مسجّلة.');
            }
        }
    }

    /** «آلاف العملاء» عدد بلا رقم، فلا يراه فحص الأرقام. */
    protected function checkCrowd(QualityReport $report, string $field, string $text, ContentFacts $facts): void
    {
        foreach (config('claims.crowd_markers', []) as $marker) {
            if (ArabicText::contains($text, $marker) && ! $facts->mentions($marker)) {
                $report->add('invented_customers', QualityReport::ERROR, $field, "«{$marker}» عدد عملاء لم يرد في البيانات.");

                return;
            }
        }
    }

    // ================================================================
    //  الكلمات: ما منعه التاجر وما لا يشبه لهجته
    // ================================================================

    /** بكل تصريفاتها: «تجربة» الممنوعة ظهرت أربع مرات في مخرج واحد عند المنافس. (C1، C8) */
    protected function checkBannedWords(QualityReport $report, string $field, string $text, array $banned): void
    {
        foreach ($banned as $word) {
            $word = trim((string) $word);

            if ($word !== '' && ($found = ArabicText::find($text, $word)) !== []) {
                $report->add('banned_word', QualityReport::ERROR, $field,
                    "الكلمة الممنوعة «{$word}» وردت بصيغة «".implode('»، «', $found).'».');
            }
        }
    }

    protected function checkSuperlatives(QualityReport $report, string $field, string $text): void
    {
        $found = [];

        foreach (ProfileQualityCheck::SUPERLATIVES as $word) {
            array_push($found, ...ArabicText::find($text, $word));
        }

        foreach (array_unique($found) as $surface) {
            $report->add('superlative', QualityReport::WARNING, $field, "مبالغة مطلقة «{$surface}».");
        }
    }

    /** «فاخرة»، «الشهيرة»، «الطازجة» صفات لم يصف بها التاجر منتجه. (P1) */
    protected function checkPuffery(QualityReport $report, string $field, string $text, ContentFacts $facts): void
    {
        $found = [];

        foreach (config('claims.puffery', []) as $word) {
            if (! $facts->mentions($word)) {
                array_push($found, ...ArabicText::find($text, $word));
            }
        }

        foreach (array_unique($found) as $surface) {
            $report->add('puffery', QualityReport::WARNING, $field, "صفة «{$surface}» لم ترد في وصف المنتج.");
        }
    }

    /** «ملّيت» في نص فصيح (C3)، و«زهقت» في نص سعودي (C9). */
    protected function checkDialect(QualityReport $report, string $field, string $text, ?string $dialect): void
    {
        $config = config('dialects.'.($dialect ?: 'saudi'));

        if (! $config) {
            return;
        }

        $found = [];

        foreach ($config['avoid'] ?? [] as $word) {
            array_push($found, ...ArabicText::find($text, $word, stem: false));
        }

        foreach (array_unique($found) as $surface) {
            $report->add('dialect_leak', QualityReport::WARNING, $field, "«{$surface}» ليست من اللهجة المختارة ({$config['label']}).");
        }
    }

    /** «أسبيرو» بدل AeroPress. (C4·V2) */
    protected function checkLatinNames(QualityReport $report, string $field, string $text, ContentFacts $facts): void
    {
        foreach (config('content.latin_names', []) as $latin => $spellings) {
            foreach ($spellings as $spelling) {
                if (ArabicText::contains($text, $spelling, stem: false) && ! $facts->mentions($spelling, stem: false)) {
                    $report->add('transliterated_name', QualityReport::WARNING, $field, "«{$spelling}» يُكتب بحروفه: {$latin}.");

                    break;
                }
            }
        }
    }

    // ================================================================
    //  البنية
    // ================================================================

    protected function checkPlaceholders(QualityReport $report, string $field, string $text): void
    {
        if (preg_match('/\[نص تجريبي|\[كابشن تجريبي|\[سكربت تجريبي|lorem ipsum|\{\{|\}\}|\bTODO\b|\bundefined\b|\bnull\b/iu', $text)) {
            $report->add('placeholder', QualityReport::ERROR, $field, 'نص تجريبي أو أثر برمجي في المخرج.');
        }
    }

    /** النص كما سيُلصق في المنصة: الكابشن ثم الهاشتاقات. إكس 280 حرفاً. */
    protected function checkLength(QualityReport $report, array $content, ?string $platform): void
    {
        $platform = config("content.platforms.{$platform}");

        if (! $platform || empty($platform['caption_max'])) {
            return;
        }

        $tags = collect($content['hashtags'] ?? [])->map(fn ($t) => '#'.ltrim($t, '#'))->implode(' ');

        // الثريد: الحد لكل تغريدة، والهاشتاقات تُلحق بآخرها
        if ($tweets = $content['tweets'] ?? []) {
            foreach (array_values($tweets) as $index => $tweet) {
                $last = $index === count($tweets) - 1;
                $length = mb_strlen(trim($tweet.($last && $tags !== '' ? ' '.$tags : '')));

                if ($length > $platform['caption_max']) {
                    $report->add('too_long', QualityReport::ERROR, 'tweet:'.($index + 1),
                        "التغريدة {$length} حرفاً".($last && $tags !== '' ? ' مع الهاشتاقات' : '')."، وحد {$platform['label']} {$platform['caption_max']}.");
                }
            }

            return;
        }

        $length = mb_strlen(trim(($content['caption'] ?? '').($tags !== '' ? "\n\n".$tags : '')));

        if ($length > $platform['caption_max']) {
            $report->add('too_long', QualityReport::ERROR, 'caption',
                "النص {$length} حرفاً مع الهاشتاقات، وحد {$platform['label']} {$platform['caption_max']}.");
        }
    }

    protected function checkSlideCount(QualityReport $report, array $content, ?string $format, int $expected): void
    {
        $count = count($content['slides'] ?? []);

        if ($format === 'carousel' && $expected > 0 && $count !== $expected) {
            $report->add('slide_count', QualityReport::WARNING, 'content', "{$count} شرائح، والقالب يطلب {$expected}.");
        }
    }

    // ================================================================
    //  أدوات
    // ================================================================

    /**
     * نصوص المنشور بحقولها. الافتتاحية تُفحص وحدها فقط إن لم تكن
     * جزءاً من السكربت، وإلا بُلِّغ عن المشكلة الواحدة مرتين.
     *
     * @return array<string, string>
     */
    protected function texts(array $content): array
    {
        $texts = ['caption' => (string) ($content['caption'] ?? '')];

        foreach (array_values($content['slides'] ?? []) as $index => $slide) {
            $texts['slide:'.($index + 1)] = (string) (is_array($slide) ? ($slide['text'] ?? '') : $slide);
        }

        $script = (string) ($content['script'] ?? '');
        $hook = (string) ($content['hook'] ?? '');

        $texts['script'] = $script;

        // ما يسمعه الجمهور ويراه. التوقيت وتوجيه التصوير لمن يصوّر، لا يُنشران
        foreach (array_values($content['scenes'] ?? []) as $index => $scene) {
            $texts['scene:'.($index + 1)] = trim(($scene['voiceover'] ?? '')."\n".($scene['on_screen'] ?? ''));
        }

        $spoken = $script.' '.collect($content['scenes'] ?? [])->pluck('voiceover')->implode(' ');

        if ($hook !== '' && ! str_contains($spoken, $hook)) {
            $texts['hook'] = $hook;
        }

        foreach (array_values($content['frames'] ?? []) as $index => $frame) {
            $texts['frame:'.($index + 1)] = trim(($frame['text'] ?? '')."\n".($frame['interaction'] ?? ''));
        }

        foreach (array_values($content['tweets'] ?? []) as $index => $tweet) {
            $texts['tweet:'.($index + 1)] = (string) $tweet;
        }

        $texts['title'] = (string) ($content['title'] ?? '');

        foreach (array_values($content['sections'] ?? []) as $index => $section) {
            $texts['section:'.($index + 1)] = trim(($section['heading'] ?? '')."\n".($section['text'] ?? ''));
        }

        $texts['hashtags'] = collect($content['hashtags'] ?? [])
            ->map(fn ($t) => str_replace('_', ' ', ltrim((string) $t, '#')))
            ->implode(' ، ');

        return array_filter($texts, fn ($text) => trim($text) !== '');
    }

    /** @param array<int, string> $words */
    protected function firstHit(string $text, array $words): ?string
    {
        foreach ($words as $word) {
            if (($found = ArabicText::find($text, $word)) !== []) {
                return $found[0];
            }
        }

        return null;
    }
}
