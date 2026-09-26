<?php

namespace App\Services\Brand;

use App\Enums\JobStatus;
use App\Enums\ProductType;
use App\Enums\ProfileSource;
use App\Jobs\GenerateBrandProfileJob;
use App\Models\Brand;
use App\Models\BrandProfile;
use App\Models\GenerationJob;
use App\Services\AI\AiManager;
use App\Services\AI\DTO\TextRequest;
use App\Services\AI\ProviderException;
use App\Services\Brand\Quality\ProfileQualityCheck;
use App\Services\Brand\Quality\ProfileQualityReport;
use App\Services\Credits\CreditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * يحوّل إجابات صاحب المشروع إلى ملف هوية بثلاث طبقات.
 *
 * ما هو حقيقة في الإجابات (الاسم، النوع، الرابط) لا يُطلب من النموذج:
 * يُنسخ كما هو. النموذج يكتب ما يحتاج صياغة فقط — وبهذا لا يخترع
 * رابطاً أو يغيّر اسماً مهما أخطأ.
 */
class BrandProfileGenerator
{
    public const OPERATION = 'brand.profile';

    /**
     * إصدار البرومبت. يُحفظ في كل نسخة، ونسخة كُتبت بإصدار أقدم لا يُبقى
     * وصفها التقني عند إعادة التوليد (stabilize) — وإلا ما وصل التحسين أبداً
     * لمن لم تتغير إجاباته. ارفعه مع كل تغيير جوهري في system() أو schema().
     */
    public const PROMPT_VERSION = 3;

    /** قيد أساسي يُثبَّت أولاً في كل ملف، ولو نسيه النموذج. */
    public const BASELINE_NOTE = 'لا تذكر أي تفاصيل غير واردة في المدخلات.';

    public const SCOPE_NOTE = 'لا تذكر منتجات أو خدمات غير الواردة في ملخص المبيعات.';

    public const ADVANTAGES_NOTE = 'المزايا التي يجوز إبرازها هي الواردة في توجيهات المزايا التنافسية وحدها.';

    /**
     * قيود يكتبها الكود لا النموذج، فتثبت في كل نسخة.
     * تدقيق المنافس (H1 ← H2): بنفس الإجابات حرفياً ولّد ثلاثة قيود ثم «لا توجد ملاحظات».
     */
    public const FIXED_NOTES = [self::BASELINE_NOTE, self::SCOPE_NOTE, self::ADVANTAGES_NOTE];

    /**
     * كل حقل تقني والإجابات التي يُكتب منها. إن لم تتغير إجاباته منذ النسخة
     * السابقة يبقى كما هو — بما فيه تعديل التاجر اليدوي عليه. تدقيق المنافس (H3):
     * تغيير إجابة واحدة أعاد كتابة كل شيء.
     */
    public const SOURCES = [
        'activity_type' => ['type', 'one_liner'],
        'sales_summary' => ['type', 'one_liner'],
        'advantages_directives' => ['advantages'],
        'important_notes' => ['type', 'one_liner', 'advantages', 'audience', 'notes'],
    ];

    public function __construct(
        protected AiManager $ai,
        protected CreditService $credits,
        protected ProfileQualityCheck $quality,
    ) {}

    /**
     * التوليد الأول مجاني: لا نطالب بنقاط قبل أن يرى المستخدم قيمة.
     */
    public function isFree(Brand $brand): bool
    {
        // النسخة الحالية نص تجريبي من المزود الوهمي: خلل عندنا، فإعادتها لا تُحتسب على التاجر
        return BrandProfile::forBrand($brand)->doesntExist()
            || (bool) BrandProfile::forBrand($brand)->active()->first()?->isPlaceholder();
    }

    public function cost(Brand $brand): int
    {
        return $this->isFree($brand) ? 0 : $this->credits->cost(self::OPERATION);
    }

