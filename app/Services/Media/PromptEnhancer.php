<?php

namespace App\Services\Media;

use App\Enums\JobStatus;
use App\Jobs\EnhancePromptJob;
use App\Models\Brand;
use App\Models\GenerationJob;
use App\Services\AI\AiManager;
use App\Services\AI\DTO\TextRequest;
use App\Services\Credits\CreditService;
use App\Support\Arabic\ArabicText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * تحسين وصف الصورة بالذكاء (زر العصا في /studio).
 *
 * يحوّل «كوب قهوة على طاولة» إلى مشهد محدد: خلفية وإضاءة وزاوية وتكوين، دون أن يغيّر
 * قصد التاجر ودون أن يخترع ما لم يقله. طلب نصي صغير لكنه يمر بالمسار الملزم كاملاً
 * (مهمة + طابور + AiManager + حجز نقاط) — لا استدعاء نموذج داخل طلب HTTP.
 *
 * النتيجة تمر بحارس خفيف قبل تسليمها: رقم أو ادعاء (خصم، ضمان، مجاني…) لم يذكره التاجر
 * يُعاد للنموذج مرة واحدة، فإن تكرر فشلت المهمة وأُرجعت النقاط بدل تسليم وصف مختلَق.
 */
class PromptEnhancer
{
    public const OPERATION = 'prompt.enhance';

    /** سقف ما نسلّمه: حقل الوصف في الاستوديو 1500، والمحسَّن فقرة قصيرة لا مقالة. */
    public const MAX_LENGTH = 900;

    public function __construct(protected AiManager $ai, protected CreditService $credits) {}

    /** @param  array{prompt: string, aspect_ratio?: ?string, product_id?: mixed, reference_asset_id?: mixed, use_brand_identity?: mixed}  $input */
    public function dispatch(Brand $brand, array $input, ?int $userId = null): GenerationJob
    {
        return DB::transaction(function () use ($brand, $input, $userId) {
            $job = GenerationJob::create([
                'brand_id' => $brand->id,
                'user_id' => $userId,
                'type' => 'prompt_enhance',
                'status' => JobStatus::Queued,
                'payload' => [
                    'prompt' => trim($input['prompt']),
                    'aspect_ratio' => $input['aspect_ratio'] ?? null,
                    'product_id' => $input['product_id'] ?? null,
                    'has_reference' => filled($input['reference_asset_id'] ?? null) || filled($input['product_id'] ?? null),
                    'use_brand_identity' => (bool) ($input['use_brand_identity'] ?? true),
                ],
            ]);

            $this->credits->hold($brand, self::OPERATION, 1, $job);

            // طابور «content» أولاً في ترتيب العامل: لا ينتظر خلف صور تستغرق دقائق
            EnhancePromptJob::dispatch($job->id)->onQueue('content');

            return $job->fresh();
        });
    }

    public function run(GenerationJob $job): void
    {
        $brand = $job->brand;
        $payload = (array) $job->payload;
        $original = trim((string) ($payload['prompt'] ?? ''));

        $job->markProcessing();

        $product = filled($payload['product_id'] ?? null) ? $brand->products()->find($payload['product_id']) : null;

        $enhanced = null;
        $problems = null;

        // محاولتان: الثانية بما رصده الحارس في الأولى
        foreach ([0.7, 0.4] as $temperature) {
            $response = $this->ai->generateText(new TextRequest(
                system: $this->system(),
                prompt: $this->userPrompt($brand, $product, $payload, $original, $problems),
                schema: ['type' => 'object', 'required' => ['enhanced'], 'properties' => ['enhanced' => ['type' => 'string']]],
                temperature: $temperature,
                maxTokens: 700,
                operation: self::OPERATION,
                model: config('ai.prompt_enhance.model'),
            ), $job);

            $candidate = $this->clean((string) ($response->data['enhanced'] ?? ''));
            $problems = $this->problems($original, $candidate);

            if ($problems === []) {
                $enhanced = $candidate;

                break;
            }
        }

        $held = (float) $job->credits_held;

        if ($enhanced === null) {
            $this->credits->refund($brand, $held, $job, self::OPERATION, 'إرجاع: تعذّر تحسين الوصف بأمان');
            $job->markFailed('تعذّر تحسين الوصف دون إضافة ما لم تذكره. عدّله يدوياً أو جرّب مرة أخرى.');

            return;
        }

        $this->credits->settle($brand, $held, $held, $job, self::OPERATION);
        $job->markCompleted(['prompt' => $enhanced, 'original' => $original]);
    }

