<?php

namespace App\Http\Controllers;

use App\Models\ContentItem;
use App\Models\GenerationJob;
use App\Models\MediaAsset;
use App\Models\MediaFolder;
use App\Models\Product;
use App\Services\AI\ModelCatalog;
use App\Services\Content\CarouselEditing;
use App\Services\Content\ContentGenerationService;
use App\Services\Credits\CreditService;
use App\Services\Credits\DailyCapReachedException;
use App\Services\Credits\InsufficientCreditsException;
use App\Services\Media\ImageGenerationService;
use App\Services\Media\PromptEnhancer;
use App\Services\Media\StudioModels;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ImageStudioController extends Controller
{
    public function index(Request $request)
    {
        // معرض "توليد" = نتائج فعلية فقط؛ الصور المرفوعة من الجهاز مدخلات مرجعية
        // لا نتائج، فتبقى خارجه وتظهر فقط في مكتبة "الصور المرفوعة مسبقاً"
        // contentItem: صور شرائح الكاروسيل تُعرض بنص شريحتها لا ببرومبت الصورة الإنجليزي
        // contentItem.mediaAssets: شرائح الكاروسيل الواحد تُجمع في بطاقة مكدّسة واحدة (صورة كل شريحة الأحدث)
        $gallery = MediaAsset::where('kind', 'image')->whereNotNull('generation_job_id')->with('contentItem.mediaAssets');

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

        $models = app(StudioModels::class);
        $provider = $models->provider();

        // المعرض يكبر بلا سقف صامت: "عرض المزيد" يرفع الحد 60 في كل مرة (نجلب واحداً زائداً لمعرفة وجود المزيد)
        $limit = min(600, max(60, (int) $request->integer('limit', 60)));
        $items = $gallery->take($limit + 1)->get();
        $galleryEntries = $this->galleryEntries($items->take($limit));

        // شكل المهمة الجارية (عدد/نسبة) لعرض هياكل انتظار مطابقة لما سيصل.
        // صور الكاروسيل مهمة أم: عددها في indices ونسبتها عند أول شريحة
        $tracked = ($jobUuid = $request->string('job')->toString())
            ? GenerationJob::where('uuid', $jobUuid)->first()
            : null;
        $trackedChild = $tracked?->type === 'carousel_images'
            ? GenerationJob::where('parent_id', $tracked->id)->oldest('id')->first()
            : null;

        // تبويب «من خطة المحتوى»: محتوى الخطة الشهرية لشهر واحد، يُتنقّل بينه بالأسهم
        $monthParam = (string) $request->query('plan_month');
        $planMonth = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthParam)
            ? CarbonImmutable::createFromFormat('!Y-m', $monthParam)->startOfMonth()
            : CarbonImmutable::now()->startOfMonth();

        $planItems = ContentItem::inPlan()
            ->whereBetween('planned_for', [$planMonth->toDateString(), $planMonth->endOfMonth()->toDateString()])
            ->with(['product', 'mediaAssets'])
            ->orderBy('planned_for')
            ->orderBy('id')
            ->get();

        return view('content.studio', [
            'enhanceUrl' => route('studio.enhance'),
            'enhanceCost' => app(CreditService::class)->cost(PromptEnhancer::OPERATION),
            'trackedCount' => min(8, max(1, (int) (isset($tracked?->payload['indices']) ? count($tracked->payload['indices']) : ($tracked?->payload['count'] ?? 1)))),
            'trackedRatio' => (string) ($trackedChild?->payload['aspect_ratio'] ?? $tracked?->payload['aspect_ratio'] ?? '1:1'),
            'activeTab' => $request->query('tab') === 'plan' ? 'plan' : 'studio',
            'planMonth' => $planMonth,
            'slideImageCost' => app(CreditService::class)->cost('image.standard_1k'),
            'studioModelService' => $models,
            'modelCaps' => $models->allCapabilities(),
            'modelsRoutable' => $models->routable(),
            // حين لا توجيه: النموذج الفعلي المُعدّ (للقراءة فقط بدل قائمة مضلِّلة)
            'activeModel' => trim($provider.' · '.(config("ai.providers.{$provider}.image_model") ?? ''), ' ·'),
            'gallery' => $items->take($limit),
            'galleryEntries' => $galleryEntries,
            'galleryHasMore' => $items->count() > $limit,
            'galleryLimit' => $limit,
            'referenceLibrary' => $referenceLibrary,
            'folders' => MediaFolder::orderBy('name')->get(),
            'activeFolder' => $activeFolder,
            'pinnedOnly' => $pinnedOnly,
            'products' => Product::where('is_active', true)->withCount('images')->get(),
            // إزالة الخلفية تحتاج OpenRouter (نموذج بخلفية شفافة)؛ بغيره يظهر البند معطّلاً بسببه
            'removeBackgroundAvailable' => $models->routable(),
            'removeBackgroundCost' => app(CreditService::class)->cost(ImageGenerationService::REMOVE_BACKGROUND_OPERATION),
            'planItems' => $planItems,
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

    /**
     * بطاقات شبكة «الاستوديو» بترتيبها: صورة مفردة، أو كاروسيل كامل في بطاقة واحدة مكدّسة
     * (موضعها موضع أحدث صورة منه). كانت كل شريحة بطاقة مستقلة فيضيع الكاروسيل بين الصور.
     *
     * @return list<array{type: 'asset', asset: MediaAsset}|array{type: 'carousel', item: ContentItem}>
     */
    protected function galleryEntries(iterable $assets): array
    {
        $entries = [];
        $carousels = [];

        foreach ($assets as $asset) {
            $item = $asset->contentItem;

            if ($asset->slide_index !== null && $item?->format?->value === 'carousel' && $item->slides() !== []) {
                if (! isset($carousels[$item->id])) {
                    $carousels[$item->id] = true;
                    $entries[] = ['type' => 'carousel', 'item' => $item];
                }

                continue;
            }

            $entries[] = ['type' => 'asset', 'asset' => $asset];
        }

        return $entries;
    }

    public function store(Request $request, ImageGenerationService $service, StudioModels $models)
    {
        $data = $request->validate([
            'model' => ['nullable', Rule::in(array_keys(config('ai.studio_models')))],
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

        // نرفض قبل الحجز: لا يُخصم ثمن 4K أو جودة قصوى من نموذج لا ينتجهما
        if ($message = $models->unsupported($data['model'] ?? null, $data['quality'])) {
            return back()->withInput()->withErrors(['quality' => $message]);
        }

        try {
            $job = $service->dispatch($this->brand(), $data, $request->user()->id);
        } catch (InsufficientCreditsException|DailyCapReachedException $e) {
            return back()->withInput()->withErrors(['credits' => $e->getMessage()]);
        }

        return redirect()->route('studio.index', ['job' => $job->uuid]);
    }

    /**
     * تحسين وصف الصورة بالذكاء. لا استدعاء نموذج داخل الطلب (قاعدة المشروع §1): يُنشئ مهمة
     * في الطابور ويعيد رابط استطلاعها، والواجهة تضع النتيجة في حقل الوصف حين تكتمل.
     */
    public function enhance(Request $request, PromptEnhancer $enhancer)
    {
        $data = $request->validate([
            'prompt' => ['required', 'string', 'min:3', 'max:1500'],
            'aspect_ratio' => ['nullable', 'in:'.implode(',', array_keys(config('ai.aspect_ratios')))],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'reference_asset_id' => ['nullable', 'integer', Rule::exists('media_assets', 'id')->where('brand_id', $this->brand()->id)],
            'use_brand_identity' => ['nullable', 'boolean'],
        ], [
            'prompt.required' => 'اكتب وصفاً أولاً ثم اضغط تحسين.',
            'prompt.min' => 'الوصف قصير جداً للتحسين.',
        ]);

        try {
            $job = $enhancer->dispatch($this->brand(), $data, $request->user()->id);
        } catch (InsufficientCreditsException|DailyCapReachedException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['uuid' => $job->uuid, 'status_url' => route('api.jobs.show', $job)], 202);
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
            // من تبويب «من خطة المحتوى» في الاستوديو: تُتابَع الصور وتظهر في «الاستوديو» لا في صفحة المحتوى
            'return' => ['nullable', 'in:studio'],
            'aspect_ratio' => ['nullable', 'in:'.implode(',', array_keys(config('ai.aspect_ratios')))],
            'quality' => ['nullable', 'in:'.implode(',', array_keys(config('ai.quality_tiers')))],
        ]);

        $contentItem->load('mediaAssets');
        $existing = array_keys($contentItem->slideImages());

        $indices = match ($data['stage']) {
            'cover' => [0],
            // شريحة أُطفئت صورتها في «نظام الكاروسيل» (image=false) لا تُولَّد لها صورة
            'rest' => array_values(array_diff(
                array_keys(array_filter($contentItem->slides(), fn ($slide) => ($slide['image'] ?? true) !== false)),
                [0],
                $existing,
            )),
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
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 422)
                : back()->withErrors(['credits' => $e->getMessage()]);
        } catch (\InvalidArgumentException $e) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 422)
                : back()->withErrors(['stage' => $e->getMessage()]);
        }

        // من نافذة «نظام الكاروسيل»: تبقى النافذة مفتوحة وتستطلع المهمة بنفسها
        if ($request->expectsJson()) {
            return response()->json(['uuid' => $job->uuid, 'status_url' => route('api.jobs.show', $job)], 202);
        }

        if (($data['return'] ?? null) === 'studio') {
            return redirect()->route('studio.index', ['job' => $job->uuid]);
        }

        return redirect()->route('content.show', [$contentItem, 'job' => $job->uuid]);
    }

    /** بيانات نافذة «نظام الكاروسيل» (شرائح، صور، تصميم، هوية) — قراءة فقط بلا نموذج. */
    public function carouselData(ContentItem $contentItem, CarouselEditing $editing)
    {
        abort_unless($contentItem->format->value === 'carousel' && $contentItem->slides() !== [], 404);

        return response()->json($editing->payload($contentItem));
    }

    /** حفظ تعديلات النافذة على الكاروسيل نفسه (مجاني)، ثم إعادة فحص الصدق كأي تعديل يدوي. */
    public function saveCarousel(Request $request, ContentItem $contentItem, CarouselEditing $editing, ContentGenerationService $content)
    {
        abort_unless($contentItem->format->value === 'carousel', 404);

        [$slides, $moves, $design] = $this->editedCarousel($request, $contentItem, $editing);

        DB::transaction(function () use ($contentItem, $slides, $moves, $design, $editing) {
            $contentItem->update(['body' => ['slides' => $slides, 'design' => $design] + (array) $contentItem->body]);
            $editing->applyMoves($contentItem, $moves);
        });

        $contentItem->update(['quality' => $content->recheck($contentItem->refresh())]);

        return response()->json([
            'message' => $contentItem->needsReview() ? 'حُفظ، وفيه نقاط تحتاج مراجعة قبل النشر.' : 'حُفظت التعديلات.',
            'needsReview' => $contentItem->needsReview(),
            'carousel' => $editing->payload($contentItem->refresh()),
        ]);
    }

    /** «حفظ كنسخة جديدة»: التعديلات في كاروسيل جديد بصور منسوخة، والأصل كما هو. */
    public function copyCarousel(Request $request, ContentItem $contentItem, CarouselEditing $editing, ContentGenerationService $content)
    {
        abort_unless($contentItem->format->value === 'carousel', 404);

        [$slides, $moves, $design] = $this->editedCarousel($request, $contentItem, $editing);

        $copy = $editing->duplicate($contentItem->load('mediaAssets'), $slides, $moves, $design);
        $copy->update(['quality' => $content->recheck($copy)]);

        return response()->json([
            'message' => 'حُفظت كنسخة جديدة — الأصل لم يتغير.',
            'needsReview' => $copy->needsReview(),
            'carousel' => $editing->payload($copy->refresh()),
        ], 201);
    }

    /** @return array{0: list<array>, 1: array<int, int>, 2: array} */
    protected function editedCarousel(Request $request, ContentItem $contentItem, CarouselEditing $editing): array
    {
        $data = $request->validate([
            'slides' => ['required', 'array', 'min:3', 'max:'.CarouselEditing::MAX_SLIDES],
            'slides.*.origin' => ['nullable', 'integer', 'min:0'],
            'slides.*.role' => ['nullable', 'in:hook,promise,pull,harvest,ask'],
            'slides.*.text' => ['nullable', 'string', 'max:600'],
            'slides.*.kicker' => ['nullable', 'string', 'max:160'],
            'slides.*.focal' => ['nullable', 'string', 'max:160'],
            'slides.*.tail' => ['nullable', 'string', 'max:300'],
            'slides.*.visual' => ['nullable', 'string', 'max:2000'],
            'slides.*.layout' => ['nullable', 'array'],
            'slides.*.image' => ['nullable', 'boolean'],
            'design' => ['nullable', 'array'],
        ], [
            'slides.min' => 'الكاروسيل ثلاث شرائح على الأقل.',
            'slides.max' => 'الكاروسيل عشر شرائح على الأكثر.',
        ], ['slides' => 'الشرائح']);

        [$slides, $moves] = $editing->rebuild($contentItem->slides(), $data['slides']);

        if (count($slides) < 3) {
            throw ValidationException::withMessages(['slides' => 'الكاروسيل ثلاث شرائح على الأقل — شريحة بلا نص لا تُحفظ.']);
        }

        return [$slides, $moves, $editing->design($data['design'] ?? null)];
    }

    /** إرفاق صورة موجودة في الاستوديو بمحتوى — بلا توليد جديد. */
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
            'meta' => ['source' => 'upload'] + (
                ($thumb = MediaAsset::putThumbnail($disk, $path, (string) file_get_contents($file->getRealPath())))
                    ? ['thumb' => $thumb]
                    : []
            ),
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

        $mediaAsset->deleteFiles();
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
     * إزالة الخلفية: صورة جديدة شفافة بجانب الأصل (لا يُمسّ الأصل)، عبر الطابور كأي توليد.
     */
    public function removeBackground(MediaAsset $mediaAsset, Request $request, ImageGenerationService $service, StudioModels $models)
    {
        abort_unless($mediaAsset->kind === 'image', 404);

        if (! $models->routable()) {
            return back()->withErrors(['remove_background' => 'إزالة الخلفية تتطلب مزود الصور OpenRouter من إعدادات المنصة.']);
        }

        // النموذج المُعدّ يجب أن يعلن الخلفية الشفافة ويقبل صورة مرجعية؛ قدرات مجهولة = نحاول
        $params = app(ModelCatalog::class)->imageParameters((string) config('ai.remove_background.model'));

        if ($params !== null && (! in_array('transparent', $params['background']['values'] ?? [], true)
            || (int) data_get($params, 'input_references.max', 0) < 1)) {
            return back()->withErrors(['remove_background' => 'نموذج إزالة الخلفية المُعدّ لا يدعم الخلفية الشفافة. غيّر AI_REMOVE_BG_MODEL.']);
        }

        try {
            $job = $service->dispatchRemoveBackground($this->brand(), $mediaAsset, $request->user()->id);
        } catch (InsufficientCreditsException|DailyCapReachedException $e) {
            return back()->withErrors(['credits' => $e->getMessage()]);
        }

        return redirect()->route('studio.index', array_filter([
            'job' => $job->uuid,
            'folder' => $mediaAsset->folder_id,
        ]));
    }

    /**
     * حفظ صورة من المعرض كصورة مرجعية أساسية لمنتج: تُنسخ لصور المنتج وتصير مرجعه
     * البصري (is_reference) — فتُرفق تلقائياً في كل توليد قادم لهذا المنتج.
     */
    public function toProduct(MediaAsset $mediaAsset, Request $request)
    {
        abort_unless($mediaAsset->kind === 'image', 404);

        $data = $request->validate([
            'product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('brand_id', $this->brand()->id)],
        ], [], ['product_id' => 'المنتج']);

        $product = Product::findOrFail($data['product_id']);
        $max = Product::MAX_IMAGES[$product->type->value] ?? 6;

        if ($product->images()->count() >= $max) {
            return back()->withErrors(['product_id' => "«{$product->title}» وصل للحد الأقصى ({$max} صور). احذف صورة من صفحة المنتج ثم أعد المحاولة."]);
        }

        $source = Storage::disk($mediaAsset->disk);

        abort_unless($source->exists($mediaAsset->path), 404);

        // نسخة مستقلة في مجلد المنتج: حذف الصورة من المعرض لاحقاً لا يُفقد المنتج مرجعه
        $extension = pathinfo($mediaAsset->path, PATHINFO_EXTENSION) ?: 'png';
        $path = "brands/{$product->brand_id}/products/".Str::uuid().'.'.$extension;
        Storage::disk('public')->put($path, $source->get($mediaAsset->path));

        DB::transaction(function () use ($product, $path) {
            $image = $product->images()->create([
                'disk' => 'public',
                'path' => $path,
                'position' => ($product->images()->max('position') ?? -1) + 1,
                'is_reference' => false,
            ]);

            // استعلامان لا تحديث نموذج: نفس سبب ProductController::applyReferenceImage
            $product->images()->whereKeyNot($image->id)->update(['is_reference' => false]);
            $product->images()->whereKey($image->id)->update(['is_reference' => true]);
        });

        return back()->with('status', "صارت الصورة المرجع البصري الأساسي لـ«{$product->title}» — تُرفق في صوره القادمة.");
    }

    /** حذف جماعي نهائي (نفس قرار الحذف الفوري للصورة الواحدة). */
    public function bulkDestroy(Request $request)
    {
        $assets = $this->selectedAssets($request);

        foreach ($assets as $asset) {
            $asset->deleteFiles();
            $asset->delete();
        }

        return back()->with('status', $assets->count() === 1 ? 'حُذفت صورة واحدة نهائياً.' : "حُذفت {$assets->count()} صور نهائياً.");
    }

    /** نقل جماعي إلى مجلد، أو إلى «الكل» حين folder_id فارغ. */
    public function bulkMove(Request $request)
    {
        $data = $request->validate([
            'folder_id' => ['nullable', 'integer', Rule::exists('media_folders', 'id')->where('brand_id', $this->brand()->id)],
        ]);

        $assets = $this->selectedAssets($request);

        MediaAsset::whereKey($assets->modelKeys())->update(['folder_id' => $data['folder_id'] ?? null]);

        return back()->with('status', ($data['folder_id'] ?? null)
            ? "نُقلت {$assets->count()} صور إلى المجلد."
            : "أُعيدت {$assets->count()} صور إلى «الكل».");
    }

    /**
     * الصور المحددة لهذه العلامة فقط: النطاق العام BelongsToBrand يُسقط أي معرّف لعلامة أخرى
     * بصمت (لا خطأ يكشف وجوده)، ولا نمس إلا الصور.
     *
     * @return Collection<int, MediaAsset>
     */
    protected function selectedAssets(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:600'],
            'ids.*' => ['integer'],
        ], ['ids.required' => 'حدّد صورة واحدة على الأقل.']);

        return MediaAsset::whereIn('id', $data['ids'])->where('kind', 'image')->get();
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