    /**
     * المدخلات تُقرأ من العلامة لا من الطلب: مصدر واحد للإجابات،
     * ولقطتها تُحفظ في المهمة ثم في النسخة لكشف التأخر لاحقاً.
     */
    public function dispatch(Brand $brand, ?int $userId = null): GenerationJob
    {
        $this->adoptManualNotes($brand);

        $free = $this->isFree($brand);

        return DB::transaction(function () use ($brand, $userId, $free) {
            $job = GenerationJob::create([
                'brand_id' => $brand->id,
                'user_id' => $userId,
                'type' => 'brand_profile',
                'status' => JobStatus::Queued,
                'payload' => ['answers' => $brand->profileAnswers(), 'free' => $free],
            ]);

            if (! $free) {
                $this->credits->hold($brand, self::OPERATION, 1, $job);
            }

            GenerateBrandProfileJob::dispatch($job->id)->onQueue('content');

            return $job->fresh();
        });
    }

    public function run(GenerationJob $job): void
    {
        $brand = $job->brand;
        $answers = (array) ($job->payload['answers'] ?? []);

        $job->markProcessing();

        $previous = BrandProfile::forBrand($brand)->active()->first();

        $draft = $this->draft($brand, $answers, $job);

        if ($draft['simple'] === null || $draft['detailed'] === null) {
            // الفشل يُرجع النقاط كاملة: لا يدفع المستخدم مقابل ملف ناقص
            $this->credits->refund($brand, (float) $job->credits_held, $job, self::OPERATION, 'إرجاع: مخرج ناقص');
            $job->markFailed('لم يُنتج النموذج الوصفين المطلوبين. أُرجعت النقاط.');

            return;
        }

        $draft = $this->stabilize($draft, $previous, $answers, $brand);

        [$draft, $report, $attempts] = $this->withQualityGate($brand, $answers, $draft, $job, $previous);

        $profile = BrandProfile::createVersion($brand, [
            'answers' => $answers,
            'simple' => $draft['simple'],
            'detailed' => $draft['detailed'],
            'technical' => $draft['technical'],
            'quality' => [
                'score' => $report->score(),
                'passes' => $report->passes(),
                'attempts' => $attempts,
                'issues' => $report->issues(),
                // حقول بقيت من النسخة السابقة لأن إجاباتها لم تتغير
                'kept' => $draft['kept'] ?? [],
                'prompt_version' => self::PROMPT_VERSION,
                // من كتبها: «fake/…» يعني نصاً تجريبياً لا أوصافاً، والصفحة تقولها صراحة
                'model' => $draft['model'] ?? null,
                'checked_at' => now()->toIso8601String(),
            ],
            'source' => ProfileSource::Generated,
            'generation_job_id' => $job->id,
        ]);

        // «المجال» لم يعد سؤالاً: نكتبه من التحليل، فيبقى من يقرؤه (المنتجات والاستيراد) صحيحاً
        if (filled($profile->technical['activity_type'] ?? null)) {
            $brand->update(['industry' => Str::limit($profile->technical['activity_type'], 120, '')]);
        }

        $held = (float) $job->credits_held;
        $this->credits->settle($brand, $held, $held, $job, self::OPERATION);

        $job->markCompleted(['brand_profile_id' => $profile->id, 'version' => $profile->version]);
    }