    protected function system(): string
    {
        return <<<'TXT'
أنت محرر أوصاف للصور التسويقية التي يولّدها نموذج ذكاء اصطناعي. تحوّل وصف التاجر المختصر إلى مشهد بصري محدد يعطي صورة أفضل، دون أن تغيّر قصده.

القواعد:
1. احتفظ بكل ما ذكره التاجر: المنتج، والأشخاص، والأماكن، والألوان، والأعداد. لا تحذف شيئاً ولا تستبدله.
2. أضف ما ينقصه بصرياً فقط: الخلفية والسطح، والإضاءة، وزاوية التصوير، والتكوين، والجو العام، وملمس الأشياء، وعناصر مساندة طبيعية قليلة.
3. لا تخترع: لا أسماء علامات تجارية، ولا أسعاراً، ولا أرقاماً أو نسباً، ولا عروضاً أو خصومات أو ضمانات، ولا ادعاءات عن جودة المنتج أو أثره.
4. لا تطلب كتابة أي نص أو حروف أو أرقام أو شعارات أو عبارات داخل الصورة.
5. إن كان للمنتج صورة مرجعية فلا تصف شكل عبوته أو ألوانها أو ما كُتب عليها؛ حدّد موضعه في المشهد وما حوله فقط، ولا تكتب كلمة «مرجعية» أو «المرفقة» في الوصف.
6. اكتب بلغة الوصف الأصلي نفسها (العربي يبقى عربياً) في فقرة واحدة متصلة من جملتين إلى أربع جمل، بحد أقصى نحو 90 كلمة، بلا عناوين ولا قوائم ولا علامات اقتباس.
7. لا تُدخل أسلوب العلامة الذي يُضاف تلقائياً (ألوانها ونمطها)؛ ركّز على المشهد.

أعد الوصف الجديد وحده في الحقل المطلوب.
TXT;
    }

    /** @param  list<string>|null  $feedback */
    protected function userPrompt(Brand $brand, $product, array $payload, string $original, ?array $feedback): string
    {
        $context = array_filter([
            filled($brand->name) ? "المتجر: {$brand->name}" : null,
            $product ? "المنتج: {$product->title} — صورته ستُرفق مرجعاً، فلا تصف عبوته." : null,
            ! $product && ! empty($payload['has_reference']) ? 'ستُرفق صورة مرجعية: لا تصف مظهر ما فيها.' : null,
            ! empty($payload['use_brand_identity']) && filled($brand->visual_style) ? "نمط العلامة البصري (يُضاف تلقائياً فلا تكرره): {$brand->visual_style}" : null,
            filled($payload['aspect_ratio'] ?? null) ? "نسبة الصورة {$payload['aspect_ratio']}: اجعل التكوين مناسباً لها." : null,
        ]);

        return "وصف التاجر (حسّنه):\n«{$original}»"
            .($context ? "\n\nسياق للاسترشاد فقط:\n- ".implode("\n- ", $context) : '')
            .($feedback ? "\n\n## تصحيح مطلوب\nنسختك السابقة خالفت القواعد:\n- ".implode("\n- ", $feedback)."\nأعد كتابة الوصف دون هذه المخالفات." : '');
    }

    /** ينظّف ما يضيفه النموذج عادةً: اقتباس وتنسيق وتمهيد وفواصل أسطر. */
    protected function clean(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^(?:الوصف(?: المحسّن| المحسن)?|النتيجة)\s*[:：]\s*/u', '', $text);
        $text = preg_replace('/[*_`#]+/u', '', $text);
        $text = preg_replace('/\s*\R\s*/u', ' ', $text);
        $text = trim($text, " \t\n\r\0\x0B«»\"'“”");

        return Str::squish($text);
    }

    /**
     * ما يخالف قواعد الأمانة: قائمة بالعربية تُعاد للنموذج كما هي. فارغة = سليم.
     *
     * @return list<string>
     */
    protected function problems(string $original, string $enhanced): array
    {
        if ($enhanced === '') {
            return ['لم تُعد أي وصف.'];
        }

        $problems = [];

        if (mb_strlen($enhanced) > self::MAX_LENGTH) {
            $problems[] = 'الوصف أطول من اللازم: اختصره في فقرة واحدة قصيرة (حتى 90 كلمة).';
        }

        // أعداد لم يذكرها التاجر (خصم 50، سعر 30…): تُقارَن بعد توحيد الأرقام الهندية
        $numbers = fn (string $text) => collect(preg_match_all('/\d+/u', strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']), $m) ? $m[0] : [])->unique();
        $added = $numbers($enhanced)->diff($numbers($original));

        if ($added->isNotEmpty()) {
            $problems[] = 'أضفت رقماً ('.$added->first().') لم يذكره التاجر: احذف كل الأرقام غير المذكورة في وصفه.';
        }

        // ادعاءات (خصم/ضمان/مجاني/حصري/علاجي…) بمعجم بوابة الصدق نفسه
        foreach (config('claims.categories', []) as $category) {
            if (empty($category['always']) && $this->mentionsAny($original, $category['words'])) {
                continue;
            }

            if ($hit = $this->firstHit($enhanced, $category['words'])) {
                $problems[] = "«{$hit}»: {$category['label']} لم يذكره التاجر، فلا تُدخله.";
            }
        }

        // طلب كتابة داخل الصورة: النماذج ضعيفة في العربي، والنص يُركَّب فوق الصورة بقالبنا
        foreach ([
            '/(?<!\p{L})(?:مكتوب|مكتوبة|يكتب|كتابة|عبارة|لافتة|شعار)(?!\p{L})/u',
            '/\b(?:text saying|caption|slogan|logo|watermark)\b/iu',
        ] as $pattern) {
            if (preg_match($pattern, $enhanced, $hit) && ! preg_match($pattern, $original)) {
                $problems[] = "طلبت نصاً أو شعاراً داخل الصورة («{$hit[0]}»): احذفه، فالنص لا يُكتب داخل الصورة.";

                break;
            }
        }

        return $problems;
    }

    /** @param  list<string>  $words */
    protected function mentionsAny(string $text, array $words): bool
    {
        return $this->firstHit($text, $words) !== null;
    }

    /** @param  list<string>  $words */
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
