<?php

namespace App\Services\AI\Prompts;

use App\Models\Brand;
use App\Models\Offer;
use App\Models\Product;
use App\Models\Testimonial;
use App\Services\Brand\BrandProfileGenerator;
use App\Services\Content\ContentFacts;
use App\Services\Content\ContentFormats;
use Illuminate\Support\Collection;

/**
 * بناء البرومبت بالطبقات، والترتيب مقصود:
 *   نظام ← براند ← منتج ← منصة ← هدف ← قالب
 *
 * الطبقات العليا قيود لا يتجاوزها النموذج، والسفلى توجيه إبداعي.
 * تغيير أي طبقة هنا ينعكس على كل التوليدات فوراً بلا نشر كود.
 */
class PromptBuilder
{
    protected ?Brand $brand = null;

    protected ?Product $product = null;

    protected string $goal = 'engagement';

    protected string $platform = 'instagram';

    protected string $template = 'value_carousel';

    protected string $language = 'ar';

    protected ?string $extraInstructions = null;

    protected ?string $occasion = null;

    protected ?string $angle = null;

    /** الشكل كما اختاره التاجر («reels»)، واختيار حقله التابع («30»)، وسكربت التصوير. */
    protected ?string $variant = null;

    protected ?string $formatChoice = null;

    protected bool $filming = false;

    /** لهجة هذا المحتوى إن اختار التاجر غير لهجة علامته. */
    protected ?string $dialectOverride = null;

    /** تُقرأ مرة لكل مهمة: البرومبت والفحص والتصحيح يرون العروض نفسها. */
    protected ?Collection $offers = null;

    protected ?Collection $testimonials = null;

    public static function make(): self
    {
        return new self;
    }

    public function forBrand(Brand $brand): self
    {
        $this->brand = $brand;

        return $this;
    }

    public function forProduct(?Product $product): self
    {
        $this->product = $product;

        return $this;
    }

    public function goal(string $goal): self
    {
        $this->goal = $goal;

        return $this;
    }

    public function platform(string $platform): self
    {
        $this->platform = $platform;

        return $this;
    }

    public function template(string $template): self
    {
        $this->template = $template;

        return $this;
    }

    public function language(string $language): self
    {
        $this->language = $language;

        return $this;
    }

    public function occasion(?string $occasion): self
    {
        $this->occasion = $occasion;

        return $this;
    }

    public function withInstructions(?string $instructions): self
    {
        $this->extraInstructions = $instructions;

        return $this;
    }

    /** زاوية نسخة من نسخ الدفعة (config('content.angles')). */
    public function angle(?string $angle): self
    {
        $this->angle = $angle;

        return $this;
    }

    public function currentAngle(): ?string
    {
        return $this->angle;
    }

    /**
     * شكل المحتوى من صفحة «كتابة المحتوى».
     * الشكل بلا اختيار صالح لحقله التابع يُكتب بعدد افتراضي، لا بخطأ.
     */
    public function variant(?string $variant, ?string $choice = null, bool $filming = false): self
    {
        $this->variant = ContentFormats::get($variant) ? $variant : null;
        $this->formatChoice = $this->variant && ContentFormats::choice($this->variant, $choice) ? $choice : null;
        $this->filming = $this->variant !== null && $filming && ContentFormats::supportsFilming($this->variant);

        return $this;
    }

    public function withDialect(?string $dialect): self
    {
        $this->dialectOverride = $dialect && config("dialects.{$dialect}") ? $dialect : null;

        return $this;
    }

    public function dialect(): string
    {
        if ($this->dialectOverride) {
            return $this->dialectOverride;
        }

        $dialect = (string) $this->brand?->dialect;

        return config("dialects.{$dialect}") ? $dialect : 'saudi';
    }

    public function isEnglish(): bool
    {
        return $this->language === 'en';
    }

    /**
     * ما يفرضه شكل المحتوى على المخطط: عدد المشاهد أو الإطارات أو التغريدات، وسكربت التصوير.
     *
     * @return array{count?: array{0: int, 1: int}, filming: bool}
     */
    public function shape(): array
    {
        $choice = $this->variant ? ContentFormats::choice($this->variant, $this->formatChoice) : null;

        return array_filter(['count' => $choice['count'] ?? null, 'filming' => $this->filming], fn ($v) => $v !== null);
    }

