<?php

namespace App\Http\Controllers;

use App\Models\ContentItem;
use App\Models\MediaAsset;
use App\Models\MediaFolder;
use App\Models\Product;
use App\Services\Credits\DailyCapReachedException;
use App\Services\Credits\InsufficientCreditsException;
use App\Services\Media\ImageGenerationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ImageStudioController extends Controller
{
    public function index(Request $request)
    {
        // معرض "توليد" = نتائج فعلية فقط؛ الصور المرفوعة من الجهاز مدخلات مرجعية
        // لا نتائج، فتبقى خارجه وتظهر فقط في مكتبة "الصور المرفوعة مسبقاً"
        $gallery = MediaAsset::where('kind', 'image')->whereNotNull('generation_job_id');

        $pinnedOnly = $request->boolean('pinned');
        $activeFolder = $pinnedOnly ? null : ($request->integer('folder') ?: null);

        if ($pinnedOnly) {
            $gallery->where('is_pinned', true);
        } elseif ($activeFolder) {
            $gallery->where('folder_id', $activeFolder);
        }

        if ($search = trim((string) $request->get('q'))) {
            $gallery->where('prompt', 'like', '%'.$search.'%');
        }

        $gallery->orderBy('created_at', $request->get('sort') === 'oldest' ? 'asc' : 'desc');

        $referenceLibrary = MediaAsset::where('kind', 'image')->latest()->take(60)->get();

        return view('content.studio', [
            'gallery' => $gallery->take(60)->get(),
            'referenceLibrary' => $referenceLibrary,
            'folders' => MediaFolder::orderBy('name')->get(),
            'activeFolder' => $activeFolder,
            'pinnedOnly' => $pinnedOnly,
            'products' => Product::where('is_active', true)->get(),
            'planItems' => ContentItem::whereIn('status', ['ready', 'draft'])->latest()->take(20)->get(),
            // نطاق التصميم الجديد (/studio فقط) — الكاروسيل في content/show.blade.php يبقى على ai.quality_tiers
            'ratios' => config('ai.aspect_ratios'),
            'resolutions' => config('ai.resolutions'),
            'qualityLevels' => config('ai.quality_levels'),
            'qualityMatrix' => config('ai.image_quality_matrix'),
            'studioModels' => config('ai.studio_models'),
            'defaultModel' => config('ai.studio_default_model'),
            'search' => $search,
            'sort' => $request->get('sort', 'newest'),
        ]);
    }

    public function store(Request $request, ImageGenerationService $service)
    {
        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:1500'],
            'aspect_ratio' => ['required', 'in:'.implode(',', array_keys(config('ai.aspect_ratios')))],
            'quality' => ['required', 'in:'.implode(',', array_keys(config('ai.image_quality_matrix')))],
            'count' => ['nullable', 'integer', 'min:1', 'max:4'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'content_item_id' => ['nullable', 'integer', 'exists:content_items,id'],
            'use_brand_identity' => ['nullable', 'boolean'],
            // اختيار صورة سابقة من معرض العلامة كمرجع بصري — يكشف آلية reference_asset_id
            // الموجودة فعلاً لتوليد شرائح الكاروسيل (ImageGenerationService::referenceImagePath)
            'reference_asset_id' => [
                'nullable', 'integer',
                Rule::exists('media_assets', 'id')->where('brand_id', $this->brand()->id),
            ],
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

    /**
     * إعادة توليد صورة بنفس معطياتها الأصلية — لا بيانات جديدة هنا، فقط
     * إعادة تشغيل ImageGenerationService::dispatch() بحمولة المهمة المخزَّنة فعلاً
     * في generation_jobs.payload (البند 2.4 في docs/image-studio-redesign-plan.md).
     */
    public function regenerate(MediaAsset $mediaAsset, Request $request, ImageGenerationService $service)
    {
        abort_unless($mediaAsset->kind === 'image', 404);

        $payload = $mediaAsset->generationJob?->payload;

        abort_if($payload === null, 404);

        try {
            $job = $service->dispatch($this->brand(), $payload, $request->user()->id);
        } catch (InsufficientCreditsException|DailyCapReachedException $e) {
            return back()->withErrors(['credits' => $e->getMessage()]);
        }

        return redirect()->route('studio.index', ['job' => $job->uuid]);
    }

    /**
     * رفع صورة مرجعية من الجهاز — تُحفظ فوراً كـMediaAsset عادي (بلا توليد ولا
     * توليد job) ليُستخدم مساراً فوراً كمرجع، على نمط BrandLogoController.
     * استجابة JSON: الشريط يستدعيها بـfetch لا بإرسال نموذج كامل الصفحة
     * (البند 2.8 في docs/image-studio-redesign-plan.md).
     */
    public function upload(Request $request)
    {
        $data = $request->validate([
            'file' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:10240'],
        ], [], ['file' => 'الصورة']);

        $brand = $this->brand();
        $disk = config('ai.media_disk', 'public');
        $file = $data['file'];

        $path = $file->store("brands/{$brand->id}/media/uploads", $disk);
        $size = @getimagesize($file->getRealPath());

        $asset = MediaAsset::create([
            'brand_id' => $brand->id,
            'kind' => 'image',
            'disk' => $disk,
            'path' => $path,
            'mime' => $file->getMimeType(),
            'bytes' => $file->getSize(),
            'width' => $size[0] ?? null,
            'height' => $size[1] ?? null,
            'meta' => ['source' => 'upload'],
        ]);

        return response()->json(['id' => $asset->id, 'url' => $asset->url()]);
    }

    /**
     * حذف نهائي فوري (قرار المستخدم 2026-09-23 — لا حذف ناعم): يمسح الملف من
     * التخزين ثم السجل. البند 2.3 في docs/image-studio-redesign-plan.md.
     */
    public function destroy(MediaAsset $mediaAsset)
    {
        abort_unless($mediaAsset->kind === 'image', 404);

        Storage::disk($mediaAsset->disk)->delete($mediaAsset->path);
        $mediaAsset->delete();

        return back()->with('status', 'حُذفت الصورة نهائياً.');
    }

    /** نقل صورة إلى مجلد، أو إعادتها لغير مصنّف (folder_id فارغ). */
    public function move(MediaAsset $mediaAsset, Request $request)
    {
        abort_unless($mediaAsset->kind === 'image', 404);

        $data = $request->validate([
            'folder_id' => [
                'nullable', 'integer',
                Rule::exists('media_folders', 'id')->where('brand_id', $this->brand()->id),
            ],
        ]);

        $mediaAsset->update(['folder_id' => $data['folder_id'] ?? null]);

        return back()->with('status', $data['folder_id'] ?? null ? 'نُقلت الصورة إلى المجلد.' : 'أُعيدت الصورة إلى "الكل".');
    }

    /**
     * تثبيت/إلغاء تثبيت صورة — زر القلب في العارض الكامل. استجابة JSON دوماً:
     * يُستدعى بـfetch من العارض حتى لا يُغلق بإعادة تحميل الصفحة (مثل upload()).
     */
    public function pin(MediaAsset $mediaAsset)
    {
        abort_unless($mediaAsset->kind === 'image', 404);

        $mediaAsset->update(['is_pinned' => ! $mediaAsset->is_pinned]);

        return response()->json(['pinned' => $mediaAsset->is_pinned]);
    }
}
