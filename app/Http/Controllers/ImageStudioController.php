<?php

namespace App\Http\Controllers;

use App\Models\ContentItem;
use App\Models\MediaAsset;
use App\Models\Product;
use App\Services\Credits\DailyCapReachedException;
use App\Services\Credits\InsufficientCreditsException;
use App\Services\Media\ImageGenerationService;
use Illuminate\Http\Request;

class ImageStudioController extends Controller
{
    public function index()
    {
        return view('content.studio', [
            'gallery' => MediaAsset::where('kind', 'image')->latest()->take(24)->get(),
            'products' => Product::where('is_active', true)->get(),
            'planItems' => ContentItem::whereIn('status', ['ready', 'draft'])->latest()->take(20)->get(),
            'ratios' => config('ai.aspect_ratios'),
            'qualities' => config('ai.quality_tiers'),
        ]);
    }

    public function store(Request $request, ImageGenerationService $service)
    {
        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:1500'],
            'aspect_ratio' => ['required', 'in:'.implode(',', array_keys(config('ai.aspect_ratios')))],
            'quality' => ['required', 'in:'.implode(',', array_keys(config('ai.quality_tiers')))],
            'count' => ['nullable', 'integer', 'min:1', 'max:4'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'content_item_id' => ['nullable', 'integer', 'exists:content_items,id'],
            'use_brand_identity' => ['nullable', 'boolean'],
        ]);

        try {
            $job = $service->dispatch($this->brand(), $data, $request->user()->id);
        } catch (InsufficientCreditsException|DailyCapReachedException $e) {
            return back()->withInput()->withErrors(['credits' => $e->getMessage()]);
        }

        return redirect()->route('studio.index', ['job' => $job->uuid]);
    }

    /**
     * صور الكاروسيل على مراحل (المرحلة 4 في خطة التدقيق):
     *   cover  الغلاف وحده — يرى التاجر الأسلوب قبل أن يدفع للبقية
     *   rest   ما بقي بلا صورة، بالغلاف مرجعاً
     *   slide  شريحة واحدة بعينها
     */
    public function carousel(Request $request, ContentItem $contentItem, ImageGenerationService $service)
    {
        $data = $request->validate([
            'stage' => ['required', 'in:cover,rest,slide'],
            'index' => ['required_if:stage,slide', 'nullable', 'integer', 'min:0'],
            'aspect_ratio' => ['nullable', 'in:'.implode(',', array_keys(config('ai.aspect_ratios')))],
            'quality' => ['nullable', 'in:'.implode(',', array_keys(config('ai.quality_tiers')))],
        ]);

        $contentItem->load('mediaAssets');
        $existing = array_keys($contentItem->slideImages());

        $indices = match ($data['stage']) {
            'cover' => [0],
            'rest' => array_values(array_diff(array_keys($contentItem->slides()), [0], $existing)),
            'slide' => [(int) $data['index']],
        };

        if ($data['stage'] === 'rest' && ! $contentItem->coverImage()) {
            return back()->withErrors(['stage' => 'ولّد الغلاف واعتمده أولاً: بقية الشرائح تتبع أسلوبه.']);
        }

        if ($indices === []) {
            return back()->with('status', 'لكل الشرائح صور بالفعل.');
        }

        try {
            $job = $service->dispatchSlides($this->brand(), $contentItem, $indices, $data, $request->user()->id);
        } catch (InsufficientCreditsException|DailyCapReachedException $e) {
            return back()->withErrors(['credits' => $e->getMessage()]);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['stage' => $e->getMessage()]);
        }

        return redirect()->route('content.show', [$contentItem, 'job' => $job->uuid]);
    }

    /** إرفاق صورة موجودة في المعرض بمحتوى — بلا توليد جديد. */
    public function attach(Request $request, ContentItem $contentItem)
    {
        $data = $request->validate([
            'media_asset_id' => ['required', 'integer', 'exists:media_assets,id'],
        ]);

        MediaAsset::findOrFail($data['media_asset_id'])->update([
            'content_item_id' => $contentItem->id,
        ]);

        return back()->with('status', 'أُرفقت الصورة بالمحتوى.');
    }
}