    public function platformKey(): string
    {
        return $this->platform;
    }

    /** ما يحتاجه فحص الصدق ليحكم على مخرج هذا البرومبت. */
    public function qualityOptions(): array
    {
        return [
            'platform' => $this->platform,
            'format' => $this->format(),
            'slides' => $this->slideCount(),
            // الإنجليزية بلا لهجة: قوائم التسرّب عربية، والمدقق اللغوي عربي
            'dialect' => $this->isEnglish() ? null : $this->dialect(),
            'language' => $this->language,
            'banned_words' => (array) $this->brand?->banned_words,
        ];
    }

    /**
     * طبقة النظام: الدور والقيود الملزمة. لا إبداع هنا.
     */
    public function system(): string
    {
        $guardrails = collect(config('content.guardrails', []))
            ->map(fn ($rule) => "- {$rule}")
            ->implode("\n");

        $banned = $this->brand?->banned_words
            ? "\nكلمات ممنوعة نهائياً في النص بأي صيغة أو تصريف: ".implode('، ', $this->brand->banned_words)
            : '';

        $banned .= $this->profileConstraints();

        $voice = $this->isEnglish() ? $this->englishVoice() : $this->dialectVoice();

        return <<<SYSTEM
        أنت كاتب محتوى تسويقي عربي محترف بخبرة طويلة في التجارة الإلكترونية.
        {$voice}
        تكتب لتُقرأ على الجوال: جمل قصيرة، سطر واحد لكل فكرة، بلا حشو ولا مقدمات.

        قواعد ملزمة لا تُخالف بأي حال:
        {$guardrails}{$banned}

        إن نقصتك معلومة عن المنتج فلا تخترعها، واكتب الجملة بصياغة لا تحتاجها.
        SYSTEM;
    }

    protected function dialectVoice(): string
    {
        $dialect = config('dialects.'.$this->dialect());

        $vocabulary = array_filter([
            filled($dialect['note'] ?? null) ? "المقصود: {$dialect['note']}." : null,
            $dialect['use'] ? 'مفردات من اللهجة: '.implode('، ', $dialect['use']).'.' : null,
            $dialect['avoid'] ? 'ليست من اللهجة، فلا تستخدمها: '.implode('، ', array_slice($dialect['avoid'], 0, 12)).'.' : null,
        ]);

        return "تكتب بلهجة {$dialect['label']} طبيعية كما يتحدث الناس فعلاً، لا بلغة إعلانات مترجمة."
            .($vocabulary ? "\n".implode("\n", $vocabulary) : '');
    }

    /** البيانات بالعربية والمخرج بالإنجليزية: النموذج يُطلب منه ألا يترجم حرفياً. */
    protected function englishVoice(): string
    {
        return 'تكتب المحتوى كله باللغة الإنجليزية كما يكتبه متحدث أصلي، لا ترجمة حرفية للبيانات العربية. '
            .'الأسعار والأرقام والأسماء تُنقل كما وردت.';
    }

    /**
     * طبقة المستخدم: البراند والمنتج والمنصة والهدف والقالب.
     * حدود الحقائق آخراً: أقرب ما يقرؤه النموذج قبل أن يكتب.
     */
    public function prompt(): string
    {
        return collect([
            $this->brandLayer(),
            $this->storeFactsLayer(),
            $this->productLayer(),
            $this->offersLayer(),
            $this->testimonialsLayer(),
            $this->platformLayer(),
            $this->goalLayer(),
            $this->templateLayer(),
            $this->formatLayer(),
            $this->angleLayer(),
            $this->occasionLayer(),
            $this->extraLayer(),
            $this->factsBoundaryLayer(),
        ])->filter()->implode("\n\n");
    }

    /**
     * دفتر الحقائق: نفس الطبقات التي يقرؤها النموذج، مع إجابات التاجر كما كتبها.
     * ما يراه النموذج هو بالضبط ما يُسمح له بذكره — لا أكثر.
     */
    public function facts(): ContentFacts
    {
        return new ContentFacts(
            collect([
                $this->brandLayer(),
                $this->brand ? implode("\n", array_filter($this->brand->profileAnswers())) : null,
                $this->storeFactsLayer(),
                $this->productLayer(),
                $this->offersLayer(),
                $this->testimonialsLayer(),
                $this->occasionLayer(),
                $this->extraLayer(),
                $this->durationFact(),
            ])->filter()->implode("\n\n"),
            $this->testimonials()->pluck('body')->all(),
        );
    }

