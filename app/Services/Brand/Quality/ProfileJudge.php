<?php

namespace App\Services\Brand\Quality;

use App\Enums\ProductType;
use App\Models\BrandProfile;
use App\Services\AI\AiManager;
use App\Services\AI\DTO\TextRequest;
use App\Support\Arabic\ArabicText;

/**
 * محكّم تقييم ملف الهوية: نموذج من عائلة غير عائلة المولّد يقرأ الإجابات والمخرج
 * ويحكم بمعايير ثابتة، ويقتبس كل خطأ بنصه.
 *
 * الفحص الآلي (ProfileQualityCheck) يلتقط ما يُقاس بالكلمة: رقم، رابط، كلمة ممنوعة.
 * ويفوته ما يحتاج فهماً: «فلا يشاركون صالة انتظار الكبار» تفصيل لم يقله التاجر
 * وليس فيه رقم ولا كلمة ممنوعة. هذا ما يحكم فيه المحكّم.
 *
 * الاقتباس يُطابق بالمخرج بعد التطبيع: خطأ لا يوجد نصه في المخرج «غير موثّق»
 * ويُعرض كذلك، فلا يُبنى قرار على ادعاء المحكّم وحده.
 */
class ProfileJudge
{
    public const OPERATION = 'eval.judge';

    /** المحاور بالترتيب الذي تُعرض به، ووصف كل منها كما يقرؤه المحكّم. */
    public const AXES = [
        'faithfulness' => 'الصدق: كل حقيقة عن المشروع (خدمة، ميزة، مدينة، شرط، جمهور) واردة في الإجابات بقيدها، بلا تفاصيل مستنتجة ولا تعميم يحوّل خياراً إلى كل النشاط',
        'constraints' => 'القيود: احترام ملاحظات التاجر والكلمات الممنوعة، وتحييد الادعاء المطلق والطبي والقانوني ولو كتبه التاجر',
        'classification' => 'التصنيف: نوع النشاط وملخص المبيعات يطابقان نموذج البيع الفعلي (جملة/تجزئة/الاثنان، سلع/خدمة، أفراد/منشآت)',
        'coverage' => 'التغطية: كل ميزة وقناة بيع ومدينة وردت في الإجابات حاضرة حيث يلزم، وبشكل صحيح',
        'consistency' => 'الاتساق: لا تناقض بين الملاحظات والوصفين، ولا بين المبسط والتفصيلي، ولا ملاحظة تمنع ما قاله التاجر عن نفسه',
        'language' => 'اللغة: نحو سليم (الإعراب، التذكير والتأنيث، العدد)، وأسلوب عربي طبيعي غير مترجم، ومستوى اللغة المطلوب',
        'specificity' => 'التميّز: نص خاص بهذا المشروع لا يصلح لمنافس، بلا تقليل من المنافسين أو البدائل',
        'audience_fit' => 'الجمهور: مخاطبة الجمهور المذكور بصيغته (مؤنث، منشآت، مراهقون…) وحاجته',
    ];

    public const CATEGORIES = [
        'fabrication', 'inferred_detail', 'overgeneralization', 'constraint_violation', 'unsafe_claim',
        'competitor_disparagement', 'misclassification', 'missing_coverage', 'contradiction', 'over_restriction',
        'grammar', 'register', 'generic', 'audience_mismatch', 'other',
    ];

    public const SEVERITIES = ['critical', 'high', 'medium', 'low'];

    public const CLASSIFICATIONS = ['retail', 'wholesale', 'both', 'service_b2c', 'service_b2b', 'service_both', 'unclear'];

    protected const FIELDS = ['simple', 'detailed', 'activity_type', 'sales_summary', 'advantages_directives', 'important_notes'];

    public function __construct(protected AiManager $ai) {}

    /**
     * @param  array  $case  الحالة: about، والمتوقع (expect.classification)، والعلامة
     * @param  array  $draft  مخرج BrandProfileGenerator::compose()
     * @return array{model: string, scores: array<string, int>, overall: float, classification: string, defects: array, summary: string, cost_usd: float, latency_ms: int}
     */
    public function judge(array $case, array $answers, array $draft): array
    {
        $response = $this->ai->generateText(new TextRequest(
            system: $this->system(),
            prompt: $this->prompt($case, $answers, $draft),
            schema: $this->schema(),
            temperature: 0.2,
            maxTokens: 6000,
            operation: self::OPERATION,
            model: config('ai.eval_judge.model') ?: null,
        ), null, config('ai.eval_judge.provider') ?: null);

        $data = (array) ($response->data ?? []);
        $scores = [];

        foreach (array_keys(self::AXES) as $axis) {
            $scores[$axis] = max(1, min(5, (int) data_get($data, "scores.{$axis}", 0) ?: 1));
        }

        $outputs = $this->outputs($draft);

        $defects = collect((array) ($data['defects'] ?? []))
            ->filter(fn ($d) => is_array($d) && filled($d['quote'] ?? null))
            ->map(fn (array $d) => [
                'field' => in_array($d['field'] ?? null, self::FIELDS, true) ? $d['field'] : 'other',
                'category' => in_array($d['category'] ?? null, self::CATEGORIES, true) ? $d['category'] : 'other',
                'severity' => in_array($d['severity'] ?? null, self::SEVERITIES, true) ? $d['severity'] : 'medium',
                'quote' => trim((string) $d['quote']),
                'why' => trim((string) ($d['why'] ?? '')),
                'fix' => trim((string) ($d['fix'] ?? '')),
                'verified' => $this->quoted($outputs, (string) ($d['field'] ?? ''), (string) $d['quote']),
            ])
            ->values()
            ->all();

        return [
            'model' => $response->provider.'/'.$response->model,
            'scores' => $scores,
            'overall' => round(array_sum($scores) / count($scores), 2),
            'classification' => in_array($data['classification'] ?? null, self::CLASSIFICATIONS, true) ? $data['classification'] : 'unclear',
            'defects' => $defects,
            'summary' => trim((string) ($data['summary'] ?? '')),
            'cost_usd' => $response->costUsd,
            'latency_ms' => $response->latencyMs,
        ];
    }

