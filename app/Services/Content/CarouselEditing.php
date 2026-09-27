<?php

namespace App\Services\Content;

use App\Enums\ColorRole;
use App\Models\ContentItem;
use App\Models\MediaAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * تعديل الكاروسيل: نص الشرائح وترتيبها وتصميمها (قالب، تاريخ، موضع النص ولونه).
 *
 * مصدر واحد للحفظ تستعمله صفحة المحتوى ونافذة «نظام الكاروسيل» في الاستوديو:
 * الصورة تتبع شريحتها عند الترتيب، وصورة شريحة محذوفة تُفصل وتبقى في الاستوديو.
 * التعديل مجاني — لا نموذج هنا؛ فحص الصدق يُعاد بعد الحفظ كما في أي تعديل يدوي.
 */
class CarouselEditing
{
    /** أشكال الكاروسيل (القوالب البصرية) — الرسم نفسه في resources/js/carousel-render.js */
    public const TEMPLATES = [
        'coffee_modern' => 'كوفي عصري',
        'editorial' => 'تحريري',
        'bold_dark' => 'جريء داكن',
        'calm' => 'هادئ',
        'heritage' => 'تراثي',
        'vibrant' => 'حيوي',
    ];

    public const DATE_MODES = ['none', 'month', 'day_month'];

    public const MAX_SLIDES = 10;

    /**
     * الشرائح بعد التعديل، وخريطة المواضع (الأصل ← الجديد) لتتبع الصور شرائحها.
     * ما لم يُرسَل من حقول الشريحة يبقى من أصلها (الوصف البصري والتصميم مثلاً).
     *
     * @return array{0: list<array>, 1: array<int, int>}
     */
    public function rebuild(array $old, array $submitted): array
    {
        $slides = [];
        $moves = [];

        foreach (array_values($submitted) as $position => $row) {
            // الشكل القديم: نص فقط، لنفس الموضع
            $row = is_array($row) ? $row : ['text' => (string) $row, 'origin' => $position];

            $origin = is_numeric($row['origin'] ?? null) && isset($old[(int) $row['origin']]) ? (int) $row['origin'] : null;
            $base = $origin !== null ? $old[$origin] : [];

            $pick = fn ($key) => array_key_exists($key, $row) ? $row[$key] : ($base[$key] ?? null);

            $slide = ContentSchema::slide([
                'role' => $pick('role') ?? 'pull',
                'text' => $pick('text') ?? '',
                'kicker' => $pick('kicker'),
                'focal' => $pick('focal'),
                'tail' => $pick('tail'),
                'visual' => $pick('visual'),
                'layout' => $pick('layout'),
                'image' => $pick('image'),
            ]);

            if ($slide['text'] === '') {
                continue;
            }

            if ($origin !== null) {
                $moves[$origin] = count($slides);
            }

            $slides[] = $slide;
        }

        return [$slides, $moves];
    }

    /** الصورة تتبع شريحتها إلى موضعها الجديد؛ صورة شريحة محذوفة تُفصل (slide_index = null). */
    public function applyMoves(ContentItem $item, array $moves): void
    {
        foreach ($item->mediaAssets()->whereNotNull('slide_index')->get() as $asset) {
            $target = $moves[$asset->slide_index] ?? null;

            if ($target !== $asset->slide_index) {
                $asset->update(['slide_index' => $target]);
            }
        }
    }

    /** @return array{template: string, date: string, quality: string} */
    public function design(?array $design): array
    {
        $design = (array) $design;
        $tiers = array_keys(config('ai.quality_tiers', []));

        return [
            'template' => array_key_exists($design['template'] ?? '', self::TEMPLATES) ? $design['template'] : 'coffee_modern',
            'date' => in_array($design['date'] ?? null, self::DATE_MODES, true) ? $design['date'] : 'none',
            'quality' => in_array($design['quality'] ?? null, $tiers, true) ? $design['quality'] : ($tiers[0] ?? 'standard_1k'),
        ];
    }