    /** المدة التي اختارها التاجر حقيقة عن المحتوى نفسه: «في 30 ثانية» ليست رقماً مخترعاً. */
    protected function durationFact(): ?string
    {
        $choice = $this->variant ? ContentFormats::choice($this->variant, $this->formatChoice) : null;

        return $choice && in_array(ContentFormats::kind($this->variant), ['reel', 'story'], true)
            ? "مدة المحتوى: {$choice['label']}"
            : null;
    }

    /** معرّفات العروض التي رآها النموذج: تنبّه الخطة إن انتهى العرض قبل موعد النشر. */
    public function offerIds(): array
    {
        return $this->offers()->pluck('id')->all();
    }

    /**
     * العروض السارية اليوم على المتجر كله وعلى هذا المنتج.
     * المنتهي لا يُعرض، فلا يُذكر كوده ولا يُعدّ حقيقة.
     */
    protected function offers(): Collection
    {
        if (! $this->brand?->id) {
            return collect();
        }

        return $this->offers ??= Offer::forBrand($this->brand)
            ->runningOn(today())
            ->applyingTo($this->product)
            ->orderBy('ends_at')
            ->get();
    }

    /** تجارب هذا المنتج أولاً، ثم تجارب المتجر العامة. خمس تكفي منشوراً. */
    protected function testimonials(): Collection
    {
        if (! $this->brand?->id) {
            return collect();
        }

        return $this->testimonials ??= Testimonial::forBrand($this->brand)
            ->where(fn ($q) => $q->whereNull('product_id')
                ->when($this->product?->id, fn ($q) => $q->orWhere('product_id', $this->product->id)))
            ->orderByRaw('product_id is null')
            ->latest()
            ->take(5)
            ->get();
    }

    protected function brandLayer(): ?string
    {
        if (! $this->brand) {
            return null;
        }

        // الجمهور والنبرة وقناة الطلب لا يحملها ملف المشروع، فتُذكر دائماً
        $always = [
            filled($this->brand->audience) ? "الجمهور المستهدف: {$this->brand->audience}" : null,
            filled($this->brand->tone) ? "نبرة العلامة: {$this->brand->tone}" : null,
            filled($this->brand->whatsapp) ? "قناة الطلب: واتساب {$this->brand->whatsapp}" : null,
        ];

        $fragment = $this->brand->activeProfile?->toPromptFragment();

        // مع الملف: الاسم والمجال والنبذة والمزايا والرابط تأتي منه وحده.
        // ذكرها مرتين يجعل النموذج يختار بين نسختين حين تختلفان.
        if (filled($fragment)) {
            return "## العلامة التجارية\n".$fragment."\n".implode("\n", array_filter($always));
        }

        $lines = array_filter([
            "الاسم: {$this->brand->name}",
            filled($this->brand->industry) ? "المجال: {$this->brand->industry}" : null,
            filled($this->brand->description) ? "نبذة: {$this->brand->description}" : null,
            $this->brand->selling_points
                ? 'نقاط القوة: '.implode('، ', $this->brand->selling_points)
                : null,
            filled($this->brand->store_url) ? "المتجر: {$this->brand->store_url}" : null,
            ...$always,
        ]);

        return "## العلامة التجارية\n".implode("\n", $lines);
    }

    /**
     * ملاحظات ملف المشروع قيود لا توجيه، فمكانها طبقة النظام:
     * ما يُكتب في طبقة المستخدم يتجاوزه النموذج بسهولة.
     */
    protected function profileConstraints(): string
    {
        // قواعد التاجر أولاً: كتبها بنفسه، ولا تمسّها إعادة توليد الملف
        $rules = array_values(array_filter(array_map('trim', (array) $this->brand?->content_rules)));
        $notes = $this->brand?->activeProfile?->constraints() ?? [];

        $ruleKeys = array_map([BrandProfileGenerator::class, 'noteKey'], $rules);
        $notes = array_values(array_filter(
            $notes,
            fn ($note) => ! in_array(BrandProfileGenerator::noteKey($note), $ruleKeys, true),
        ));

        $all = [...$rules, ...$notes];

        if ($all === []) {
            return '';
        }

        return "\n\nتوجيهات ملزمة خاصة بهذا المشروع:\n"
            .collect($all)->map(fn ($note) => "- {$note}")->implode("\n");
    }