    /**
     * استدعاء النموذج وتطبيع مخرجه، بلا حفظ ولا نقاط.
     *
     * منفصل عن run() ليستعمله تقييم الجودة (brand:eval-profile) على
     * نفس المسار حرفياً — برومبت ومخطط وتطبيعاً — دون أن يلمس بيانات أحد.
     *
     * @return array{brief: ?string, simple: ?string, detailed: ?string, technical: array<string, mixed>, raw: string, model: string, latency_ms: int}
     */
    public function draft(Brand $brand, array $answers, ?GenerationJob $job = null, ?ProfileQualityReport $feedback = null): array
    {
        [$provider, $model] = $this->route();

        $request = new TextRequest(
            system: $this->system($brand),
            prompt: $this->prompt($brand, $answers).$this->correction($feedback),
            schema: $this->schema(),
            // التصحيح يريد التزاماً لا تنويعاً
            temperature: $feedback ? 0.3 : 0.5,
            // الموجز الاستراتيجي يسبق الحقول، والعربية أثقل توكنزاً
            maxTokens: 3000,
            operation: self::OPERATION,
            model: $model,
        );

        try {
            $response = $this->ai->generateText($request, $job, $provider);
        } catch (ProviderException $e) {
            // نموذج الهوية مكتوب بخطأ أو سُحب أو ليس لهذا المزود: ملف بالنموذج الافتراضي خير من مهمة فاشلة
            if ($request->model === null || ! in_array($e->statusCode, [400, 404], true)) {
                throw $e;
            }

            Log::warning('نموذج الهوية غير صالح؛ أُعيد الطلب بنموذج المزود الافتراضي', [
                'model' => $request->model,
                'error' => mb_substr($e->getMessage(), 0, 300),
            ]);

            $request->model = null;
            $response = $this->ai->generateText($request, $job, $provider);
        }

        $data = (array) ($response->data ?? []);

        return [
            // للتقييم والتشخيص فقط: لا يُحفظ في النسخة ولا يُفحص
            'brief' => $this->text($data['brief'] ?? null, 1500),
            'simple' => $this->text($data['simple'] ?? null, 800),
            'detailed' => $this->text($data['detailed'] ?? null, 4000),
            'technical' => $this->technical($brand, $answers, $data),
            'raw' => $response->raw,
            'model' => $response->provider.'/'.$response->model,
            'latency_ms' => $response->latencyMs,
        ];
    }

    /**
     * مزود الهوية ونموذجها إن خُصّصا في config('ai.profile')، وإلا الافتراضي.
     *
     * النموذج وحده يكفي مع المزود الافتراضي (OpenRouter: «openai/gpt-4.1»).
     * مزود مخصص بلا مفتاح يُتجاوز بتحذير في السجل، ونموذجه معه لأنه اسم عنده
     * لا عند الافتراضي: ملف بالنموذج الافتراضي خير من مهمة فاشلة تُرجع النقاط.
     *
     * @return array{0: ?string, 1: ?string}
     */
    protected function route(): array
    {
        $provider = config('ai.profile.provider');

        if (blank($provider)) {
            return [null, config('ai.profile.model') ?: null];
        }

        if (! $this->ai->ready($provider)) {
            Log::warning("مزود الهوية [{$provider}] بلا مفتاح API؛ استُخدم مزود النص الافتراضي");

            return [null, null];
        }

        return [$provider, config('ai.profile.model') ?: null];
    }

    /**
     * بوابة الجودة: مخرج فيه خطأ (رقم أو رابط مخترع، كلمة ممنوعة) يُعاد
     * مرة واحدة مع تسمية المشاكل بعينها، ويُحفظ الأفضل من المحاولتين.
     *
     * مرة واحدة لا أكثر: كل محاولة طلبٌ من حصة المزود، والتصحيح الثاني نادراً
     * ما ينجح حيث فشل الأول. ما يبقى من مشاكل يظهر للمستخدم في الصفحة.
     * فشل طلب التصحيح نفسه لا يُسقط المهمة: المسودة الأولى صالحة للعرض.
     *
     * @return array{0: array, 1: ProfileQualityReport, 2: int}
     */
    protected function withQualityGate(Brand $brand, array $answers, array $draft, GenerationJob $job, ?BrandProfile $previous = null): array
    {
        $report = $this->quality->check($draft, $answers, (array) $brand->banned_words);

        if ($report->passes()) {
            return [$draft, $report, 1];
        }

        try {
            $retry = $this->stabilize($this->draft($brand, $answers, $job, $report), $previous, $answers, $brand);
        } catch (\Throwable $e) {
            Log::warning('تعذّر طلب تصحيح ملف الهوية؛ حُفظت المسودة الأولى', ['job' => $job->id, 'error' => $e->getMessage()]);

            return [$draft, $report, 2];
        }

        if ($retry['simple'] === null || $retry['detailed'] === null) {
            return [$draft, $report, 2];
        }

        $retryReport = $this->quality->check($retry, $answers, (array) $brand->banned_words);

        // الأقل أخطاءً يفوز؛ وعند التعادل التصحيح، لأنه كُتب وهو يعرف المشاكل
        return count($retryReport->errors()) <= count($report->errors())
            ? [$retry, $retryReport, 2]
            : [$draft, $report, 2];
    }