    /**
     * نسخة جديدة بالتعديلات، والأصل كما هو. صور الشرائح تُنسخ ملفاتها (لا مشاركة):
     * حذف صورة من أحدهما لاحقاً لا يُفقد الآخر صورته.
     */
    public function duplicate(ContentItem $item, array $slides, array $moves, array $design): ContentItem
    {
        return DB::transaction(function () use ($item, $slides, $moves, $design) {
            $copy = $item->replicate(['generation_job_id', 'quality']);
            $copy->body = ['slides' => $slides, 'design' => $design] + (array) $item->body;
            $copy->save();

            foreach ($item->slideImages() as $oldIndex => $asset) {
                $target = $moves[$oldIndex] ?? null;
                $disk = Storage::disk($asset->disk);

                if ($target === null || ! $disk->exists($asset->path)) {
                    continue;
                }

                $contents = $disk->get($asset->path);
                $path = preg_replace('#/[^/]+$#', '/'.Str::uuid().'.'.pathinfo($asset->path, PATHINFO_EXTENSION), $asset->path);
                $disk->put($path, $contents);

                $meta = array_diff_key((array) $asset->meta, ['thumb' => true]);

                if ($thumb = MediaAsset::putThumbnail($asset->disk, $path, $contents, keepAlpha: $asset->isTransparent())) {
                    $meta['thumb'] = $thumb;
                }

                // بلا generation_job_id: النسخة لا تتكرر في شبكة «الاستوديو»، وتظهر مع كاروسيلها
                MediaAsset::create([
                    'brand_id' => $copy->brand_id,
                    'content_item_id' => $copy->id,
                    'kind' => 'image',
                    'disk' => $asset->disk,
                    'path' => $path,
                    'mime' => $asset->mime,
                    'bytes' => strlen($contents),
                    'width' => $asset->width,
                    'height' => $asset->height,
                    'prompt' => $asset->prompt,
                    'meta' => $meta + ['copied_from' => $asset->id],
                    'slide_index' => $target,
                ]);
            }

            return $copy->fresh('mediaAssets');
        });
    }

    /** ما تحتاجه نافذة «نظام الكاروسيل» لرسم الشرائح وتعديلها. */
    public function payload(ContentItem $item): array
    {
        $item->loadMissing(['mediaAssets', 'brand.defaultLogo']);

        $slides = $item->slides();
        $images = $item->slideImages();
        $cover = $images[0] ?? null;
        $brand = $item->brand;
        $first = $slides[0] ?? [];

        $errors = collect($item->qualityIssues())
            ->where('severity', 'error')
            ->pluck('field')
            ->filter(fn ($field) => str_starts_with((string) $field, 'slide:'))
            ->map(fn ($field) => (int) substr($field, 6) - 1)
            ->unique()->values()->all();

        return [
            'id' => $item->id,
            'title' => Str::limit((string) ($item->body['title'] ?? $first['focal'] ?? $first['text'] ?? 'كاروسيل'), 70),
            'slides' => collect($slides)->map(fn ($slide, $index) => $slide + ['origin' => $index])->values()->all(),
            'images' => collect($images)->map(fn (MediaAsset $asset) => $asset->url())->all(),
            'thumbs' => collect($images)->map(fn (MediaAsset $asset) => $asset->thumbUrl())->all(),
            'design' => $this->design($item->body['design'] ?? null),
            'ratio' => $cover?->meta['aspect_ratio'] ?? '4:5',
            'dates' => $item->planned_for ? [
                'month' => $item->planned_for->translatedFormat('F Y'),
                'day_month' => $item->planned_for->translatedFormat('j F'),
            ] : null,
            'errors' => $errors,
            'productId' => $item->product_id,
            'templates' => self::TEMPLATES,
            'tiers' => collect(config('ai.quality_tiers', []))
                ->map(fn ($tier, $key) => ['label' => $tier['label'] ?? $key, 'credits' => (float) (config('credits.costs', [])["image.{$key}"] ?? $tier['credits'] ?? 1)])
                ->all(),
            'maxSlides' => self::MAX_SLIDES,
            'brand' => [
                'name' => $brand?->name,
                'logo' => $brand?->defaultLogo?->url(),
                'primary' => $brand?->colorFor(ColorRole::Primary),
                'accent' => $brand?->colorFor(ColorRole::Accent) ?? $brand?->colorFor(ColorRole::Secondary),
                'font' => $brand?->font('ar_primary'),
                'slug' => $brand?->slug,
            ],
            'urls' => [
                'save' => route('studio.carousels.update', $item),
                'copy' => route('studio.carousels.copy', $item),
                'regenerate' => route('studio.carousel', $item),
                'enhance' => route('studio.enhance'),
                'content' => route('content.show', $item),
                'data' => route('studio.carousels.show', $item),
            ],
        ];
    }
}