    protected function productLayer(): ?string
    {
        if (! $this->product) {
            return null;
        }

        // حقائق البيع تُقرأ حيّة لا من الورقة المخزّنة: الخصم ينتهي والمخزون ينفد
        $sales = collect($this->product->salesContext())->map(fn ($line) => "• {$line}")->implode("\n");

        return "## المنتج\n".$this->product->promptContext().($sales !== '' ? "\n".$sales : '');
    }

    protected function storeFactsLayer(): ?string
    {
        $lines = $this->brand?->storeFactLines() ?? [];

        return $lines ? "## حقائق المتجر\n".implode("\n", array_map(fn ($l) => "- {$l}", $lines)) : null;
    }

    protected function offersLayer(): ?string
    {
        $offers = $this->offers();

        return $offers->isEmpty() ? null
            : "## العروض السارية اليوم\n".$offers->map(fn (Offer $o) => '- '.$o->promptLine())->implode("\n");
    }

    protected function testimonialsLayer(): ?string
    {
        $testimonials = $this->testimonials();

        return $testimonials->isEmpty() ? null
            : "## تجارب عملاء حقيقية (تُقتبس بنصها واسمها كما هنا، دون إعادة صياغة)\n"
                .$testimonials->map(fn (Testimonial $t) => '- '.$t->promptLine())->implode("\n");
    }

    protected function platformLayer(): string
    {
        $platform = config("content.platforms.{$this->platform}", []);
        $hashtags = $platform['hashtags'] ?? ['min' => 3, 'max' => 6];

        return "## المنصة\n"
            ."المنصة: ".($platform['label'] ?? $this->platform)."\n"
            ."الطول المناسب: ".($platform['recommended_length'] ?? 'متوسط')."\n"
            ."الأسلوب: ".($platform['style'] ?? '')."\n"
            ."عدد الهاشتاقات: من {$hashtags['min']} إلى {$hashtags['max']}، "
            .($this->isEnglish() ? 'بالإنجليزية' : 'بالعربية').' وبلا مسافات داخل الوسم.';
    }

    /**
     * الشكل كما اختاره التاجر: ما يميزه على منصته، ومدته أو طوله، وسكربت التصوير.
     * كل خيار في الواجهة يصل إلى هنا؛ لا خيار يُعرض ثم يُتجاهل.
     */
    protected function formatLayer(): ?string
    {
        $format = ContentFormats::get($this->variant);

        if (! $format) {
            return null;
        }

        $platform = config("content.platforms.{$this->platform}.label", $this->platform);
        $lines = ["## شكل المحتوى\n{$format['label']} على {$platform}.".(filled($format['guidance'] ?? null) ? ' '.$format['guidance'] : '')];

        if ($choice = ContentFormats::choice($this->variant, $this->formatChoice)) {
            [$min, $max] = $choice['count'];

            $lines[] = match ($format['kind']) {
                'reel' => "المدة: {$choice['label']}. اكتب كلاماً يُقال بصوت طبيعي في هذه المدة: نحو {$choice['words']} كلمة منطوقة، "
                    ."موزعة على {$min} إلى {$max} مشاهد. لكل مشهد time بالثواني من بداية الفيديو، وينتهي آخرها عند نهاية المدة تقريباً.",
                'story' => "المدة الإجمالية: {$choice['label']}، في {$min} إلى {$max} إطارات. نص كل إطار قصير يُقرأ في ثوانٍ.",
                'thread' => "عدد التغريدات: من {$min} إلى {$max}. كل تغريدة لا تتجاوز 280 حرفاً، والهاشتاقات تُلحق بآخر تغريدة فتُحسب ضمن حدها.",
                default => "{$choice['label']}.",
            };
        }

        if ($this->filming) {
            $unit = $format['kind'] === 'story' ? 'إطار' : 'مشهد';

            $lines[] = "سكريبت التصوير مطلوب: لكل {$unit} shot يوجّه من يصوّر: نوع اللقطة (قريبة، متوسطة، واسعة)، "
                .'وزاوية الكاميرا وحركتها، والمكان والإضاءة، وما يظهر في الكادر. التوجيه لمن يصوّر لا للجمهور، ولا يضيف معلومة عن المنتج.';
        }

        return implode("\n", $lines);
    }