    protected function system(): string
    {
        $axes = collect(self::AXES)->map(fn ($text, $key) => "- {$key}: {$text}")->implode("\n");

        return <<<SYSTEM
        أنت مدقق جودة مستقل في شركة اختبار، تراجع ملف هوية علامة تجارية كتبه نموذج ذكاء اصطناعي
        من إجابات صاحب المشروع. لا تعيد الكتابة ولا تجامل: مهمتك أن تجد ما سيضر التاجر إن نُشر.

        ## المحاور (من 1 إلى 5 لكل محور؛ 5 = لا ملاحظة، 3 = عيب واضح يحتاج تعديلاً، 1 = غير صالح للنشر)
        {$axes}

        ## ما يُعد خطأً
        - حقيقة عن المشروع ليست في الإجابات: خدمة، شرط، مدة، مكان، ميزة، وصف مرافق، طريقة عمل. (fabrication أو inferred_detail)
        - تحويل خيار أو جزء إلى كل النشاط («نفصّل كل عباية» والتاجر يبيع جاهزاً ويفصّل عند الطلب). (overgeneralization)
        - إضافة شرط أو تضييق لم يقله التاجر («الاستبدال إن لم يوافق المقاس»). (inferred_detail)
        - وعد علاجي أو قانوني أو نتيجة مضمونة، أو صفة مطلقة (الأفضل، الأرخص، الأول). (unsafe_claim)
        - التقليل من المنافسين أو «المتاجر العادية» أو البديل المعتاد بصفات سلبية. (competitor_disparagement)
        - ملاحظة تمنع ما قاله التاجر عن نفسه أو تناقض الوصفين. (over_restriction أو contradiction)
        - تصنيف خاطئ لنموذج البيع أو الجمهور. (misclassification)

        ## ما ليس خطأً
        - مشهد من يوم العميل يصف حاجته («تنتهي من دوامك…») ما دام لا ينسب للمشروع حقيقة جديدة.
        - إعادة صياغة حقيقة واردة بألفاظ أخرى دون تغيير معناها أو قيدها.
        - ذكر اسم خدمة كما كتبه التاجر («علاج الجذور» في عيادة أسنان) — هذا اسم خدمة لا ادعاء.
        - كتابة الوصفين والوصف التقني بالفصحى ولو كانت لهجة العلامة سعودية: هذا مقصود، فالملف مرجع
          تقرؤه برومبتات أخرى، واللهجة تُطبَّق عند كتابة المنشورات. لا تحكم على اللهجة في هذه الحقول.

        ## اللهجة في الملاحظات وحدها
        الملاحظات (important_notes) تُحقن قيوداً ملزمة في برومبت كل منشور. ملاحظة تفرض لغة أو لهجة
        («اكتب بفصحى راقية») تناقض لهجة العلامة المضبوطة: هذا تناقض (contradiction). ولا خطأ في ملاحظة لا تذكر اللغة.

        ## الخطورة
        - critical: مخالفة نظامية أو وعد قد يُحاسب عليه التاجر، أو اختلاق حقيقة جوهرية.
        - high: معلومة مستنتجة أو شرط مضاف يضلل العميل، أو تصنيف خاطئ، أو قيد للتاجر لم يُحترم.
        - medium: تناقض، تشدد يضعف الصوت، تقليل من المنافسين، نص عام.
        - low: نحو أو أسلوب لا يغير المعنى.

        ## قواعد الإخراج
        - كل خطأ باقتباس حرفي منسوخ من المخرج كما هو (من 3 إلى 15 كلمة)، وحقله، وسبب قصير، وإصلاح مقترح.
        - لا تذكر خطأً بلا اقتباس. لا تكرر الخطأ نفسه لحقلين إلا إن ورد فيهما فعلاً.
        - classification: نموذج البيع كما يفهم من المخرج (activity_type وsales_summary)، لا من الإجابات.
        - summary: جملتان عن أهم ما في المخرج من قوة وضعف.
        SYSTEM;
    }