    /** ما يُلحق بالبرومبت في محاولة التصحيح: المشاكل بعينها، لا «أعد المحاولة» مبهمة. */
    protected function correction(?ProfileQualityReport $feedback): string
    {
        if (! $feedback) {
            return '';
        }

        $problems = collect($feedback->issues())
            // المبالغة والعبارة المستهلكة تحذيران، لكن ما دامت المحاولة قائمة فلتُصحَّحا معها
            ->filter(fn ($i) => $i['severity'] === ProfileQualityReport::ERROR || in_array($i['code'], ['superlative', 'cliche'], true))
            ->map(fn ($i) => "- ({$i['field']}) {$i['message']}")
            ->unique()
            ->implode("
");

        return "

## تصحيح مطلوب
"
            ."كتبتَ مسودة سابقة خالفت القواعد. أعد كتابة الملف كاملاً، وتجنّب هذه المشاكل تحديداً:
"
            .$problems
            ."
لا تذكر أي رقم أو رابط أو وصف غير موجود حرفياً في المدخلات أعلاه.";
    }

    /**
     * الوصف التقني = حقائق الإجابات + ما صاغه النموذج.
     *
     * @return array<string, mixed>
     */
    protected function technical(Brand $brand, array $answers, array $data): array
    {
        $type = ProductType::tryFrom((string) ($answers['type'] ?? '')) ?? ProductType::Good;

        $fixed = array_map([self::class, 'noteKey'], self::FIXED_NOTES);

        $generated = collect(is_array($data['important_notes'] ?? null)
                ? $data['important_notes']
                : preg_split('/\R+/u', (string) ($data['important_notes'] ?? '')))
            ->map(fn ($note) => trim(preg_replace('/^\s*(\d+[.)-]|[-•*])\s*/u', '', (string) $note)))
            ->filter()
            ->reject(fn ($note) => in_array(self::noteKey($note), $fixed, true))
            ->take(5);

        $notes = collect(self::FIXED_NOTES)->merge($generated)->values()->all();

        return [
            'project_type' => $type === ProductType::Service ? 'خدمة' : 'سلع',
            'project_name' => $answers['project_name'] ?: $brand->name,
            'activity_type' => $this->text($data['activity_type'] ?? null, 300) ?? ($brand->industry ?: ''),
            'sales_summary' => $this->text($data['sales_summary'] ?? null, 1500) ?? $answers['one_liner'],
            'advantages_directives' => $this->text($data['advantages_directives'] ?? null, 1500) ?? $answers['advantages'],
            'important_notes' => $notes,
            'store_url' => $answers['store_url'] ?: ($brand->store_url ?: ''),
        ];
    }

    /**
     * يُبقي من النسخة السابقة كل حقل تقني لم تتغير إجاباته.
     *
     * النموذج يعيد صياغة كل شيء في كل مرة، حتى بلا سبب (تدقيق المنافس H2: لفظ جديد
     * 100% ومعنى جديد 0%). والوصف التقني يُقرأ قبل كل منشور: صياغة جديدة بلا سبب
     * تعني محتوى يتغير بلا سبب. الوصفان المبسط والتفصيلي يُعاد توليدهما دائماً.
     */
    protected function stabilize(array $draft, ?BrandProfile $previous, array $answers, Brand $brand): array
    {
        // نسخة تجريبية من المزود الوهمي ليس فيها ما يستحق الإبقاء،
        // ونسخة ببرومبت أقدم يُعاد كل ما فيها: تحسين البرومبت سبب كافٍ
        if (! $previous || $previous->isPlaceholder() || $previous->writtenByOlderPrompt()) {
            return $draft;
        }

        $before = (array) $previous->answers;
        $unchanged = fn (array $keys) => collect($keys)->every(
            fn ($key) => trim((string) ($before[$key] ?? '')) === trim((string) ($answers[$key] ?? ''))
        );

        $kept = [];

        foreach (self::SOURCES as $field => $sources) {
            if (! $unchanged($sources)) {
                continue;
            }

            if ($field === 'important_notes') {
                // القيود الثابتة تُكتب من جديد دائماً؛ وقواعد التاجر لها حقلها، فلا تتكرر هنا
                $rules = array_map([self::class, 'noteKey'], (array) $brand->content_rules);
                $fixed = array_map([self::class, 'noteKey'], self::FIXED_NOTES);

                $extras = collect($previous->constraints())
                    ->reject(fn ($note) => in_array(self::noteKey($note), [...$fixed, ...$rules], true))
                    ->values()
                    ->all();

                if ($extras === []) {
                    continue;
                }

                $draft['technical']['important_notes'] = [...self::FIXED_NOTES, ...$extras];
                $kept[] = $field;

                continue;
            }

            $value = trim((string) ($previous->technical[$field] ?? ''));

            if ($value !== '') {
                $draft['technical'][$field] = $value;
                $kept[] = $field;
            }
        }

        $draft['kept'] = $kept;

        return $draft;
    }

    /**
     * ملاحظات أضافها التاجر يدوياً قبل أن يكون لقواعده حقل مستقل
     * تنتقل إلى قواعده قبل إعادة التوليد، وإلا مسحها التوليد.
     *
     * المضاف = ما في النسخة اليدوية النشطة وليس في آخر نسخة مولّدة قبلها.
     * وما يخالف القواعد (مبالغة، معلومة بلا مصدر) لا يُنقل: لم يكن سيُنفَّذ أصلاً.
     */
    public function adoptManualNotes(Brand $brand): void
    {
        $active = BrandProfile::forBrand($brand)->active()->first();

        if (! $active || $active->source !== ProfileSource::ManualEdit) {
            return;
        }

        $generated = BrandProfile::forBrand($brand)
            ->where('source', ProfileSource::Generated)
            ->where('version', '<', $active->version)
            ->orderByDesc('version')
            ->first();

        $known = array_map([self::class, 'noteKey'], [
            ...self::FIXED_NOTES,
            ...($generated?->constraints() ?? []),
            ...(array) $brand->content_rules,
        ]);

        $conflicts = app(ClaimConflicts::class);

        $added = collect($active->constraints())
            ->reject(fn ($note) => in_array(self::noteKey($note), $known, true))
            ->filter(fn ($note) => $conflicts->inRule($note) === null)
            ->values()
            ->all();

        if ($added !== []) {
            $brand->update(['content_rules' => [...(array) $brand->content_rules, ...$added]]);
        }
    }

    /** مفتاح مقارنة الملاحظات: «ركّز على المقاهي.» و«ركز على المقاهي» ملاحظة واحدة. */
    public static function noteKey(string $note): string
    {
        return trim(preg_replace('/[\s.،,!؟?]+/u', ' ', \App\Support\Arabic\ArabicText::normalize($note)));
    }

    /**
     * برومبت النظام: منهجية استراتيجي علامات لا تعليمات تلخيص.
     *
     * الإصدار السابق قدّم «الدقة على البلاغة» وقال إن الملف «لا يُنشر»، بينما
     * الوصفان يُنشران حرفياً في البايو و«من نحن» — فخرجا سرداً مسطحاً للإجابات.
     * هنا: لمن يُكتب كل حقل، وموجز استراتيجي قبل الكتابة (brief)، وتحويل كل
     * ميزة إلى فائدة دون حقيقة جديدة، ومعايير صنعة تقصي النص القالبي.
     *
     * الأوصاف مرجعية تُقرأ داخل برومبتات أخرى، فتُكتب بفصحى واضحة.
     * اللهجة تُطبَّق لاحقاً عند كتابة المحتوى نفسه، لا هنا.
     */
    protected function system(Brand $brand): string
    {
        $banned = $brand->banned_words
            ? "\n- لا تستخدم هذه الكلمات إطلاقاً بأي تصريف: ".implode('، ', $brand->banned_words)
            : '';

        $tone = filled($brand->tone)
            ? "\n- نبرة العلامة كما حددها صاحبها: «{$brand->tone}». اجعلها تظهر في اختيار الكلمات وإيقاع الجمل، مع بقاء اللغة فصحى."
            : '';

        $cliches = implode('»، «', (array) config('brand.profile_cliches', []));

        return <<<SYSTEM
        أنت استراتيجي علامات تجارية أول وكاتب إعلانات عربي محترف، تبني هويات المتاجر
        والمشاريع في السعودية والخليج. تكتب ملف هوية يُقرأ كأن خبيراً جلس مع صاحب
        المشروع ساعة كاملة وفهم عمله — لا نصاً عاماً يصلح لأي متجر.

        ## لمن يُكتب كل حقل
        - simple وdetailed: يُنشران كما هما في البايو وصفحة «من نحن» ووصف المتجر. يقرؤهما العميل، فهما نص تسويقي مصقول.
        - activity_type وsales_summary وadvantages_directives وimportant_notes: موجز داخلي يقرؤه كاتب محتوى آلي قبل كل منشور. تلغرافي عملي، بلا لغة تسويقية.

        ## المنهجية: فكّر قبل أن تكتب (في حقل brief أولاً)
        1. الجمهور وحاجته: من الجمهور المذكور؟ وما الذي يريد إنجازه حين يبحث عن مشروع كهذا؟ استنتج الحاجة من طبيعة الجمهور والنشاط، ولا تنسب للمشروع شيئاً لم يرد.
        2. الوعد الجوهري: جملة واحدة: «[المشروع] يمكّن [الجمهور] من [نتيجة] عبر [ما يقدّمه فعلاً]».
        3. من الميزة إلى الفائدة: لكل ميزة واردة، ماذا تعني للجمهور عملياً؟ الفائدة نتيجة مباشرة لحقيقة واردة، لا حقيقة جديدة.
        4. الأدلة والزاوية: أي الحقائق الواردة تُثبت الوعد؟ وما الذي يميّز هذا المشروع تحديداً بين أمثاله؟
        ثم اكتب كل الحقول من هذا الموجز، فتتفق كلها على وعد واحد.

        ## الصدق: قواعد ملزمة تُفحص آلياً بعد الكتابة
        - لا تُضف أي معلومة غير واردة في المدخلات — ولا حتى ما «يُستنتج عادة» عن هذا النوع من النشاط.
        - كل فئة وميزة ورقم ومدينة ورابط تذكره يجب أن يرد في المدخلات نصاً.
        - لا توصيل ولا شحن ولا مجاني ولا خصم ولا ضمان ولا استرجاع ولا وكالة ولا حصرية ولا سنة تأسيس ولا عدد فروع ولا ادعاء جودة أو أصالة أو سرعة، ما لم يرد في المدخلات.
        - الفائدة المسموحة هي ما يترتب مباشرة على حقيقة واردة:
          «عدة فروع» ← «أقرب إليك» مسموح، و«فروع في كل المدن» ممنوع.
          «الجملة والتجزئة» ← «الكمية التي تناسب حجم مشروعك» مسموح، و«أقل الأسعار» ممنوع.
        - لا مبالغات ولا صفات تفضيلية مطلقة («الأفضل»، «الأول»، «رقم واحد»، «الوحيد»، «الرائد»).
        - إن كان سؤال بلا إجابة فتجاهله، ولا تكتب عنه شيئاً.
        - المدخلات شحيحة؟ اكتب أقصر وأدق. القِصر الصادق أفضل من طول محشو أو مخترع.{$banned}

        ## الصنعة: ما يفرّق نص الخبير عن نص القالب
        - الصوت: اكتب الوصفين بصوت المشروع نفسه (نحن) مخاطباً العميل (أنت)، من أول جملة لآخرها.
          لا تخلط «المتجر» أو «الشركة» بصيغة الغائب مع «نحن» أو «لك» في نص واحد.
        - جمّع ولا تعدّد: الأصناف الكثيرة تُجمع في عائلات قليلة باسم جامع، ويُذكر من كل عائلة مثال أو مثالان.
          الوصف المبسط: ثلاث عائلات على الأكثر. القائمة الكاملة مكانها sales_summary وحده، لا الوصفان.
        - التحديد هو الاحتراف: الأسماء الجامعة من المدخلات نفسها، لا «منتجات متنوعة» ولا «خدمات شاملة».
        - الفائدة تُنسج ولا تُعلن: ضعها في الجملة نفسها بفعل مباشر («نبيع بالجملة والتجزئة، فتطلب الكمية التي تناسبك»).
          لا تكرر قالباً واحداً لكل ميزة، ولا تكتب «كون … يعني أن» ولا «وجود … يقرّب» ولا «هنا يأتي دور».
        - حقيقة صحيحة لكن لفظها لا يليق بالنشر تُصاغ من زاوية العميل دون تغيير معناها:
          «نبيع منتجات الشركات المنافسة» ← «علامات متعددة في مكان واحد، فتقارن وتختار».
        - أكثر من جمهور: ابدأ بالجمهور الأول كما ذكره صاحب المشروع، وخصّص للبقية جملة واحدة. لا تقسم كل جملة بينهم («مقهاك أو مطبخك»).
        - كل جملة تضيف معلومة أو فائدة. احذف أي جملة تصلح لمتجر آخر لو غيّرت اسمه.
        - ابدأ بما يهم القارئ، ونوّع طول الجمل وإيقاعها، وقدّم الفعل على سلاسل الإضافة.
        - عبارات مستهلكة أو مترجمة ممنوعة لأنها تجعل النص عاماً ضعيفاً: «{$cliches}».
        - عربية فصحى معاصرة سلسة، كما يكتبها كاتب إعلانات سعودي محترف لا مترجم آلي. بلا Markdown ولا رموز تعبيرية ولا علامات تعجب.{$tone}

        ## مثال للمستوى المطلوب (مشروع آخر، لا تنسخ ألفاظه)
        المدخلات: نبيع أحذية جري وملابس رياضية وجوارب ضغط · نقيس طريقة الجري في المتجر · العدّاؤون في الرياض
        ضعيف: «متجر خطوة متجر يبيع أحذية الجري والملابس الرياضية وجوارب الضغط، ويتميز بقياس طريقة الجري، ويستهدف العدّائين في الرياض.»
        قوي: «في خطوة نختار معك حذاء الجري على مقاس خطوتك: نقيس طريقة جريك في المتجر، ثم نكمل تجهيزك بالملابس الرياضية وجوارب الضغط. لكل عدّاء في الرياض.»
        الفرق: صوت واحد يخاطب العميل، الميزة صارت فعلاً يعيشه، والأصناف في جملة واحدة لا قائمة.

        ## الحقول
        - brief: موجزك الاستراتيجي من المنهجية (لا يُنشر): أربعة أسطر قصيرة — الحاجة · الوعد الجوهري · الأدلة من المدخلات · الزاوية المميزة.
        - simple: فقرة واحدة من 35 إلى 60 كلمة تُفهم وحدها في البايو: اسم المشروع، وماذا يقدّم بالتحديد، ولمن، وأقوى سبب وارد يدفع العميل لاختياره.
        - detailed: من 120 إلى 200 كلمة في فقرتين أو ثلاث يفصل بينها سطر فارغ:
          افتتاحية تضع القارئ في حاجته ثم تقدّم المشروع حلاً لها؛
          ثم ما يقدّمه في عائلات مع أمثلة منها؛ ثم المزايا الواردة منسوجة بفائدتها للعميل؛
          ثم خاتمة هادئة تدعو للتصفح أو الطلب عبر القنوات الواردة فقط.
        - activity_type: سطر واحد لا يتجاوز 12 كلمة يصنّف النشاط بدقة: النشاط + الفئة الرئيسية + لمن إن كان يبيع لأنشطة تجارية.
        - sales_summary: كل ما يبيعه المشروع كما ورد، مجمّعاً في مجموعات بصيغة تلغرافية تفصل بينها فواصل منقوطة.
        - advantages_directives: سطر لكل ميزة واردة بهذا الشكل: «الميزة كما وردت ← فائدتها للجمهور ← متى يبرزها كاتب المحتوى».
          إن لم ترد مزايا محددة فسطر واحد: «لم تُذكر مزايا محددة؛ لا تنسب للمشروع ميزة، وركّز على وضوح ما يقدّمه ولمن».
        - important_notes: من 3 إلى 5 تعليمات قصيرة (20 كلمة على الأكثر) تبدأ بفعل أمر، وكل منها قرار خاص بهذا المشروع:
          من يُخاطَب وبأي لغة، وأي ميزة تتقدّم ومتى، وما يُتجنّب (مثل خدمة نفاها صاحب المشروع أو قيد على ميزة).
          لا تعليمات عامة تصلح لأي مشروع مثل «اكتب بأسلوب جذاب».
          هذه مثبتة سلفاً فلا تكررها: {$this->fixedNotesLine()}
        SYSTEM;
    }

    protected function fixedNotesLine(): string
    {
        return implode(' / ', self::FIXED_NOTES);
    }

    protected function prompt(Brand $brand, array $answers): string
    {
        $type = ProductType::tryFrom((string) ($answers['type'] ?? '')) ?? ProductType::Good;

        $lines = array_filter([
            "نوع المشروع: {$type->label()}",
            'اسم المشروع: '.($answers['project_name'] ?: $brand->name),
            filled($answers['store_url'] ?? null) ? "رابط المتجر: {$answers['store_url']}" : null,
        ]);

        $questions = BrandProfile::questions($type);

        foreach (['one_liner', 'advantages', 'audience', 'notes'] as $key) {
            $answer = trim((string) ($answers[$key] ?? ''));

            $lines[] = "\nس: {$questions[$key]}\nج: ".($answer !== '' ? $answer : BrandProfile::UNANSWERED);
        }

        return "## مدخلات المشروع\n".implode("\n", $lines)
            ."\n\nابدأ بالموجز الاستراتيجي، ثم اكتب الحقول من هذه المدخلات وحدها.";
    }

    /**
     * مخطط مسطّح: المزود الوهمي يبنيه من خصائصه، والنماذج الحقيقية تلتزم به أسهل.
     *
     * brief أولاً عمداً: النموذج يكتب الحقول بترتيبها، فيخطط قبل أن يكتب
     * (وGemini يلتزم بالترتيب عبر propertyOrdering). لا يُحفظ في النسخة.
     */
    protected function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'brief' => ['type' => 'string'],
                'simple' => ['type' => 'string'],
                'detailed' => ['type' => 'string'],
                'activity_type' => ['type' => 'string'],
                'sales_summary' => ['type' => 'string'],
                'advantages_directives' => ['type' => 'string'],
                'important_notes' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['brief', 'simple', 'detailed', 'activity_type', 'sales_summary', 'advantages_directives', 'important_notes'],
        ];
    }

    protected function text(mixed $value, int $limit): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $this->trimToSentence($value, $limit);
    }

    /**
     * يقطع عند آخر نهاية جملة قبل الحد، لا في منتصف كلمة.
     *
     * Str::limit كان يقطع عند الحرف رقم 800 أياً كان، فيظهر الوصف
     * مبتوراً بـ «…». نقبل نهاية الجملة إن أبقت 60% من المساحة على الأقل؛
     * وإلا نقطع عند آخر كلمة كاملة ونُبقي «…» علامةً صادقة على القطع.
     */
    protected function trimToSentence(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $cut = mb_substr($text, 0, $limit);

        // الإزاحات هنا بالبايت، والقطع بالبايت على نفس النص فلا تنكسر الحروف
        if (preg_match_all('/[.!?؟…](?=\s|$)/u', $cut, $m, PREG_OFFSET_CAPTURE)) {
            [$mark, $offset] = end($m[0]);
            $sentence = substr($cut, 0, $offset + strlen($mark));

            if (mb_strlen($sentence) >= $limit * 0.6) {
                return trim($sentence);
            }
        }

        return rtrim(preg_replace('/\s+\S*$/u', '', $cut)).'…';
    }
}
