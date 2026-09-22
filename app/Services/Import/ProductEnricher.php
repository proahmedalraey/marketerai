<?php

namespace App\Services\Import;

use App\Models\Brand;
use App\Models\GenerationJob;
use App\Models\Product;
use App\Services\AI\AiManager;
use App\Services\AI\DTO\TextRequest;
use App\Services\Products\SpecSheetBuilder;
use App\Support\Arabic\ArabicText;
use Illuminate\Support\Str;

/**
 * يحوّل بيانات المتجر الخام إلى الحقول التي يحتاجها البرومبت.
 *
 * وصف المتجر مكتوب للمشتري، لا للنموذج: فيه حشو تسويقي وقليل من المواصفات.
 * هذه الخطوة تعيد ترتيبه إلى مميزات ومواصفات وجمهور — وهي نفسها الحقول
 * التي يكتبها المستخدم يدوياً، فلا يتفرع النظام إلى مسارين.
 */
class ProductEnricher
{
    public const OPERATION = 'product.spec_sheet';

    public function __construct(
        protected AiManager $ai,
        protected SpecSheetBuilder $specSheets,
    ) {}

    public function enrich(Product $product, ?GenerationJob $job = null): Product
    {
        $product->fill($this->fieldsFor($product, $job));
        $product->save();

        $product->update(['spec_sheet' => $this->specSheets->build($product->fresh())]);

        return $product->refresh();
    }

    /**
     * الحقول المُثراة دون حفظ.
     * يحتاجها استيراد الرابط الواحد: هناك نملأ النموذج أمام المستخدم
     * ولا ننشئ سجلاً قبل أن يضغط حفظ.
     *
     * @return array<string, string>
     */
    public function fieldsFor(Product $product, ?GenerationJob $job = null): array
    {
        $brand = $product->brand ?? \App\Support\CurrentBrand::get();

        $response = $this->ai->generateText(new TextRequest(
            system: $this->system($brand),
            prompt: $this->prompt($product, $brand),
            schema: $this->schema(),
            temperature: 0.4,
            maxTokens: 1200,
            operation: self::OPERATION,
        ), $job);

        $data = $response->data ?? [];

        return array_filter([
            'summary' => $this->clean($data['summary'] ?? null, 400),
            'features' => $this->clean($data['features'] ?? null, 5000),
            'specifications' => $this->clean($data['specifications'] ?? null, 5000),
            'audience' => $this->clean($data['audience'] ?? null, 1000) ?: $brand?->audience,
        ], fn ($value) => filled($value));
    }

    /**
     * وصف مختصر من محتوى النموذج الحالي لا من صفحة المتجر.
     * يستخدمه زر «إعادة التوليد» بعد أن يعدّل المستخدم المميزات بنفسه.
     */
    public function summaryFrom(Product $draft): ?string
    {
        $brand = $draft->brand ?? \App\Support\CurrentBrand::get();

        $source = array_filter([
            "الاسم: {$draft->title}",
            filled($draft->features) ? "المميزات:
{$draft->features}" : null,
            filled($draft->specifications) ? "المواصفات:
{$draft->specifications}" : null,
            filled($draft->audience) ? "الجمهور: {$draft->audience}" : null,
            $draft->price !== null ? 'السعر: '.$draft->money($draft->price) : null,
        ]);

        $response = $this->ai->generateText(new TextRequest(
            system: $this->system($brand),
            prompt: implode("
", $source)."

".implode("
", [
                'اكتب وصفاً مختصراً لهذا العنصر على شكل نقاط قصيرة، نقطة في كل سطر.',
                // «من ثلاث إلى خمس» كانت تجبر المنتج الناقص على الحشو (تدقيق المنافس P2)
                'حتى خمس نقاط، وبعدد المعلومات المتاحة أعلاه فقط: منتج بمعلومتين يأخذ نقطتين.',
                'كل نقطة جملة واحدة لا تتجاوز 12 كلمة، ولا تعيد معنى نقطة سابقة بصياغة أخرى.',
                'لا تكرر الاسم في كل نقطة، ولا تضف صفة أو معلومة غير موجودة أعلاه.',
                'أعد كائن JSON فيه الحقل summary فقط.',
            ]),
            schema: [
                'type' => 'object',
                'properties' => ['summary' => ['type' => 'string']],
                'required' => ['summary'],
            ],
            temperature: 0.5,
            maxTokens: 400,
            operation: self::OPERATION,
        ));

        $summary = $this->clean($response->data['summary'] ?? null, 600);

