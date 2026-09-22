<?php

namespace App\Services\Content;

use App\Enums\ContentFormat;
use App\Enums\JobStatus;
use App\Jobs\GenerateContentJob;
use App\Jobs\RewriteSlideJob;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\GenerationJob;
use App\Models\Product;
use App\Services\AI\AiManager;
use App\Services\AI\DTO\TextRequest;
use App\Services\AI\Prompts\PromptBuilder;
use App\Services\AI\ProviderException;
use App\Services\Content\Quality\ContentQualityCheck;
use App\Services\Credits\CreditService;
use App\Services\Credits\InsufficientCreditsException;
use App\Services\Quality\QualityReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContentGenerationService
{
    /** طلب التصحيح يُسجَّل باسمه في ai_usage_logs: كلفته علينا لا على التاجر. */
    public const CORRECTION_OPERATION = 'content.correction';

    /** تحذيرات تُصلح مع الأخطاء حين يُطلب تصحيح أصلاً — لا تستحق طلباً وحدها. */
    protected const FIX_WITH_ERRORS = ['superlative', 'puffery', 'dialect_leak', 'transliterated_name'];

    public function __construct(
        protected AiManager $ai,
        protected CreditService $credits,
        protected ContentQualityCheck $quality,
        protected Proofreader $proofreader,
    ) {}

    /**
     * دفعة صفحة «كتابة المحتوى»: مهمة لكل إعداد، تعمل بالتوازي وتفشل وحدها.
     *
     * الرصيد يُفحص للدفعة كلها قبل أي حجز: دفعة لا يكفيها الرصيد تُرفض كاملة،
     * لا تُنفَّذ أولها وتتوقف في منتصفها.
     *
     * الإعدادات المتطابقة في دفعة واحدة تأخذ زوايا مختلفة، وإلا خرجت نسخ شبه متطابقة.
     *
     * @param  array<int, array{goal:string, platform:string, format:string, option?:?string, product_id?:?int, language?:string, dialect?:?string, filming?:bool}>  $items
     * @return array<int, GenerationJob>
     */
    public function dispatchBatch(Brand $brand, array $items, ?int $userId = null): array
    {
        $total = collect($items)->sum(fn ($item) => $this->credits->cost($this->operationFor(ContentFormats::kind($item['format']))));
        $balance = $this->credits->balance($brand);

        if ($total > $balance) {
            throw new InsufficientCreditsException($total, $balance,
                "الرصيد غير كافٍ: الدفعة تحتاج {$total} نقطة والمتاح {$balance}.");
        }

        $angles = $this->batchAngles($brand, $items);

        return DB::transaction(fn () => collect($items)->map(function ($item, $i) use ($brand, $userId, $angles) {
            $product = filled($item['product_id'] ?? null) ? Product::forBrand($brand)->find($item['product_id']) : null;

            return $this->dispatch($brand, [
                'goal' => $item['goal'],
                'platform' => $item['platform'],
                'variant' => $item['format'],
                'option' => $item['option'] ?? null,
                'filming' => (bool) ($item['filming'] ?? false),
                'template' => ContentFormats::templateFor($item['goal'], $item['format'], $brand, $product),
                'product_id' => $product?->id,
                'general' => $product === null,
                'language' => $item['language'] ?? 'ar',
                'dialect' => ($item['language'] ?? 'ar') === 'ar' ? ($item['dialect'] ?? null) : null,
                'angle' => $angles[$i] ?? null,
                'draft' => true,
            ], $userId);
        })->all());
    }

    /**
     * «إعادة المحاولة»: المحتوى نفسه بإعداداته نفسها، في مهمة جديدة ونقاط جديدة.
     * النسخة السابقة تبقى حتى يحذفها التاجر: قد تكون الأفضل.
     */
    public function retry(Brand $brand, ContentItem $item, ?int $userId = null): GenerationJob
    {
        $options = (array) $item->options;

        return $this->dispatchBatch($brand, [[
            'goal' => $item->goal,
            'platform' => $item->platform,
            'format' => $item->variant,
            'option' => $options['option'] ?? null,
            'filming' => (bool) ($options['filming'] ?? false),
            'product_id' => $item->product_id,
            'language' => $item->language,
            'dialect' => $options['dialect'] ?? null,
        ]], $userId)[0];
    }

    /** @return array<int, string|null> */
    protected function batchAngles(Brand $brand, array $items): array
    {
        $key = fn ($item) => implode('|', [$item['goal'], $item['platform'], $item['format'], $item['product_id'] ?? '', $item['option'] ?? '']);
        $groups = collect($items)->map($key)->countBy();
        $seen = [];

        return collect($items)->map(function ($item) use ($brand, $key, $groups, &$seen) {
            $k = $key($item);

            if ($groups[$k] < 2) {
                return null;
            }

            $product = filled($item['product_id'] ?? null) ? Product::forBrand($brand)->find($item['product_id']) : null;
            $available = ContentRequirements::anglesFor($brand, $product);
            $n = $seen[$k] = ($seen[$k] ?? -1) + 1;

            return $available === [] ? null : $available[$n % count($available)];
        })->all();
    }

    /**
     * إنشاء مهمة توليد ودفعها للطابور.
     * لا يُستدعى النموذج داخل طلب HTTP أبداً.
     *
     * @param  array{goal:string, platform:string, template:string, variant?:?string, option?:?string, filming?:bool, product_id?:int|null, language?:string, dialect?:?string, angle?:?string, draft?:bool, occasion?:string|null, instructions?:string|null, quantity?:int}  $input
     */
    public function dispatch(Brand $brand, array $input, ?int $userId = null): GenerationJob
    {
        $template = $input['template'] ?? 'value_carousel';
        $format = config("content.templates.{$template}.format", 'post');
        $quantity = max((int) ($input['quantity'] ?? 1), 1);
        $operation = $this->operationFor($format);

        return DB::transaction(function () use ($brand, $input, $template, $format, $operation, $quantity, $userId) {
            $job = GenerationJob::create([
                'brand_id' => $brand->id,
                'user_id' => $userId,
                'type' => 'content',
                'status' => JobStatus::Queued,
                'payload' => array_filter([
                    'goal' => $input['goal'] ?? 'engagement',
                    'platform' => $input['platform'] ?? 'instagram',
                    'template' => $template,
                    'format' => $format,
                    'variant' => $input['variant'] ?? null,
                    'option' => $input['option'] ?? null,
                    'filming' => (bool) ($input['filming'] ?? false),
                    'product_id' => $input['product_id'] ?? null,
                    'general' => ($input['general'] ?? false) ?: null,
                    'language' => $input['language'] ?? 'ar',
                    'dialect' => $input['dialect'] ?? null,
                    'angle' => $input['angle'] ?? null,
                    'draft' => (bool) ($input['draft'] ?? false),
                    'occasion' => $input['occasion'] ?? null,
                    'instructions' => $input['instructions'] ?? null,
                    'quantity' => $quantity,
                ], fn ($value) => $value !== null),
            ]);

            // الحجز قبل الدفع للطابور: لا مهمة بلا رصيد مؤكد
            $this->credits->hold($brand, $operation, $quantity, $job);

            GenerateContentJob::dispatch($job->id)->onQueue('content');

            return $job->fresh();
        });
    }

    /**
     * التنفيذ الفعلي داخل العامل.
     */
    public function run(GenerationJob $job): void
    {
        $brand = $job->brand;
        $payload = $job->payload;
        $format = $payload['format'] ?? 'post';
        $quantity = (int) ($payload['quantity'] ?? 1);
        $operation = $this->operationFor($format);

        $job->markProcessing();

        // «بدون منتج» في صفحة الكتابة محتوى عام عن العلامة فعلاً، لا المنتج الأساسي خفيةً
        $product = match (true) {
            filled($payload['product_id'] ?? null) => Product::forBrand($brand)->find($payload['product_id']),
            (bool) ($payload['general'] ?? false) => null,
            default => $brand->primaryProduct(),
        };

        $builder = $this->builderFor($brand, $product, $payload);
        $angles = filled($payload['angle'] ?? null) ? [$payload['angle']] : $this->anglesFor($brand, $product, $quantity);

        $created = [];
        $failures = 0;
        $lastError = null;

        for ($i = 0; $i < $quantity; $i++) {
            try {
                $version = $this->generateVersion((clone $builder)->angle($angles[$i]), $job);

                if ($version === null) {
                    $failures++;

                    continue;
                }

                $draft = (bool) ($payload['draft'] ?? false);

                $item = ContentItem::create([
                    'brand_id' => $brand->id,
                    'product_id' => $product?->id,
                    'goal' => $payload['goal'] ?? 'engagement',
                    'platform' => $payload['platform'] ?? 'instagram',
                    'format' => $format,
                    'variant' => $payload['variant'] ?? null,
                    'template' => $payload['template'] ?? null,
                    'language' => $payload['language'] ?? 'ar',
                    // ما يلزم «إعادة المحاولة» لتكتب المحتوى نفسه بإعداداته نفسها
                    'options' => array_filter([
                        'option' => $payload['option'] ?? null,
                        'dialect' => ($payload['language'] ?? 'ar') === 'ar' ? $builder->dialect() : null,
                        'filming' => ($payload['filming'] ?? false) ?: null,
                    ], fn ($value) => $value !== null) ?: null,
                    // العروض التي رآها النموذج: الخطة تنبّه إن انتهى أحدها قبل موعد النشر
                    'body' => $version['content'] + array_filter([
                        'angle' => $angles[$i],
                        'offer_ids' => $builder->offerIds(),
                    ]),
                    'caption' => $version['content']['caption'],
                    'quality' => $this->qualityPayload($version['report'], $version['attempts'], $version['proofread']),
                    // من صفحة الكتابة: مسودة تنتظر «إضافة للخطة الشهرية»
                    'status' => $draft ? 'draft' : 'ready',
                    'in_plan' => ! $draft,
                    'generation_job_id' => $job->id,
                ]);

                $created[] = $item->id;
            } catch (\Throwable $e) {
                $failures++;
                $lastError = $e;
                report($e);

                // الحصة نفدت: كل نسخة تالية طلب ضائع من حصة لن تعود قبل ساعات
                if ($e instanceof ProviderException && $e->quotaExhausted) {
                    $failures += $quantity - $i - 1;

                    break;
                }
            }
        }

        // الفشل الجزئي ليس فشلاً كلياً: نسلّم ما نجح ونرجع نقاط ما فشل.
        // التصحيح لا يُحتسب: النقاط لكل نسخة سُلّمت، لا لكل طلب أُرسل.
        $consumedUnits = count($created);
        $held = (int) $job->credits_held;
        $unitCost = $this->credits->cost($operation);

        $this->credits->settle($brand, $held, $unitCost * $consumedUnits, $job, $operation);

        if ($created === []) {
            $job->markFailed($lastError instanceof ProviderException
                ? $lastError->userMessage()
                : 'لم يُنتج النموذج محتوى صالحاً بعد كل المحاولات.');

            return;
        }

        $job->markCompleted(
            ['content_item_ids' => $created, 'failed' => $failures],
            $failures > 0 ? 'partial' : 'completed'
        );
    }

    /**
     * برومبت المهمة كما سيُرسل، بلا زاوية. يستعمله التقييم (content:eval)
     * وإعادة الفحص بعد التعديل اليدوي، فيبنيان نفس دفتر الحقائق حرفياً.
     */
    public function builderFor(Brand $brand, ?Product $product, array $payload): PromptBuilder
    {
        return PromptBuilder::make()
            ->forBrand($brand)
            ->forProduct($product)
            ->goal($payload['goal'] ?? 'engagement')
            ->platform($payload['platform'] ?? 'instagram')
            ->template($payload['template'] ?? 'value_carousel')
            ->variant($payload['variant'] ?? null, isset($payload['option']) ? (string) $payload['option'] : null, (bool) ($payload['filming'] ?? false))
            ->language($payload['language'] ?? 'ar')
            ->withDialect($payload['dialect'] ?? null)
            ->occasion($payload['occasion'] ?? null)
            ->withInstructions($payload['instructions'] ?? null);
    }

    /**
     * نسخة واحدة عبر بوابة الصدق، بلا حفظ ولا نقاط.
     *
     * مخرج فيه خطأ (رقم أو كود أو ادعاء بلا أصل، كلمة ممنوعة) يُعاد مرة
     * واحدة مع مسودته والمشاكل بعينها، ويُحفظ الأقل أخطاءً. مرة لا أكثر:
     * كل محاولة طلب من حصة المزود، وما يبقى يظهر للتاجر «راجع قبل النشر».
     * فشل طلب التصحيح نفسه لا يُسقط النسخة: المسودة الأولى تُسلَّم بمشاكلها.
     *
     * بعد البوابة يمر التدقيق اللغوي (proofread)، ثم يُعاد الفحص على ما صحّحه.
     *
     * @return array{content: array, report: QualityReport, attempts: int, first_report: QualityReport, proofread: ?array}|null
     */
    public function generateVersion(PromptBuilder $builder, ?GenerationJob $job = null): ?array
    {
        $format = $builder->format();
        $first = $this->draft($builder, $this->operationFor($format), $job);

        if (! ContentSchema::isUsable($first, $format)) {
            return null;
        }

        $facts = $builder->facts();
        $options = $builder->qualityOptions();
        $report = $this->quality->check($first, $facts, $options);

        $result = ['content' => $first, 'report' => $report, 'attempts' => 1, 'first_report' => $report, 'proofread' => null];

        if (! $report->passes()) {
            $result = $this->correct($result, $builder, $facts, $options, $job);
        }

        return $this->proofread($result, $facts, $options, $job);
    }

    protected function correct(array $result, PromptBuilder $builder, ContentFacts $facts, array $options, ?GenerationJob $job): array
    {
        $result['attempts'] = 2;

        try {
            $retry = $this->draft($builder, self::CORRECTION_OPERATION, $job, $result['content'], $result['report']);
        } catch (\Throwable $e) {
            Log::warning('تعذّر طلب تصحيح المحتوى؛ سُلّمت المسودة الأولى بمشاكلها', [
                'job' => $job?->id,
                'error' => $e->getMessage(),
            ]);

            return $result;
        }

        if (! ContentSchema::isUsable($retry, $builder->format())) {
            return $result;
        }

        $retryReport = $this->quality->check($retry, $facts, $options);

        // الأقل أخطاءً يفوز؛ وعند التعادل التصحيح، لأنه كُتب وهو يعرف المشاكل
        if (count($retryReport->errors()) <= count($result['report']->errors())) {
            $result['content'] = $retry;
            $result['report'] = $retryReport;
        }

        return $result;
    }

    /**
     * التدقيق اللغوي: آخر خطوة، على النص الذي سيُحفظ.
     *
     * المدقق نموذج، فلا يُؤتمن: ما صحّحه يُفحص من جديد، وإن أدخل خطأً
     * لم يكن (رقماً أو ادعاءً) يُرفض تصحيحه كله. فشله لا يُسقط النسخة.
     */
    protected function proofread(array $result, ContentFacts $facts, array $options, ?GenerationJob $job): array
    {
        // المدقق عربي: يصحح الهمزات والتاء المربوطة، ولا شيء له في نص إنجليزي
        if (! config('ai.proofread.enabled', true) || ($options['language'] ?? 'ar') === 'en') {
            return $result;
        }

        try {
            $proof = $this->proofreader->proofread($result['content'], $options['dialect'] ?? 'saudi', $job);
        } catch (\Throwable $e) {
            Log::warning('تعذّر التدقيق اللغوي؛ سُلّم النص دون تدقيق', ['job' => $job?->id, 'error' => $e->getMessage()]);
            $result['proofread'] = ['status' => 'failed'];

            return $result;
        }

        if ($proof['changes'] === []) {
            $result['proofread'] = ['status' => 'clean', 'skipped' => $proof['skipped']];

            return $result;
        }

        $report = $this->quality->check($proof['content'], $facts, $options);
        $key = fn ($issue) => $issue['field'].'|'.$issue['message'];

        $introduced = collect($report->errors())->map($key)
            ->diff(collect($result['report']->errors())->map($key));

        if ($introduced->isNotEmpty()) {
            $result['proofread'] = ['status' => 'rejected', 'skipped' => $proof['skipped']];

            return $result;
        }

        $result['content'] = $proof['content'];
        $result['report'] = $report;
        $result['proofread'] = ['status' => 'applied', 'changes' => $proof['changes'], 'skipped' => $proof['skipped']];

        return $result;
    }

    /**
     * يعيد فحص منشور بعد أن عدّله التاجر بيده.
     * عدد المحاولات يبقى كما كان: وصفٌ لما حدث وقت التوليد.
     */
    public function recheck(ContentItem $item): array
    {
        $builder = $this->builderForItem($item);
        $report = $this->quality->check((array) $item->body, $builder->facts(), $this->itemOptions($item, $builder));

        return $this->qualityPayload($report, (int) ($item->quality['attempts'] ?? 1)) + ['edited' => true];
    }

    // ==================================================================
    //  شريحة واحدة (البند 4.4 في خطة التدقيق)
    // ==================================================================

    public const SLIDE_OPERATION = 'content.slide_regen';

    /**
     * إعادة كتابة شريحة واحدة، أو تحسينها بتوجيه، بنقطة واحدة (قرار §9-2).
     * المنافس لا يعيد إلا المحتوى كاملاً.
     */
    public function dispatchSlideRewrite(Brand $brand, ContentItem $item, int $index, ?string $direction = null, ?string $note = null, ?int $userId = null): GenerationJob
    {
        return DB::transaction(function () use ($brand, $item, $index, $direction, $note, $userId) {
            $job = GenerationJob::create([
                'brand_id' => $brand->id,
                'user_id' => $userId,
                'type' => 'slide_text',
                'status' => JobStatus::Queued,
                'payload' => ['content_item_id' => $item->id, 'index' => $index, 'direction' => $direction, 'note' => $note],
            ]);

            $this->credits->hold($brand, self::SLIDE_OPERATION, 1, $job);

            RewriteSlideJob::dispatch($job->id)->onQueue('content');

            return $job->fresh();
        });
    }

    /**
     * الشريحة الجديدة تمر بما يمر به المنشور كله: فحص الصدق، وتصحيح واحد إن لزم،
     * وتدقيق لغوي — على هذه الشريحة وحدها. باقي الشرائح لا يمسّها شيء.
     */
    public function rewriteSlide(GenerationJob $job): void
    {
        $brand = $job->brand;
        $payload = (array) $job->payload;
        $index = (int) ($payload['index'] ?? -1);

        $job->markProcessing();

        $item = ContentItem::forBrand($brand)->with(['product', 'mediaAssets'])->find($payload['content_item_id'] ?? 0);

        if (! $item || ! isset($item->slides()[$index])) {
            $this->credits->refund($brand, (int) $job->credits_held, $job, self::SLIDE_OPERATION, 'إرجاع: الشريحة لم تعد موجودة');
            $job->markFailed('الشريحة لم تعد موجودة. أُرجعت النقطة.');

            return;
        }

        $builder = $this->builderForItem($item);
        $facts = $builder->facts();
        $options = $this->itemOptions($item, $builder);
        $field = 'slide:'.($index + 1);

        $with = function (array $slide) use ($item, $index) {
            $body = (array) $item->body;
            $body['slides'][$index] = $slide;

            return $body;
        };
        $errorsIn = fn (QualityReport $report) => collect($report->errors())->where('field', $field)->values();

        $slide = $this->draftSlide($builder, $item, $index, $payload, $job);

        if ($slide === null) {
            $this->credits->refund($brand, (int) $job->credits_held, $job, self::SLIDE_OPERATION, 'إرجاع: مخرج غير صالح');
            $job->markFailed('لم يُنتج النموذج نصاً صالحاً للشريحة. أُرجعت النقطة.');

            return;
        }

        $report = $this->quality->check($with($slide), $facts, $options);

        if ($errorsIn($report)->isNotEmpty()) {
            try {
                $retry = $this->draftSlide($builder, $item, $index, $payload, $job, $errorsIn($report)->all());
                $retryReport = $retry ? $this->quality->check($with($retry), $facts, $options) : null;

                if ($retryReport && $errorsIn($retryReport)->count() <= $errorsIn($report)->count()) {
                    [$slide, $report] = [$retry, $retryReport];
                }
            } catch (\Throwable $e) {
                Log::warning('تعذّر تصحيح الشريحة؛ حُفظت كما كُتبت بمشاكلها', ['job' => $job->id, 'error' => $e->getMessage()]);
            }
        }

        if (config('ai.proofread.enabled', true) && ($options['language'] ?? 'ar') !== 'en') {
            try {
                $proof = $this->proofreader->proofread($with($slide), $options['dialect'] ?? 'saudi', $job, ['slide_'.($index + 1)]);
                $proofReport = $proof['changes'] ? $this->quality->check($proof['content'], $facts, $options) : null;

                if ($proofReport && $errorsIn($proofReport)->count() <= $errorsIn($report)->count()) {
                    $slide = $proof['content']['slides'][$index];
                }
            } catch (\Throwable $e) {
                Log::warning('تعذّر تدقيق الشريحة لغوياً', ['job' => $job->id, 'error' => $e->getMessage()]);
            }
        }

        $item->update(['body' => $with($slide)]);
        $item->update(['quality' => ['edited' => false, 'rewritten' => $index + 1] + $this->recheck($item->refresh())]);

        $held = (int) $job->credits_held;
        $this->credits->settle($brand, $held, $held, $job, self::SLIDE_OPERATION);

        $job->markCompleted(['content_item_id' => $item->id, 'index' => $index]);
    }

    /**
     * @param  array<int, array{message: string}>|null  $feedback
     * @return array<string, string>|null
     */
    protected function draftSlide(PromptBuilder $builder, ContentItem $item, int $index, array $payload, ?GenerationJob $job, ?array $feedback = null): ?array
    {
        $slides = $item->slides();
        $current = $slides[$index];
        $role = config("content.slide_roles.{$current['role']}", ['label' => $current['role'], 'directive' => '']);
        $direction = config('content.slide_directions.'.($payload['direction'] ?? '').'.directive');

        $list = collect($slides)
            ->map(fn ($slide, $i) => ($i + 1).". [{$slide['role']}] {$slide['text']}")
            ->implode("\n");

        $prompt = $builder->prompt()
            ."\n\n## المطلوب الآن: شريحة واحدة\n"
            ."هذه شرائح الكاروسيل كما هي الآن:\n{$list}\n\n"
            .'أعد كتابة الشريحة رقم '.($index + 1)." وحدها. دورها: {$role['label']} — {$role['directive']}\n"
            .'اكتبها مختلفة عن نصها الحالي، ومتسقة مع ما قبلها وما بعدها، دون تكرار ما في غيرها.'
            .($direction ? "\nالتوجيه: {$direction}" : '')
            .(filled($payload['note'] ?? null) ? "\nملاحظة صاحب المتجر على هذه الشريحة: {$payload['note']}" : '')
            .($feedback ? "\n\n## تصحيح مطلوب\nنسختك السابقة من الشريحة خالفت القواعد:\n"
                .collect($feedback)->map(fn ($i) => "- {$i['message']}")->unique()->implode("\n")
                ."\nاحذف المعلومة غير الموجودة في البيانات، ولا تستبدلها بمعلومة أخرى غير موجودة." : '');

        $slideSchema = ContentSchema::for('carousel', count($slides))['properties']['slides']['items'];

        $response = $this->ai->generateText(new TextRequest(
            system: $builder->system(),
            prompt: $prompt,
            schema: ['type' => 'object', 'required' => ['text', 'visual'], 'properties' => array_diff_key($slideSchema['properties'], ['role' => true])],
            temperature: $feedback ? 0.3 : 0.85,
            maxTokens: 800,
            operation: $feedback ? self::CORRECTION_OPERATION : self::SLIDE_OPERATION,
        ), $job);

        $slide = ContentSchema::slide(['role' => $current['role']] + (array) ($response->data ?? []));

        if ($slide['text'] === '') {
            return null;
        }

        // الصورة الحالية وُلدت من التوجيه القديم: يبقى ما لم يُكتب غيره
        if (! isset($slide['visual']) && isset($current['visual'])) {
            $slide['visual'] = $current['visual'];
        }

        return $slide;
    }

    /** برومبت المنشور كما وُلد: مناسبته وتعليماته من مهمته الأصلية. */
    protected function builderForItem(ContentItem $item): PromptBuilder
    {
        $job = $item->generation_job_id ? GenerationJob::find($item->generation_job_id) : null;

        $options = (array) $item->options;

        return $this->builderFor($item->brand, $item->product, [
            'goal' => $item->goal,
            'platform' => $item->platform,
            'template' => $item->template,
            'variant' => $item->variant,
            'option' => $options['option'] ?? null,
            'filming' => $options['filming'] ?? false,
            'language' => $item->language ?? 'ar',
            'dialect' => $options['dialect'] ?? null,
            'occasion' => $job?->payload['occasion'] ?? null,
            'instructions' => $job?->payload['instructions'] ?? null,
        ]);
    }

    /** الصيغة من المنشور نفسه: منشور قديم بلا قالب لا يُحاسب بشرائح قالب افتراضي. */
    protected function itemOptions(ContentItem $item, PromptBuilder $builder): array
    {
        return ['format' => $item->format->value, 'slides' => $item->template ? $builder->slideCount() : 0]
            + $builder->qualityOptions();
    }

    // ==================================================================

    protected function draft(
        PromptBuilder $builder,
        string $operation,
        ?GenerationJob $job,
        ?array $previous = null,
        ?QualityReport $feedback = null,
    ): array {
        $format = $builder->format();

        $response = $this->ai->generateText(new TextRequest(
            system: $builder->system(),
            prompt: $builder->prompt().$this->correction($previous, $feedback),
            schema: ContentSchema::for($format, $builder->slideCount(), $builder->shape()),
            // التصحيح يريد التزاماً لا تنويعاً
            temperature: $feedback ? 0.3 : 0.85,
            maxTokens: 2048,
            operation: $operation,
        ), $job);

        return ContentSchema::normalize($response->data, $format, $builder->slideCount(), $builder->platformKey());
    }

    /**
     * ما يُلحق بالبرومبت في محاولة التصحيح: المسودة نفسها والمشاكل بعينها.
     * المسودة تجعله يحرّر لا يعيد الكتابة، فيبقى ما كان سليماً سليماً.
     */
    protected function correction(?array $previous, ?QualityReport $feedback): string
    {
        if (! $previous || ! $feedback) {
            return '';
        }

        $problems = collect($feedback->issues())
            ->filter(fn ($i) => $i['severity'] === QualityReport::ERROR || in_array($i['code'], self::FIX_WITH_ERRORS, true))
            ->map(fn ($i) => '- ('.ContentQualityCheck::fieldLabel($i['field']).') '.$i['message'])
            ->unique()
            ->implode("\n");

        $draft = json_encode(
            array_filter($previous, fn ($value) => $value !== null && $value !== [] && $value !== ''),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        );

        return "\n\n## تصحيح مطلوب\n"
            ."هذه مسودتك السابقة:\n{$draft}\n\n"
            ."خالفت القواعد في هذه النقاط تحديداً:\n{$problems}\n\n"
            ."أعد المحتوى كاملاً بنفس البنية وعدد الشرائح. عدّل ما يلزم لإزالة هذه المشاكل وحدها، وأبقِ الباقي كما هو.\n"
            .'احذف المعلومة غير الموجودة في البيانات، ولا تستبدلها بمعلومة أخرى غير موجودة.';
    }

    /**
     * زاوية لكل نسخة من الدفعة. النسخة الواحدة بلا زاوية: القالب يكفيها.
     *
     * @return array<int, string|null>
     */
    protected function anglesFor(Brand $brand, ?Product $product, int $quantity): array
    {
        $available = $quantity > 1 ? ContentRequirements::anglesFor($brand, $product) : [];

        return array_map(
            fn ($i) => $available === [] ? null : $available[$i % count($available)],
            range(0, max($quantity, 1) - 1),
        );
    }

    protected function qualityPayload(QualityReport $report, int $attempts, ?array $proofread = null): array
    {
        return array_filter([
            'score' => $report->score(),
            'passes' => $report->passes(),
            'attempts' => $attempts,
            'issues' => $report->issues(),
            'proofread' => $proofread,
            'checked_at' => now()->toIso8601String(),
        ], fn ($value) => $value !== null);
    }

    public function operationFor(string $format): string
    {
        return (ContentFormat::tryFrom($format) ?? ContentFormat::Post)->creditOperation();
    }
}
