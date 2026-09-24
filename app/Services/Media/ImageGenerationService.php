<?php

namespace App\Services\Media;

use App\Enums\JobStatus;
use App\Jobs\GenerateImageJob;
use App\Models\Brand;
use App\Models\ContentItem;
use App\Models\GenerationJob;
use App\Models\MediaAsset;
use App\Services\AI\AiManager;
use App\Services\AI\DTO\ImageRequest;
use App\Services\Credits\CreditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageGenerationService
{
    public function __construct(
        protected AiManager $ai,
        protected CreditService $credits,
    ) {}

    /**
     * @param  array{prompt:string, aspect_ratio?:string, quality?:string, count?:int, content_item_id?:int|null, use_brand_identity?:bool}  $input
     */
    public function dispatch(Brand $brand, array $input, ?int $userId = null): GenerationJob
    {
        $quality = $input['quality'] ?? 'standard_1k';
        $count = max((int) ($input['count'] ?? 1), 1);
        $operation = "image.{$quality}";

        return DB::transaction(function () use ($brand, $input, $quality, $count, $operation, $userId) {
            $job = GenerationJob::create([
                'brand_id' => $brand->id,
                'user_id' => $userId,
                'type' => 'image',
                'status' => JobStatus::Queued,
                'payload' => [
                    'prompt' => $input['prompt'],
                    'aspect_ratio' => $input['aspect_ratio'] ?? '1:1',
                    'quality' => $quality,
                    'count' => $count,
                    'content_item_id' => $input['content_item_id'] ?? null,
                    'slide_index' => $input['slide_index'] ?? null,
                    'use_brand_identity' => (bool) ($input['use_brand_identity'] ?? true),
                    'product_id' => $input['product_id'] ?? null,
                    'reference_asset_id' => $input['reference_asset_id'] ?? null,
                ],
            ]);

            $this->credits->hold($brand, $operation, $count, $job);

            GenerateImageJob::dispatch($job->id)->onQueue('media');

            return $job->fresh();
        });
    }

    public function run(GenerationJob $job): void
    {
        $brand = $job->brand;
        $payload = $job->payload;
        $quality = $payload['quality'] ?? 'standard_1k';
        $operation = "image.{$quality}";
        $count = (int) ($payload['count'] ?? 1);

        $job->markProcessing();

        $prompt = $this->composePrompt($brand, $payload);
        $reference = $this->referenceImagePath($brand, $payload);

        $response = $this->ai->generateImage(new ImageRequest(
            prompt: $prompt,
            aspectRatio: $payload['aspect_ratio'] ?? '1:1',
            quality: $quality,
            count: $count,
            referenceImage: $reference,
            operation: $operation,
        ), $job);

        $assetIds = [];

        foreach ($response->images as $index => $image) {
            // Gemini قد يعيد JPEG؛ الامتداد يتبع النوع الفعلي لا افتراض PNG
            $path = sprintf(
                'brands/%d/media/%s.%s',
                $brand->id,
                Str::uuid(),
                match ($image->mime) {
                    'image/jpeg' => 'jpg',
                    'image/webp' => 'webp',
                    default => 'png',
                }
            );

            Storage::disk(config('ai.media_disk'))->put($path, $image->contents);

            $asset = MediaAsset::create([
                'brand_id' => $brand->id,
                'content_item_id' => $payload['content_item_id'] ?? null,
                'generation_job_id' => $job->id,
                'kind' => 'image',
                'disk' => config('ai.media_disk'),
                'path' => $path,
                'mime' => $image->mime,
                'bytes' => strlen($image->contents),
                'width' => $image->width,
                'height' => $image->height,
                'prompt' => $prompt,
                'seed' => $image->seed,
                'meta' => $image->meta + ['quality' => $quality, 'aspect_ratio' => $payload['aspect_ratio'] ?? '1:1'],
                'slide_index' => $payload['slide_index'] ?? null,
            ]);

            $assetIds[] = $asset->id;
        }

        $produced = count($assetIds);
        $unitCost = $this->credits->cost($operation);

        // ندفع مقابل ما وصل فعلاً لا مقابل ما طُلب
        $this->credits->settle($brand, (int) $job->credits_held, $unitCost * $produced, $job, $operation);

        $job->markCompleted(
            ['media_asset_ids' => $assetIds],
            $produced < $count ? 'partial' : 'completed'
        );

        $this->finishChild($job);
    }

    /**
     * حقن الهوية البصرية في البرومبت: الألوان والنمط.
     * الشعار يُركَّب برمجياً بعد التوليد، والنص العربي لا يُطلب من النموذج إطلاقاً.
     */
    protected function composePrompt(Brand $brand, array $payload): string
    {
        $prompt = trim($payload['prompt']);

        if (! ($payload['use_brand_identity'] ?? true)) {
            return $prompt.' No text, no letters, no watermark.';
        }

        $parts = [$prompt];

        if ($palette = $brand->paletteForPrompt()) {
            $parts[] = 'Color palette: '.$palette.'.';
        }

        if (filled($brand->visual_style)) {
            $parts[] = "Visual style: {$brand->visual_style}.";
        }

        if (filled($brand->design_summary)) {
            $parts[] = "Brand design direction: {$brand->design_summary}.";
        }

        // النماذج ضعيفة في الخط العربي: نمنع أي نص داخل الصورة
        $parts[] = 'Professional commercial photography, clean composition, high detail.';
        $parts[] = 'Absolutely no text, no letters, no logos, no watermarks in the image.';

        return implode(' ', $parts);
    }

    protected function referenceImagePath(Brand $brand, array $payload): ?string
    {
        // غلاف الكاروسيل يتقدّم على صورة المنتج: فيه المنتج نفسه، وبالأسلوب المعتمد
        if (! empty($payload['reference_asset_id'])) {
            $asset = MediaAsset::forBrand($brand)->find($payload['reference_asset_id']);

            if ($asset) {
                return Storage::disk($asset->disk)->path($asset->path);
            }
        }

        if (empty($payload['product_id'])) {
            return null;
        }

        $product = $brand->products()->with('images')->find($payload['product_id']);
        $image = $product?->referenceImage();

        if (! $image) {
            return null;
        }

        return $image->original_url ?: Storage::disk($image->disk)->path($image->path);
    }

    /**
     * صور شرائح الكاروسيل على مراحل: الغلاف وحده أولاً، ثم البقية بالغلاف مرجعاً
     * بصرياً، أو شريحة واحدة بعينها. لا يدفع التاجر ثمن سبع صور قبل أن يرى أسلوبها.
     *
     * كل شريحة مهمة بحجزها وتسويتها، والمهمة الأم تعدّ الأبناء وتُغلق حين يكتملون.
     * (كان الحجز على الأم والتسوية على الأبناء بلا حجز: الأم لا تُسوّى ولا تُغلق أبداً.)
     *
     * @param  array<int, int>  $indices
     */
    public function dispatchSlides(Brand $brand, ContentItem $item, array $indices, array $options = [], ?int $userId = null): GenerationJob
    {
        $slides = $item->slides();
        $indices = array_values(array_unique(array_filter($indices, fn ($i) => isset($slides[$i]))));

        if ($indices === []) {
            throw new \InvalidArgumentException('لا شرائح لتوليد صورها.');
        }

        $cover = $item->coverImage();
        $quality = $options['quality'] ?? 'standard_1k';
        // الشرائح بنسبة الغلاف: كاروسيل بنسبتين يُقصّ عند النشر
        $ratio = $options['aspect_ratio'] ?? ($cover?->meta['aspect_ratio'] ?? '4:5');
        $operation = "image.{$quality}";

        return DB::transaction(function () use ($brand, $item, $indices, $cover, $quality, $ratio, $operation, $userId) {
            $parent = GenerationJob::create([
                'brand_id' => $brand->id,
                'user_id' => $userId,
                'type' => 'carousel_images',
                'status' => JobStatus::Queued,
                'payload' => ['content_item_id' => $item->id, 'indices' => $indices, 'quality' => $quality],
                'children_total' => count($indices),
            ]);

            $children = [];

            foreach ($indices as $index) {
                $useCover = $index > 0 && $cover !== null;

                $child = GenerationJob::create([
                    'brand_id' => $brand->id,
                    'user_id' => $userId,
                    'type' => 'image',
                    'status' => JobStatus::Queued,
                    'parent_id' => $parent->id,
                    'payload' => [
                        'prompt' => $this->slidePrompt($item, $index, $useCover),
                        'aspect_ratio' => $ratio,
                        'quality' => $quality,
                        'count' => 1,
                        'content_item_id' => $item->id,
                        'slide_index' => $index,
                        'product_id' => $item->product_id,
                        'use_brand_identity' => true,
                        // الغلاف مرجع البقية: أسلوب واحد عبر الشرائح
                        'reference_asset_id' => $useCover ? $cover->id : null,
                    ],
                ]);

                $this->credits->hold($brand, $operation, 1, $child);
                $children[] = $child;
            }

            // الدفع للطابور بعد الحجز كله: نقص الرصيد في الشريحة الخامسة يلغي ما قبلها
            foreach ($children as $child) {
                GenerateImageJob::dispatch($child->id)->onQueue('media');
            }

            return $parent->fresh();
        });
    }

    /**
     * برومبت صورة شريحة: توجيهها البصري، ومساحة هادئة للعنوان، والغلاف مرجعاً.
     * النص نفسه لا يُطلب من النموذج إطلاقاً: يُركَّب بقالبنا فوق الصورة.
     */
    public function slidePrompt(ContentItem $item, int $index, bool $matchCover = false): string
    {
        $slide = $item->slides()[$index] ?? ['text' => ''];

        $visual = $slide['visual']
            ?? ($item->body['image_prompts'][$index] ?? null)
            ?? 'Editorial product photo illustrating: '.mb_substr((string) $slide['text'], 0, 120);

        return implode(' ', array_filter([
            rtrim($visual, '. ').'.',
            'Social media carousel slide. Keep the top 45% of the frame calm and uncluttered for an overlaid headline.',
            $matchCover ? 'Match the reference image exactly in visual style, lighting, color grading and product appearance, as the next slide of the same carousel.' : null,
        ]));
    }

    /**
     * يُستدعى عند انتهاء أي شريحة، نجحت أو فشلت. آخرها يغلق المهمة الأم.
     */
    public function finishChild(GenerationJob $job): void
    {
        if (! $job->parent_id) {
            return;
        }

        DB::transaction(function () use ($job) {
            $parent = GenerationJob::withoutBrandScope()->lockForUpdate()->find($job->parent_id);

            if (! $parent || $parent->status->isFinished()) {
                return;
            }

            $parent->increment('children_done');

            if ($parent->children_done < $parent->children_total) {
                return;
            }

            $children = $parent->children()->withoutGlobalScope('brand')->get();
            $failed = $children->where('status', JobStatus::Failed)->count();
            $assets = $children->flatMap(fn ($child) => (array) ($child->result['media_asset_ids'] ?? []))->values()->all();

            if ($assets === []) {
                $parent->markFailed($children->firstWhere('status', JobStatus::Failed)?->error
                    ?? 'تعذّر توليد صور الشرائح. أُرجعت نقاطها.');

                return;
            }

            $parent->markCompleted(['media_asset_ids' => $assets, 'failed' => $failed], $failed > 0 ? 'partial' : 'completed');
        });
    }
}