        return $summary !== null ? self::dropRepeatedBullets($summary) : null;
    }

    /**
     * يحذف النقطة التي تعيد نقطة سابقة بكلمات أخرى قليلة.
     *
     * «مطحنة يدوية للمنزل» ثم «المنتج مطحنة يدوية منزلية» حشو لا معلومة.
     * المقياس: نسبة الكلمات المشتركة إلى كلمات أقصر النقطتين، بلا حروف الجر.
     */
    public static function dropRepeatedBullets(string $summary): string
    {
        $stop = ['في', 'من', 'علي', 'الي', 'عن', 'مع', 'كل', 'لكل', 'هذا', 'هذه', 'او', 'ثم', 'المنتج', 'منتج'];

        $kept = [];
        $lines = [];

        foreach (preg_split('/\R+/u', $summary) ?: [] as $line) {
            $words = collect(ArabicText::tokens($line))
                ->map(fn ($w) => preg_replace('/^(?:وال|بال|ال|و)(?=.{3,})/u', '', ArabicText::normalize($w)))
                ->reject(fn ($w) => in_array($w, $stop, true) || mb_strlen($w) < 2)
                ->unique();

            if ($words->isEmpty()) {
                continue;
            }

            $repeats = collect($kept)->contains(function ($previous) use ($words) {
                $shared = $words->intersect($previous)->count();

                return $shared / min($words->count(), $previous->count()) >= 0.6;
            });

            if (! $repeats) {
                $kept[] = $words;
                $lines[] = trim($line);
            }
        }

        return implode("\n", $lines);
    }

    // ==================================================================

    protected function system(?Brand $brand): string
    {
        $lines = [
            'أنت محرر بيانات منتجات. مهمتك تحويل وصف متجر إلى بطاقة مرجعية دقيقة بالعربية.',
            'لا تخترع مواصفات أو أرقاماً غير موجودة في المصدر. ما لا تعرفه اتركه خارج النص.',
            'لا تكتب لغة تسويقية مبالغ فيها، ولا وعوداً مطلقة.',
            'إن كان وصف المتجر نصاً عاماً يصلح لأي منتج (مثل «تسوق الأصلي بأفضل سعر»)، فتجاهله واعتمد على اسم المنتج وعلامته ووزنه.',
        ];

        if ($brand?->audience) {
            $lines[] = "جمهور العلامة العام: {$brand->audience}";
        }

        if ($banned = array_filter((array) $brand?->banned_words)) {
            $lines[] = 'كلمات ممنوعة تماماً: '.implode('، ', $banned);
        }

        return implode("\n", $lines);
    }

    protected function prompt(Product $product, ?Brand $brand): string
    {
        $source = array_filter([
            "اسم المنتج: {$product->title}",
            filled($product->summary) ? "وصف المتجر (قد يكون نصاً عاماً مكرراً في كل منتجات المتجر): {$product->summary}" : null,
            $product->price !== null ? 'السعر: '.$product->money($product->price) : null,
            filled($product->category) ? "الفئة: {$product->category}" : null,
            filled($product->brand_name) ? "العلامة المصنّعة: {$product->brand_name}" : null,
            filled($product->sku) ? "رمز المنتج: {$product->sku}" : null,
            filled($brand?->industry) ? "مجال المتجر: {$brand->industry}" : null,
        ]);

        return implode("\n", $source)."\n\n".implode("\n", [
            'أعد كائن JSON بالحقول التالية:',
            '- summary: جملة أو جملتان تصفان المنتج بدقة (حتى 300 حرف).',
            '- features: المميزات والتفاصيل، نقطة في كل سطر، بلا ترقيم ولا رموز.',
            // «الفئة» كانت تُطلب دائماً فتُخترع حين تغيب — كما فعل المنافس (P3: «إمدادات القهوة والمشروبات»)
            '- specifications: المواصفات القابلة للقياس، سطر لكل واحدة بصيغة «الاسم: القيمة» — الوزن أو الحجم، والنوع، والعلامة التجارية، والسعر. استخرج الوزن والحجم من اسم المنتج إن لم يُذكر صراحة.',
            '  الفئة ورمز المنتج يُذكران فقط إن وردا أعلاه بنصهما. لا تستنتج فئة، ولا تسمِّ رمز المنتج «رقم موديل».',
            '- audience: من يشتري هذا المنتج تحديداً، بجملة واحدة.',
        ]);
    }

    protected function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string'],
                'features' => ['type' => 'string'],
                'specifications' => ['type' => 'string'],
                'audience' => ['type' => 'string'],
            ],
            'required' => ['summary', 'features', 'audience'],
        ];
    }

    protected function clean(mixed $value, int $limit): ?string
    {
        if (! is_string($value)) {
            return is_array($value) ? $this->clean(implode("\n", array_filter($value, 'is_scalar')), $limit) : null;
        }

        $text = trim(preg_replace("/[ \t]+/u", ' ', $value));

        return $text !== '' ? Str::limit($text, $limit, '') : null;
    }
}
