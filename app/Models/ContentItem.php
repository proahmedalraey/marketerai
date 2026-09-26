<?php

namespace App\Models;

use App\Enums\ContentFormat;
use App\Enums\ContentStatus;
use App\Models\Concerns\BelongsToBrand;
use App\Services\Content\ContentFormats;
use App\Support\Arabic\ArabicText;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class ContentItem extends Model
{
    use BelongsToBrand, HasFactory;

    protected $fillable = [
        'brand_id', 'product_id', 'goal', 'platform', 'format', 'variant', 'template',
        'language', 'options', 'body', 'caption', 'quality', 'status', 'in_plan', 'planned_for', 'generation_job_id',
    ];

    protected function casts(): array
    {
        return [
            'body' => 'array',
            'options' => 'array',
            'quality' => 'array',
            'format' => ContentFormat::class,
            'status' => ContentStatus::class,
            'in_plan' => 'boolean',
            'planned_for' => 'date',
        ];
    }

    /** ما في الخطة الشهرية. ما لم يُضف بعد مسودة في صفحة «كتابة المحتوى». */
    public function scopeInPlan($query)
    {
        return $query->where('in_plan', true);
    }

    public function scopeDrafts($query)
    {
        return $query->where('in_plan', false);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function mediaAssets(): HasMany
    {
        return $this->hasMany(MediaAsset::class)->orderBy('slide_index');
    }

    public function scheduledPosts(): HasMany
    {
        return $this->hasMany(ScheduledPost::class);
    }

    /** @return array<int, array{role: string, text: string}> */
    public function slides(): array
    {
        return $this->body['slides'] ?? [];
    }

    /**
     * أحدث صورة لكل شريحة. إعادة توليد صورة شريحة تضيف أصلاً ولا تحذف السابق،
     * فالمعروض هو الأحدث.
     *
     * @return array<int, MediaAsset>
     */
    public function slideImages(): array
    {
        return $this->mediaAssets
            ->where('kind', 'image')
            ->whereNotNull('slide_index')
            ->sortBy('id')
            ->keyBy('slide_index')
            ->all();
    }

    /** الغلاف: صورة الشريحة الأولى، ومرجع أسلوب بقية الشرائح. */
    public function coverImage(): ?MediaAsset
    {
        return $this->slideImages()[0] ?? null;
    }

    /** @return array<int, string> */
    public function hashtags(): array
    {
        return $this->body['hashtags'] ?? [];
    }

    public function script(): ?string
    {
        return $this->body['script'] ?? null;
    }

    /** @return array<int, array{time?: string, voiceover: string, on_screen?: string, shot?: string}> */
    public function scenes(): array
    {
        return $this->body['scenes'] ?? [];
    }

    /** @return array<int, array{text: string, visual?: string, interaction?: string, shot?: string}> */
    public function frames(): array
    {
        return $this->body['frames'] ?? [];
    }

    /** @return array<int, string> */
    public function tweets(): array
    {
        return $this->body['tweets'] ?? [];
    }

    /** @return array<int, array{heading: string, text: string}> */
    public function sections(): array
    {
        return $this->body['sections'] ?? [];
    }

    /** الشكل كما اختاره التاجر («Reels»)، أو اسم البنية لمحتوى سبق الأشكال. */
    public function variantLabel(): string
    {
        return ContentFormats::label($this->variant) ?? $this->format->label();
    }

    /** المدة أو طول الثريد كما اختيرا («30 ثانية»). */
    public function optionLabel(): ?string
    {
        $choice = $this->options['option'] ?? null;

        return $this->variant && $choice !== null
            ? (ContentFormats::choice($this->variant, (string) $choice)['label'] ?? null)
            : null;
    }

    public function dialectLabel(): ?string
    {
        $dialect = $this->options['dialect'] ?? null;

        return $dialect && $this->language === 'ar' ? config("dialects.{$dialect}.label") : null;
    }

    public function languageLabel(): string
    {
        return config("content.languages.{$this->language}", $this->language);
    }

    public function withFilming(): bool
    {
        return (bool) ($this->options['filming'] ?? false);
    }

    /** سطر يمثل المحتوى في البطاقات: الكابشن، أو أول ما كُتب لشكل بلا كابشن. */
    public function previewText(): string
    {
        return (string) (filled($this->caption) ? $this->caption
            : ($this->tweets()[0] ?? $this->frames()[0]['text'] ?? $this->body['title'] ?? $this->scenes()[0]['voiceover'] ?? ''));
    }

    /**
     * المحتوى كاملاً نصاً واحداً، بترتيب قراءته: لزر «نسخ الكل».
     */
    public function copyText(): string
    {
        $blocks = [];

        foreach ($this->slides() as $i => $slide) {
            $blocks[] = 'الشريحة '.($i + 1).":\n".$slide['text'];
        }

        if ($hook = $this->body['hook'] ?? null) {
            $blocks[] = "الافتتاحية:\n{$hook}";
        }

        foreach ($this->scenes() as $i => $scene) {
            $blocks[] = $this->sceneText($scene, $i);
        }

        if ($script = $this->script()) {
            $blocks[] = $script;
        }

        foreach ($this->frames() as $i => $frame) {
            $blocks[] = trim('الإطار '.($i + 1).":\n".$frame['text']
                .(filled($frame['visual'] ?? null) ? "\nالمشهد: {$frame['visual']}" : '')
                .(filled($frame['interaction'] ?? null) ? "\nتفاعل: {$frame['interaction']}" : '')
                .(filled($frame['shot'] ?? null) ? "\nالتصوير: {$frame['shot']}" : ''));
        }

        // الثريد: الهاشتاقات تُلحق بآخر تغريدة كما تُنشر، لا تحت «الوصف»
        if ($tweets = $this->tweets()) {
            $tags = collect($this->hashtags())->map(fn ($t) => '#'.ltrim($t, '#'))->implode(' ');

            foreach ($tweets as $i => $tweet) {
                $last = $i === count($tweets) - 1;
                $blocks[] = ($i + 1).'/ '.$tweet.($last && $tags !== '' ? "\n{$tags}" : '');
            }

            return implode("\n\n", $blocks);
        }

        if ($title = $this->body['title'] ?? null) {
            $blocks[] = $title;
        }

        foreach ($this->sections() as $section) {
            $blocks[] = $section['heading']."\n".$section['text'];
        }

        if ($caption = $this->fullCaption()) {
            $blocks[] = $blocks ? "الوصف:\n{$caption}" : $caption;
        }

        return implode("\n\n", $blocks);
    }

    /** مشهد فيديو نصاً: التوقيت، والكلام، ونص الشاشة، والتصوير إن طُلب. */
    public function sceneText(array $scene, int $index): string
    {
        return trim('المشهد '.($index + 1).(filled($scene['time'] ?? null) ? " ({$scene['time']} ث)" : '').":\n"
            .$scene['voiceover']
            .(filled($scene['on_screen'] ?? null) ? "\nنص الشاشة: {$scene['on_screen']}" : '')
            .(filled($scene['shot'] ?? null) ? "\nالتصوير: {$scene['shot']}" : ''));
    }

    /**
     * مشاكل فحص الصدق، الأخطاء أولاً.
     *
     * @return array<int, array{code: string, severity: string, field: string, message: string}>
     */
    public function qualityIssues(): array
    {
        return collect((array) ($this->quality['issues'] ?? []))
            ->unique(fn ($issue) => $issue['field'].$issue['message'])
            ->sortBy(fn ($issue) => $issue['severity'] === 'error' ? 0 : 1)
            ->values()
            ->all();
    }

    /**
     * عروض ذكرها المنشور وتنتهي قبل تاريخ نشره المخطط.
     * كُتب المنشور والعرض سارٍ؛ نشره بعد انتهائه وعدٌ لن يُوفى.
     *
     * @return Collection<int, Offer>
     */
    public function offersEndingBeforePublish(): Collection
    {
        $ids = (array) ($this->body['offer_ids'] ?? []);

        if (! $this->planned_for || $ids === []) {
            return collect();
        }

        $text = implode("\n", [
            (string) $this->caption,
            ...array_column($this->slides(), 'text'),
            (string) $this->script(),
        ]);

        return Offer::withoutBrandScope()
            ->whereIn('id', $ids)
            ->whereNotNull('ends_at')
            ->whereDate('ends_at', '<', $this->planned_for)
            ->get()
            ->filter(fn (Offer $offer) => (filled($offer->coupon_code) && mb_stripos($text, $offer->coupon_code) !== false)
                || ArabicText::firstOf($text, [
                    ...config('claims.categories.discount.words', []),
                    ...config('claims.categories.free.words', []),
                ]) !== null)
            ->values();
    }

    /** فيه ما قد يضر التاجر إن نشره كما هو. */
    public function needsReview(): bool
    {
        return collect($this->quality['issues'] ?? [])->contains('severity', 'error');
    }

    public function goalLabel(): string
    {
        return config("content.goals.{$this->goal}.label", $this->goal);
    }

    public function platformLabel(): string
    {
        return config("content.platforms.{$this->platform}.label", $this->platform);
    }

    /** نوع المحتوى بكلمتين للبطاقات («محتوى قيمي»، «محتوى تسويقي»)، وإلا اسم القالب. */
    public function kindLabel(): ?string
    {
        return $this->template
            ? (config("content.templates.{$this->template}.short") ?? $this->templateLabel())
            : null;
    }

    public function templateLabel(): ?string
    {
        return $this->template ? config("content.templates.{$this->template}.label", $this->template) : null;
    }

    /**
     * النص الجاهز للنسخ إلى المنصة: الكابشن ثم الهاشتاقات.
     */
    public function fullCaption(): string
    {
        $parts = [$this->caption ?? ''];

        if ($tags = $this->hashtags()) {
            $parts[] = collect($tags)
                ->map(fn ($t) => str_starts_with($t, '#') ? $t : '#'.$t)
                ->implode(' ');
        }

        return trim(implode("\n\n", array_filter($parts)));
    }
}