    protected function prompt(array $case, array $answers, array $draft): string
    {
        $type = ProductType::tryFrom((string) ($answers['type'] ?? '')) ?? ProductType::Good;

        $sheet = collect(BrandProfile::sheetFor($answers, $type))
            ->map(fn ($row) => "- {$row['question']}: ".($row['answered'] ? $row['answer'] : '(لم يُجب)'))
            ->implode("\n");

        $brand = (array) ($case['brand'] ?? []);
        $banned = implode('، ', (array) ($brand['banned_words'] ?? [])) ?: '—';
        $dialect = ($brand['dialect'] ?? 'saudi') === 'msa' ? 'فصحى' : 'سعودية';

        $technical = (array) ($draft['technical'] ?? []);
        $notes = collect((array) ($technical['important_notes'] ?? []))->map(fn ($n, $i) => ($i + 1).". {$n}")->implode("\n");

        return <<<PROMPT
        ## إجابات صاحب المشروع (المصدر الوحيد للحقائق)
        {$sheet}
        - كلمات ممنوعة: {$banned}
        - لهجة العلامة: {$dialect}

        ## ما تختبره هذه الحالة
        {$case['about']}
        نموذج البيع الفعلي: {$this->expected($case)}

        ## المخرج قيد التدقيق
        ### simple
        {$draft['simple']}

        ### detailed
        {$draft['detailed']}

        ### activity_type
        {$this->line($technical['activity_type'] ?? null)}

        ### sales_summary
        {$this->line($technical['sales_summary'] ?? null)}

        ### advantages_directives
        {$this->line($technical['advantages_directives'] ?? null)}

        ### important_notes
        {$notes}
        PROMPT;
    }

    protected function expected(array $case): string
    {
        return match ($case['expect']['classification'] ?? null) {
            'retail' => 'تجزئة للأفراد',
            'wholesale' => 'جملة للمنشآت فقط',
            'both' => 'جملة وتجزئة معاً',
            'service_b2c' => 'خدمة للأفراد',
            'service_b2b' => 'خدمة للمنشآت',
            'service_both' => 'خدمة للأفراد والمنشآت',
            default => 'غير محدد',
        };
    }

    protected function line(mixed $value): string
    {
        return filled($value) ? (string) $value : '(فارغ)';
    }

    protected function schema(): array
    {
        $scores = collect(array_keys(self::AXES))
            ->mapWithKeys(fn ($axis) => [$axis => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5]])
            ->all();

        return [
            'type' => 'object',
            'required' => ['scores', 'classification', 'defects', 'summary'],
            'properties' => [
                'scores' => ['type' => 'object', 'required' => array_keys(self::AXES), 'properties' => $scores],
                'classification' => ['type' => 'string', 'enum' => self::CLASSIFICATIONS],
                'defects' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['field', 'category', 'severity', 'quote', 'why', 'fix'],
                        'properties' => [
                            'field' => ['type' => 'string', 'enum' => self::FIELDS],
                            'category' => ['type' => 'string', 'enum' => self::CATEGORIES],
                            'severity' => ['type' => 'string', 'enum' => self::SEVERITIES],
                            'quote' => ['type' => 'string'],
                            'why' => ['type' => 'string'],
                            'fix' => ['type' => 'string'],
                        ],
                    ],
                ],
                'summary' => ['type' => 'string'],
            ],
        ];
    }

    /** @return array<string, string> نص كل حقل من المخرج كما يُطابق به الاقتباس */
    protected function outputs(array $draft): array
    {
        $technical = (array) ($draft['technical'] ?? []);

        return [
            'simple' => (string) ($draft['simple'] ?? ''),
            'detailed' => (string) ($draft['detailed'] ?? ''),
            'activity_type' => (string) ($technical['activity_type'] ?? ''),
            'sales_summary' => (string) ($technical['sales_summary'] ?? ''),
            'advantages_directives' => (string) ($technical['advantages_directives'] ?? ''),
            'important_notes' => implode("\n", (array) ($technical['important_notes'] ?? [])),
        ];
    }

    /**
     * هل الاقتباس في المخرج فعلاً؟ في حقله أولاً، ثم في أي حقل (قد يخطئ المحكّم اسم الحقل).
     * المطابقة بعد التطبيع وطيّ المسافات وعلامات الترقيم: المحكّم ينسخ ولا يلتزم الفواصل دائماً.
     */
    protected function quoted(array $outputs, string $field, string $quote): bool
    {
        $needle = $this->fold($quote);

        if ($needle === '') {
            return false;
        }

        $haystacks = isset($outputs[$field]) ? [$outputs[$field], ...array_values($outputs)] : array_values($outputs);

        foreach ($haystacks as $text) {
            if (str_contains($this->fold($text), $needle)) {
                return true;
            }
        }

        return false;
    }

    protected function fold(string $text): string
    {
        $text = ArabicText::normalize($text);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
