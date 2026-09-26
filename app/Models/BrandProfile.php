<?php

namespace App\Models;

use App\Enums\ProductType;
use App\Enums\ProfileSource;
use App\Models\Concerns\BelongsToBrand;
use App\Services\Brand\BrandProfileGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * نسخة واحدة من ملف هوية المشروع.
 *
 * النسخ لا تُستبدل بل تتراكم: إعادة التوليد تضيف نسخة وتُبقي ما قبلها،
 * فيبقى للمستخدم طريق رجوع بعد كل ضغطة «أعد التوليد».
 */
class BrandProfile extends Model
{
    use BelongsToBrand;

    /** نص يُحفظ حرفياً بدل ترك السؤال فارغاً — الفراغ لا يقول إن كان سهواً أو رفضاً. */
    public const UNANSWERED = 'لم يتم الإجابة';

    protected $fillable = [
        'brand_id', 'version', 'is_active',
        'answers', 'simple', 'detailed', 'technical', 'quality',
        'source', 'generation_job_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'answers' => 'array',
            'technical' => 'array',
            'quality' => 'array',
            'source' => ProfileSource::class,
        ];
    }

    /** مفاتيح الوصف التقني وترتيبها — نفس الترتيب الذي يقرؤه النموذج. */
    public const TECHNICAL_LABELS = [
        'project_type' => 'نوع المشروع',
        'project_name' => 'اسم المشروع',
        'activity_type' => 'نوع النشاط',
        'sales_summary' => 'ملخص المبيعات',
        'advantages_directives' => 'توجيهات المزايا التنافسية',
        'store_url' => 'رابط المتجر/الصفحة',
    ];

    public function generationJob(): BelongsTo
    {
        return $this->belongsTo(GenerationJob::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * ينشئ نسخة جديدة على رأس التاريخ ويجعلها النشطة.
     *
     * الترقيم والتنشيط والتشذيب في معاملة واحدة: نسختان نشطتان معاً
     * تعنيان برومبتاً غير محدد المصدر.
     */
    public static function createVersion(Brand $brand, array $attributes): self
    {
        return DB::transaction(function () use ($brand, $attributes) {
            $next = (int) static::forBrand($brand)->max('version') + 1;

            static::forBrand($brand)->update(['is_active' => false]);

            $profile = static::create([
                ...$attributes,
                'brand_id' => $brand->id,
                'version' => $next,
                'is_active' => true,
            ]);

            static::prune($brand);

            return $profile;
        });
    }

    /**
     * الاستعادة تنسخ إلى الأعلى ولا تُرجع المؤشر للخلف،
     * وإلا ضاعت النسخة الحالية لمجرد أن المستخدم أراد المقارنة.
     */
    public function restoreAsNewVersion(): self
    {
        return static::createVersion($this->brand, [
            'answers' => $this->answers,
            'simple' => $this->simple,
            'detailed' => $this->detailed,
            'technical' => $this->technical,
            'quality' => $this->quality,
            'source' => ProfileSource::Restored,
            'generation_job_id' => $this->generation_job_id,
        ]);
    }

    /**
     * التحرير اليدوي لا يمسّ نسخة الذكاء: يُنسخ فوقها ثم يُعدَّل،
     * فيبقى الأصل في السجل لمن أراد الرجوع. التعديلات المتتالية
     * على نسخة يدوية تُكتب في مكانها حتى لا يتضخم السجل بكل حفظة.
     *
     * @param  array<string, mixed>  $changes
     */
    public function applyManualEdit(array $changes): self
    {
        // النص صار نص المستخدم: حكم الفحص على نص الذكاء لم يعد يصفه
        $changes['quality'] = null;

        if ($this->source === ProfileSource::ManualEdit) {
            $this->update($changes);

            return $this;
        }

        return static::createVersion($this->brand, [
            'answers' => $this->answers,
            'simple' => $this->simple,
            'detailed' => $this->detailed,
            'technical' => $this->technical,
            'generation_job_id' => $this->generation_job_id,
            ...$changes,
            'source' => ProfileSource::ManualEdit,
        ]);
    }

    /** ما يستحق أن يراه صاحب المتجر من الفحص: ما قد يضره إن نشره كما هو. */
    public const USER_VISIBLE_ISSUES = ['invented_number', 'invented_link', 'unsupported_claim', 'banned_word', 'superlative', 'placeholder', 'missing_field'];

    /**
     * @return array<int, array{code: string, severity: string, field: string, message: string}>
     */
    public function visibleIssues(): array
    {
        return array_values(array_filter(
            (array) ($this->quality['issues'] ?? []),
            fn ($issue) => in_array($issue['code'] ?? null, self::USER_VISIBLE_ISSUES, true),
        ));
    }

    /**
     * وُلدت بالمزود الوهمي: نص تجريبي لا أوصاف.
     * النسخ السابقة لتسجيل النموذج تُعرف من نصها.
     */
    public function isPlaceholder(): bool
    {
        return str_starts_with((string) ($this->quality['model'] ?? ''), 'fake/')
            || str_contains((string) $this->simple, '[نص تجريبي');
    }

    /** يُحسب مرة لكل نسخة: قد يحتاج استعلاماً عن النسخة التي حُرّرت عليها. */
    protected ?array $resolvedTechnicalVersions = null;

    /**
     * إصدار البرومبت الذي كتب كل حقل تقني فعلاً، أو «manual» لما حرّره التاجر.
     *
     * الحقل الذي بقي من نسخة سابقة يحمل مصدره هو لا ختم النسخة التي بقي فيها:
     * نسخة وُلدت ببرومبت جديد وأبقت وصفاً قديماً ليست جديدة في ذلك الوصف.
     * رُصد في توليد حقيقي (2026-09-26): v16 مختومة بالإصدار 3 ووصفها التقني من الإصدار 1.
     *
     * @return array<string, int|string>
     */
    public function technicalVersions(): array
    {
        if ($this->resolvedTechnicalVersions !== null) {
            return $this->resolvedTechnicalVersions;
        }

        $fields = array_keys(BrandProfileGenerator::SOURCES);

        // التحرير اليدوي يمسح quality: ما عدّله التاجر له، وما لم يمسّه يرث مصدره
        // من آخر نسخة كتبتها الآلة قبله — تعديل الوصف المبسط لا يجعل الوصف التقني «يدوياً»
        if ($this->quality === null || $this->source === ProfileSource::ManualEdit) {
            $base = static::forBrand($this->brand_id)
                ->where('version', '<', (int) $this->version)
                ->whereNotNull('quality')
                ->orderByDesc('version')
                ->first();

            $inherited = $base?->technicalVersions() ?? [];

            return $this->resolvedTechnicalVersions = collect($fields)
                ->mapWithKeys(fn ($field) => [$field => $base && $this->sameTechnical($base, $field)
                    ? ($inherited[$field] ?? 1)
                    : 'manual'])
                ->all();
        }

        $map = (array) ($this->quality['technical_versions'] ?? []);

        if ($map !== []) {
            return $this->resolvedTechnicalVersions = collect($fields)
                ->mapWithKeys(fn ($field) => [$field => $map[$field] ?? 1])
                ->all();
        }

        // نسخ ما قبل تتبّع كل حقل: ما بقي من نسخة سابقة مجهول المصدر فيُعدّ قديماً
        $version = (int) ($this->quality['prompt_version'] ?? 1);
        $kept = (array) ($this->quality['kept'] ?? []);

        return $this->resolvedTechnicalVersions = collect($fields)
            ->mapWithKeys(fn ($field) => [$field => in_array($field, $kept, true) ? 1 : $version])
            ->all();
    }

    /** يُبقى الحقل عند ثبات إجاباته إن كتبه البرومبت الحالي أو حرّره التاجر. */
    public function keepsTechnical(string $field): bool
    {
        $version = $this->technicalVersions()[$field] ?? 1;

        return $version === 'manual' || (int) $version >= BrandProfileGenerator::PROMPT_VERSION;
    }

    /**
     * في وصفها التقني ما كتبه برومبت أقدم من الحالي؟ إعادة التوليد حينها تكتبه
     * من جديد ولو لم تتغير الإجابات. ما حرّره التاجر ليس من البرومبت: يبقى له.
     */
    public function writtenByOlderPrompt(): bool
    {
        return collect(array_keys(BrandProfileGenerator::SOURCES))
            ->contains(fn ($field) => ! $this->keepsTechnical($field));
    }

    protected function sameTechnical(self $other, string $field): bool
    {
        if ($field === 'important_notes') {
            $keys = fn (self $p) => array_map([BrandProfileGenerator::class, 'noteKey'], $p->constraints());

            return $keys($this) === $keys($other);
        }

        return trim((string) ($this->technical[$field] ?? '')) === trim((string) ($other->technical[$field] ?? ''));
    }

    public function projectType(): ProductType
    {
        return ProductType::tryFrom((string) ($this->answers['type'] ?? '')) ?? ProductType::Good;
    }

    /** مقتطف لقائمة النسخ: يكفي لتمييز نسخة عن أخرى دون فتحها. */
    public function excerpt(int $length = 90): string
    {
        return \Illuminate\Support\Str::limit((string) $this->simple, $length, '…');
    }

    /** يُبقي أحدث السقف نسخةً ويحذف ما قبلها. النشطة محميّة دائماً. */
    public static function prune(Brand $brand): void
    {
        $keep = max(1, (int) config('brand.profile_versions', 10));

        $stale = static::forBrand($brand)
            ->orderByDesc('version')
            ->pluck('id')
            ->slice($keep);

        if ($stale->isEmpty()) {
            return;
        }

        static::forBrand($brand)
            ->whereIn('id', $stale)
            ->where('is_active', false)
            ->delete();
    }

    /**
     * الوصف التقني كما يُحقن في طبقة البراند.
     * الملاحظات المهمة لا تُذكر هنا: مكانها طبقة النظام لأنها قيود لا توجيه.
     */
    public function toPromptFragment(): string
    {
        $lines = [];

        foreach ($this->technicalFor($this->brand) as $key => $value) {
            // الملاحظات مصفوفة ومكانها طبقة النظام: تُستثنى قبل أي تحويل لنص
            if (! isset(self::TECHNICAL_LABELS[$key]) || ! is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value !== '' && $value !== self::UNANSWERED) {
                $lines[] = self::TECHNICAL_LABELS[$key].": {$value}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * الوصف التقني للعرض والبرومبت.
     *
     * النوع والاسم والرابط حقائق تُقرأ من العلامة حيّةً لا من النسخة:
     * تصحيح الرابط يصل لكل النسخ فوراً، واستعادة نسخة قديمة لا تُرجع رابطاً قديماً.
     *
     * @return array<string, mixed>
     */
    public function technicalFor(?Brand $brand): array
    {
        $technical = (array) $this->technical;

        if ($brand) {
            $technical['project_type'] = ($brand->business_type ?? ProductType::Good) === ProductType::Service ? 'خدمة' : 'سلع';
            $technical['project_name'] = (string) $brand->name;
            $technical['store_url'] = (string) $brand->store_url;
        }

        // ترتيب المفاتيح نفسه في العرض والبرومبت
        return collect(array_keys(self::TECHNICAL_LABELS))
            ->mapWithKeys(fn ($key) => [$key => $technical[$key] ?? ''])
            ->put('important_notes', $technical['important_notes'] ?? [])
            ->all();
    }

    /**
     * هل وُلدت هذه النسخة من إجابات غير الحالية؟
     * لا نعيد التوليد تلقائياً عند التعديل (يكلّف نقاطاً)، بل ننبّه.
     */
    public function isStaleFor(Brand $brand): bool
    {
        return (array) $this->answers != $brand->profileAnswers();
    }

    /**
     * القيود السالبة التي كتبها التوليد أو حرّرها المستخدم.
     *
     * @return array<int, string>
     */
    public function constraints(): array
    {
        return collect((array) ($this->technical['important_notes'] ?? []))
            ->filter(fn ($note) => is_string($note) && trim($note) !== '')
            ->values()
            ->all();
    }

    /**
     * أسئلة وإجابات لقطة هذه النسخة.
     *
     * @return array<int, array{question: string, answer: string, answered: bool}>
     */
    public function answerSheet(?ProductType $type = null): array
    {
        return static::sheetFor((array) $this->answers, $type ?? $this->projectType());
    }

    /**
     * أي إجابات ← صفوف عرض. نص السؤال يتبع نوع النشاط.
     *
     * @return array<int, array{key: string, question: string, answer: string, answered: bool}>
     */
    public static function sheetFor(array $answers, ProductType $type): array
    {
        return collect(static::questions($type))
            ->map(function (string $question, string $key) use ($answers) {
                $answer = trim((string) ($answers[$key] ?? ''));

                return [
                    'key' => $key,
                    'question' => $question,
                    'answer' => $answer !== '' ? $answer : self::UNANSWERED,
                    'answered' => $answer !== '' && $answer !== self::UNANSWERED,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * أسئلة المشروع: أسئلة المرجع + الجمهور. الجمهور بقي سؤالاً لأن
     * المنتجات والاستيراد يرثونه، واستنتاجه يعني جمهوراً لم يره أحد.
     *
     * @return array<string, string>
     */
    public static function questions(ProductType $type = ProductType::Good): array
    {
        return [
            'project_name' => 'اسم المشروع',
            'store_url' => 'رابط المتجر أو الصفحة (اختياري)',
            'one_liner' => $type === ProductType::Service
                ? 'في سطر واحد، وش الخدمة اللي تقدمها؟'
                : 'في سطر واحد، اوصف لي ماذا تبيع؟',
            'advantages' => $type === ProductType::Service
                ? 'وش يستلمه العميل منك بالضبط؟'
                : 'ماهي أهم ميزة/مزايا تنافسية لديكم في المشروع؟',
            'audience' => 'من جمهورك المستهدف؟',
            'notes' => 'ملاحظات إضافية تود تزويدنا فيها',
        ];
    }
}
