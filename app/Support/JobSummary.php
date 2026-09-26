<?php

namespace App\Support;

use App\Enums\JobStatus;
use App\Models\ContentItem;
use App\Models\GenerationJob;
use App\Services\Content\ContentFormats;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * وصف مهمة ذكاء اصطناعي للوحة «إنتاجاتي»: ماذا كانت، وأين نتيجتها.
 *
 * المهمة في قاعدة البيانات صف تقني (type وpayload وresult). هنا تصير سطراً
 * يفهمه التاجر: «Reels · إنستغرام»، «اكتملت»، «عرض النتائج».
 */
class JobSummary
{
    /** أنواع المهام كما تظهر للتاجر. */
    public const TYPES = [
        'content' => ['label' => 'كتابة محتوى', 'icon' => 'pen'],
        'slide_text' => ['label' => 'إعادة كتابة شريحة', 'icon' => 'pen'],
        'image' => ['label' => 'توليد صورة', 'icon' => 'image'],
        'carousel_images' => ['label' => 'صور كاروسيل', 'icon' => 'layers'],
        'prompt_enhance' => ['label' => 'تحسين وصف صورة', 'icon' => 'wand'],
        'brand_profile' => ['label' => 'أوصاف العلامة', 'icon' => 'file-text'],
        'store_scan' => ['label' => 'قراءة متجر', 'icon' => 'store'],
        'product_import' => ['label' => 'استيراد منتجات', 'icon' => 'package'],
    ];

    public function __construct(protected GenerationJob $job) {}

    /**
     * صفوف اللوحة جاهزة لجافاسكربت.
     *
     * @param  Collection<int, GenerationJob>  $jobs
     */
    public static function collect($jobs): array
    {
        return $jobs->map(fn (GenerationJob $job) => (new self($job))->toArray())->values()->all();
    }

    public function toArray(): array
    {
        $job = $this->job;
        $item = $job->contentItems->first();

        return [
            'uuid' => $job->uuid,
            'type' => $job->type,
            'kind' => self::TYPES[$job->type]['label'] ?? $job->type,
            'icon' => self::TYPES[$job->type]['icon'] ?? 'bot',
            'title' => $this->title(),
            'meta' => $this->meta(),
            'state' => $this->state(),
            'running' => ! $job->status->isFinished(),
            // في الانتظار منذ أكثر من نصف دقيقة ولا عامل حي: تنبّه اللوحة بدل «جارية» بلا نهاية
            'stalled' => QueueHealth::isStalled($job),
            'stage' => JobStage::label($job),
            'progress' => $job->progress(),
            'succeeded' => $this->succeeded(),
            'error' => $job->error,
            // كسرية منذ مصفوفة الصور (0.5)؛ نُبقي الصحيح صحيحاً ليبقى العرض «2» لا «2.0»
            'credits' => fmod((float) $job->credits_charged, 1.0) === 0.0
                ? (int) $job->credits_charged
                : (float) $job->credits_charged,
            'time' => $job->created_at->diffForHumans(),
            'resultUrl' => $this->resultUrl($item),
            'editUrl' => $item ? route('content.show', $item) : null,
        ];
    }

    /** «جارية» و«مكتملة» و«فشلت»: ثلاث حالات تكفي التاجر. */
    public function state(): string
    {
        return match (true) {
            ! $this->job->status->isFinished() => 'running',
            in_array($this->job->status, [JobStatus::Failed, JobStatus::Cancelled], true) => 'failed',
            default => 'done',
        };
    }

    /** عنوان يعرّف المهمة: أول ما كُتب فيها، وإلا وصف إعداداتها. */
    protected function title(): string
    {
        $job = $this->job;

        if ($item = $job->contentItems->first()) {
            return Str::limit($item->previewText(), 70) ?: $item->variantLabel();
        }

        $payload = (array) $job->payload;

        return match ($job->type) {
            'content' => trim((ContentFormats::label($payload['variant'] ?? null) ?? 'محتوى')
                .' · '.config('content.platforms.'.($payload['platform'] ?? '').'.label', '')),
            'slide_text' => 'إعادة كتابة الشريحة '.((int) ($payload['index'] ?? 0) + 1),
            'store_scan' => 'قراءة متجر '.Str::limit(preg_replace('#^https?://(www\.)?#', '', (string) ($payload['store_url'] ?? '')), 40),
            'product_import' => 'استيراد '.count($payload['products'] ?? []).' منتجاً',
            'image', 'carousel_images', 'prompt_enhance' => Str::limit((string) ($payload['prompt'] ?? ''), 70) ?: (self::TYPES[$job->type]['label'] ?? 'صور'),
            default => self::TYPES[$job->type]['label'] ?? $job->type,
        };
    }

    /** سطر التفاصيل: إعدادات المهمة كما اختارها التاجر. */
    protected function meta(): string
    {
        if ($this->job->type !== 'content') {
            return self::TYPES[$this->job->type]['label'] ?? '';
        }

        $payload = (array) $this->job->payload;
        $variant = $payload['variant'] ?? null;

        return collect([
            config('content.goals.'.($payload['goal'] ?? '').'.label'),
            config('content.platforms.'.($payload['platform'] ?? '').'.label'),
            ContentFormats::label($variant),
            $variant ? (ContentFormats::choice($variant, isset($payload['option']) ? (string) $payload['option'] : null)['label'] ?? null) : null,
            config('dialects.'.($payload['dialect'] ?? '').'.label'),
            ! empty($payload['filming']) ? 'مع أسلوب التصوير' : null,
        ])->filter()->implode(' · ');
    }

    /** كم أنتجت المهمة فعلاً: الفشل الجزئي يسلّم ما نجح. */
    protected function succeeded(): int
    {
        $result = (array) $this->job->result;

        return match ($this->job->type) {
            'content' => count($result['content_item_ids'] ?? []),
            'carousel_images', 'image' => (int) ($this->job->children_done ?: count($result['media_ids'] ?? [])),
            default => $this->job->status === JobStatus::Completed ? 1 : 0,
        };
    }

    /** أين تُرى النتيجة: المسودة في صفحة الكتابة، وما دخل الخطة في صفحته. */
    protected function resultUrl(?ContentItem $item): ?string
    {
        if ($this->state() !== 'done') {
            return null;
        }

        if ($item) {
            return $item->in_plan
                ? route('content.show', $item)
                : route('content.generator', ['focus' => $item->id]);
        }

        return match ($this->job->type) {
            'carousel_images' => ($id = $this->job->payload['content_item_id'] ?? null) ? route('content.show', $id) : route('studio.index'),
            'image' => route('studio.index'),
            'brand_profile' => route('brand.profile'),
            'store_scan', 'product_import' => route('products.index'),
            default => null,
        };
    }
}