    protected function goalLayer(): string
    {
        $goal = config("content.goals.{$this->goal}", []);

        return "## هدف المنشور\n"
            .($goal['label'] ?? $this->goal)."\n"
            .($goal['directive'] ?? '');
    }

    protected function templateLayer(): string
    {
        $template = config("content.templates.{$this->template}", []);
        $out = "## القالب\n".($template['label'] ?? $this->template)."\n".($template['guidance'] ?? '');

        if (! empty($template['slides'])) {
            $roles = config('content.slide_roles');
            $lines = [];

            foreach ($template['slides'] as $index => $role) {
                $label = $roles[$role]['label'] ?? $role;
                $directive = $roles[$role]['directive'] ?? '';
                $lines[] = 'سلايد '.($index + 1).": [{$role} · {$label}] {$directive}";
            }

            $out .= "\n\nبنية الشرائح المطلوبة حرفياً وبنفس الترتيب:\n".implode("\n", $lines)
                ."\n\nالنص يُكتب فوق الصورة بخط كبير، فاجعل كل شريحة جملة أو جملتين قصيرتين."
                ."\nفي الهوك: kicker تمهيد قصير، ثم focal العبارة المحورية التي تُكتب بأكبر خط، ثم tail التكملة."
                ."\nلكل شريحة visual: وصف صورتها بالإنجليزية، بلا أي حروف أو كلمات داخل الصورة، مع مساحة هادئة في أعلاها للعنوان.";
        }

        return $out;
    }

    protected function angleLayer(): ?string
    {
        $angle = $this->angle ? config("content.angles.{$this->angle}") : null;

        if (! $angle) {
            return null;
        }

        return "## زاوية هذه النسخة\n{$angle['label']}: {$angle['directive']}\n"
            .'الزاوية تغيّر طريقة العرض فقط. لا تضف معلومة لتبدو النسخة مختلفة.';
    }

    /**
     * ما يجوز ذكره، بصيغة موجبة، ثم ما ليس في البيانات بالاسم.
     *
     * الفئات الغائبة تُحسب من البيانات نفسها لا تُكتب يدوياً: متجر يوصّل
     * لا يُقال له «لا تذكر التوصيل»، ومتجر لا يوصّل يُقال له صراحة.
     */
    protected function factsBoundaryLayer(): string
    {
        $facts = $this->facts();

        $absent = collect(config('claims.categories', []))
            ->filter(fn ($category) => empty($category['always']) && ! $facts->mentionsAny($category['words']))
            ->pluck('label');

        $lines = [
            '## حدود الحقائق',
            'اذكر فقط ما ورد أعلاه في أقسام العلامة والمنتج والمناسبة وتعليمات صاحب المتجر: الأرقام والأسعار والخدمات والعروض بنصها.',
            'لا تقييمات ولا أعداد عملاء ولا اقتباسات عملاء إلا ما ورد أعلاه حرفياً.',
        ];

        if ($absent->isNotEmpty()) {
            $lines[] = 'غير متوفر في بيانات هذا المتجر، فلا يُذكر بأي صيغة: '.$absent->implode('، ').'.';
        }

        return implode("\n", $lines);
    }

    protected function occasionLayer(): ?string
    {
        return $this->occasion ? "## المناسبة\nاربط المحتوى بـ: {$this->occasion}، ربطاً طبيعياً بلا ابتذال." : null;
    }

    protected function extraLayer(): ?string
    {
        return filled($this->extraInstructions)
            ? "## تعليمات إضافية من صاحب المتجر\n{$this->extraInstructions}"
            : null;
    }

    /**
     * عدد الشرائح الذي يفرضه القالب، أو صفر لغير الكاروسيل.
     */
    public function slideCount(): int
    {
        return count(config("content.templates.{$this->template}.slides", []));
    }

    public function format(): string
    {
        return config("content.templates.{$this->template}.format", 'post');
    }
}
