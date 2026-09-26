<?php

namespace App\Http\Controllers;

use App\Enums\ProductType;
use App\Models\GenerationJob;
use App\Models\Product;
use App\Services\Import\ImportService;
use App\Services\Products\SpecSheetBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    protected const MAX_IMAGES = Product::MAX_IMAGES;

    public function index(Request $request, ImportService $imports)
    {
        $products = Product::with('images')
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.$request->string('q').'%';

                $query->where(fn ($q) => $q
                    ->where('title', 'like', $term)
                    ->orWhere('summary', 'like', $term));
            })
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->latest()
            ->paginate(12)
            ->withQueryString();

        $counts = [
            'all' => Product::count(),
            'good' => Product::where('type', 'good')->count(),
            'service' => Product::where('type', 'service')->count(),
        ];

        // العنصر المطلوب تعديله قد يقع خارج الصفحة الحالية، فنجلبه صراحة
        $editing = $request->filled('edit')
            ? Product::with('images')->find($request->integer('edit'))
            : null;

        // مهمة المسح أو الاستيراد الجارية — النافذة تُفتح على حالتها لا من الصفر
        $scan = $request->filled('import')
            ? GenerationJob::where('uuid', $request->string('import'))->where('type', 'store_scan')->first()
            : null;

        $importing = $request->filled('importing')
            ? GenerationJob::where('uuid', $request->string('importing'))->where('type', 'product_import')->first()
            : null;

        $importCost = $imports->costPerProduct();

        return view('products.index', compact(
            'products', 'counts', 'editing', 'scan', 'importing', 'importCost'
        ));
    }

    /**
     * النماذج صارت نوافذ داخل القائمة.
     * نُبقي المسارين القديمين حتى لا تنكسر الروابط المحفوظة أو الواردة من لوحة التحكم.
     */
    public function create()
    {
        return redirect()->route('products.index', ['add' => 1]);
    }

    public function edit(Product $product)
    {
        return redirect()->route('products.index', ['edit' => $product->id]);
    }

    public function store(Request $request, SpecSheetBuilder $specSheets)
    {
        $data = $this->validated($request);

        $product = Product::create($data);

        $this->syncImages($request, $product);
        $this->refresh($product, $specSheets);

        return redirect()
            ->route('products.index')
            ->with('status', $product->type === ProductType::Service
                ? 'أُضيفت الخدمة وجُهزت ورقتها المرجعية.'
                : 'أُضيف المنتج وجُهزت ورقته المرجعية.');
    }

    public function update(Request $request, Product $product, SpecSheetBuilder $specSheets)
    {
        $data = $this->validated($request);

        $product->update($data);

        $this->syncImages($request, $product);
        $this->refresh($product, $specSheets);

        return redirect()->route('products.index')->with('status', 'حُفظت التعديلات.');
    }

    public function destroy(Product $product)
    {
        $this->deleteImageFiles($product);
        $product->delete();

        return back()->with('status', 'حُذف العنصر.');
    }

    /**
     * المنتج المرجعي الأساسي: مبدّل لا مفتاح تشغيل.
     * الضغط على المرجع الحالي يلغيه، والضغط على غيره ينقل المرجعية إليه.
     */
    public function togglePrimary(Product $product)
    {
        $becomingPrimary = ! $product->is_primary;

        Product::where('is_primary', true)->update(['is_primary' => false]);
        $product->update(['is_primary' => $becomingPrimary]);

        return back()->with('status', $becomingPrimary
            ? "«{$product->title}» صار المرجع الأساسي."
            : 'أُلغي المرجع الأساسي.');
    }

    public function duplicate(Product $product, SpecSheetBuilder $specSheets)
    {
        $copy = $product->replicate(['is_primary', 'external_id', 'synced_at']);
        $copy->title = $product->title.' — نسخة';
        $copy->is_primary = false;
        $copy->external_id = null;
        $copy->source = 'manual';
        $copy->save();

        foreach ($product->images as $image) {
            $copy->images()->create($image->only(['disk', 'path', 'original_url', 'is_reference', 'position']));
        }

        $this->refresh($copy, $specSheets);

        return redirect()
            ->route('products.index', ['edit' => $copy->id])
            ->with('status', 'أُنشئت نسخة — عدّلها ثم احفظ.');
    }

    /**
     * إجراءات على المحدد. الحذف هنا لا يمر بحوار تأكيد الصفحة،
     * لذا نتحقق من الأسماء قبل التنفيذ ونعيد عدداً صريحاً للمستخدم.
     */
    public function bulk(Request $request)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['activate', 'deactivate', 'delete'])],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $products = Product::whereIn('id', $data['ids'])->get();

        if ($products->isEmpty()) {
            return back()->withErrors(['ids' => 'لم يُحدَّد أي عنصر.']);
        }

        $count = $products->count();

        if ($data['action'] === 'delete') {
            $products->each(function (Product $product) {
                $this->deleteImageFiles($product);
                $product->delete();
            });

            return back()->with('status', "حُذف {$count} عنصراً.");
        }

        $active = $data['action'] === 'activate';
        Product::whereIn('id', $products->pluck('id'))->update(['is_active' => $active]);

        return back()->with('status', $active
            ? "فُعّل {$count} عنصراً للتوليد."
            : "أُوقف {$count} عنصراً عن التوليد.");
    }

    // ==================================================================

    protected function validated(Request $request): array
    {
        $type = $request->input('type');
        $isService = $type === 'service';

        $rules = [
            'type' => ['required', Rule::in(['good', 'service'])],
            'title' => ['required', 'string', 'max:180'],

            // المميزات للسلعة = وصف الخدمة للخدمة: كلاهما النص الوصفي الأساسي
            'features' => ['required', 'string', 'max:5000'],
            'summary' => ['nullable', 'string', 'max:600'],
            // يرث من العلامة عند تركه فارغاً، فلا يوقف تعديلاً بسيطاً على عنصر قديم
            'audience' => ['nullable', 'string', 'max:1000'],

            // اختيارية: الإلزام كان يجبر التاجر على «غير متوفرة» (تدقيق المنافس P2)
            'specifications' => ['nullable', 'string', 'max:5000'],
            'deliverables' => [$isService ? 'required' : 'nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'brand_name' => ['nullable', 'string', 'max:120'],

            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'currency' => ['nullable', 'string', 'size:3'],

            // حقائق البيع: ما يُذكر بدل أن يُخترع
            'compare_at_price' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'sale_ends_at' => ['nullable', 'date'],
            'stock_status' => ['nullable', Rule::in(array_keys(Product::STOCK))],
            'rating_value' => ['nullable', 'numeric', 'min:1', 'max:5', 'required_with:rating_count'],
            'rating_count' => ['nullable', 'integer', 'min:1', 'max:10000000', 'required_with:rating_value'],
            'installment_providers' => ['nullable', 'array'],
            'installment_providers.*' => [Rule::in(array_keys(Product::INSTALLMENT_PROVIDERS))],
            'installment_count' => ['nullable', 'integer', 'min:2', 'max:12'],

            'is_primary' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],

            'images' => ['nullable', 'array', 'max:'.(self::MAX_IMAGES[$type] ?? 6)],
            'images.*' => ['image', 'mimes:png,jpg,jpeg,webp', 'max:10240'],

            // روابط صور قادمة من استيراد رابط واحد — تُخزَّن كمرجع لا كملف
            'image_urls' => ['nullable', 'array', 'max:6'],
            'image_urls.*' => ['url', 'max:2000'],

            // معرض الصور: ما وُسم للحذف، وأي صورة اختارها المستخدم مرجعاً بصرياً
            'removed_image_ids' => ['nullable', 'array'],
            'removed_image_ids.*' => ['integer'],
            'reference' => ['nullable', 'string', 'max:2100'],
        ];

        $data = $request->validate($rules, [], [
            'title' => $isService ? 'اسم الخدمة' : 'اسم المنتج',
            'features' => $isService ? 'وصف الخدمة' : 'المميزات',
            'specifications' => 'المواصفات',
            'deliverables' => 'ماذا يحصل العميل',
            'audience' => 'الجمهور المستهدف',
            'notes' => 'ملاحظات إضافية',
            'summary' => 'الوصف المختصر',
            'price' => 'السعر',
            'brand_name' => 'العلامة التجارية',
            'compare_at_price' => 'السعر قبل الخصم',
            'sale_ends_at' => 'نهاية الخصم',
            'stock_status' => 'التوفر',
            'rating_value' => 'التقييم',
            'rating_count' => 'عدد التقييمات',
            'installment_providers' => 'التقسيط',
            'installment_count' => 'عدد الدفعات',
            'images' => 'الصور',
            'images.*' => 'الصورة',
        ]);

        // سعر «قبل الخصم» لا يكون أقل من الحالي: هذا ليس خصماً بل خطأ إدخال
        if (isset($data['compare_at_price'], $data['price']) && (float) $data['compare_at_price'] <= (float) $data['price']) {
            throw ValidationException::withMessages([
                'compare_at_price' => 'السعر قبل الخصم يجب أن يكون أعلى من السعر الحالي.',
            ]);
        }

        $data['installments'] = collect($data['installment_providers'] ?? [])
            ->unique()
            ->map(fn ($provider) => ['provider' => $provider, 'count' => (int) ($data['installment_count'] ?? 4)])
            ->values()
            ->all() ?: null;

        $data['sale_ends_at'] = filled($data['compare_at_price'] ?? null) ? ($data['sale_ends_at'] ?? null) : null;

        unset(
            $data['images'], $data['image_urls'], $data['installment_providers'],
            $data['installment_count'], $data['removed_image_ids'], $data['reference'],
        );

        // الوصف المكتوب أو المولّد يبقى كما هو؛ الاشتقاق للفارغ فقط
        $data['summary'] = filled($data['summary'] ?? null)
            ? trim($data['summary'])
            : Product::deriveSummary($data);

        $data['audience'] = filled($data['audience'] ?? null)
            ? $data['audience']
            : $this->brand()->audience;

        $data['currency'] = strtoupper($data['currency'] ?? 'SAR');
        $data['is_primary'] = (bool) ($data['is_primary'] ?? false);
        $data['is_active'] = (bool) ($data['is_active'] ?? true);

        // الخدمة لا تحمل مواصفات، والسلعة لا تحمل تسليمات: ننظّف حتى لا تتسرب بين النوعين
        if ($isService) {
            $data['specifications'] = null;
        } else {
            $data['deliverables'] = null;
        }

        return $data;
    }

    protected function refresh(Product $product, SpecSheetBuilder $specSheets): void
    {
        $product->load('images');
        $product->update(['spec_sheet' => $specSheets->build($product)]);

        if ($product->is_primary) {
            Product::where('id', '!=', $product->id)->update(['is_primary' => false]);
        }
    }

    /**
     * الحذف أولاً ثم الإضافة: بترتيب معكوس قد يُرفض رفعُ صورة
     * بحجة بلوغ الحد الأقصى، بينما المستخدم أفسح لها مكاناً بالحذف.
     */
    protected function syncImages(Request $request, Product $product): void
    {
        $this->removeMarkedImages($request, $product);

        $byUrl = $this->syncRemoteImages($request, $product);
        $byIndex = $this->syncUploadedImages($request, $product);

        $this->applyReferenceImage($request, $product, $byUrl, $byIndex);
    }

    /**
     * الحذف يمر عبر علاقة المنتج لا عبر النموذج مباشرة،
     * فمعرّف يخص منتجاً آخر لا يُحذف مهما أرسله المتصفح.
     */
    protected function removeMarkedImages(Request $request, Product $product): void
    {
        $ids = array_filter((array) $request->input('removed_image_ids', []));

        if ($ids === []) {
            return;
        }

        foreach ($product->images()->whereIn('id', $ids)->get() as $image) {
            if ($image->path) {
                Storage::disk($image->disk)->delete($image->path);
            }

            $image->delete();
        }

        $product->load('images');
    }

    /** @return array<int, int> فهرس الملف المرفوع ← معرّف الصورة */
    protected function syncUploadedImages(Request $request, Product $product): array
    {
        if (! $request->hasFile('images')) {
            return [];
        }

        $max = self::MAX_IMAGES[$product->type->value] ?? 6;
        $existing = $product->images()->count();
        $position = $product->images()->max('position') ?? -1;
        $created = [];

        foreach ($request->file('images') as $index => $file) {
            if ($existing >= $max) {
                break;
            }

            $path = $file->store("brands/{$product->brand_id}/products", 'public');

            $created[$index] = $product->images()->create([
                'disk' => 'public',
                'path' => $path,
                'position' => ++$position,
                'is_reference' => false,
            ])->id;

            $existing++;
        }

        return $created;
    }

    /**
     * المرجع البصري: الصورة التي تُمرَّر لنموذج توليد الصور.
     * يصل كمفتاح نصي لأن المستخدم قد يختار صورة لم تُحفظ بعد.
     *
     * @param  array<string, int>  $byUrl
     * @param  array<int, int>  $byIndex
     */
    protected function applyReferenceImage(Request $request, Product $product, array $byUrl, array $byIndex): void
    {
        $images = $product->images()->orderBy('position')->get();

        if ($images->isEmpty()) {
            return;
        }

        [$kind, $value] = array_pad(explode(':', (string) $request->input('reference', ''), 2), 2, null);

        $chosen = match ($kind) {
            'existing' => (int) $value,
            'new' => $byIndex[(int) $value] ?? null,
            'url' => $byUrl[$value] ?? null,
            default => null,
        };

        // اختيار غير صالح أو صورة حُذفت للتو: نرجع لأول صورة بدل ترك المنتج بلا مرجع
        $target = $images->firstWhere('id', $chosen) ?? $images->first();

        /*
         * تحديثان على مستوى الاستعلام لا على النموذج.
         * لو صفّرنا الكل ثم نادينا $target->update(true) لما صدر استعلام أصلاً:
         * النموذج مُحمَّل بقيمة true من قبل التصفير، فيراها Eloquent غير متغيّرة
         * ويتخطاها — فيخرج المنتج بلا مرجع بصري عند أي حفظ لا يغيّر الاختيار.
         */
        $product->images()->whereKeyNot($target->id)->update(['is_reference' => false]);
        $product->images()->whereKey($target->id)->update(['is_reference' => true]);
    }

    /**
     * صور مستوردة برابطها: لا ملف محلياً، والعرض والتوليد يقرآن original_url.
     *
     * @return array<string, int> الرابط ← معرّف الصورة
     */
    protected function syncRemoteImages(Request $request, Product $product): array
    {
        $urls = array_filter((array) $request->input('image_urls', []));

        if ($urls === []) {
            return [];
        }

        $existing = $product->images()->pluck('original_url', 'id')->filter();
        $position = $product->images()->max('position') ?? -1;
        $max = self::MAX_IMAGES[$product->type->value] ?? 6;
        $created = [];

        foreach (array_slice($urls, 0, $max) as $url) {
            // الرابط الموجود مسبقاً يُعاد مفتاحه حتى يصلح مرجعاً دون أن يتكرر
            if ($id = $existing->search($url)) {
                $created[$url] = (int) $id;

                continue;
            }

            if ($product->images()->count() >= $max) {
                continue;
            }

            $created[$url] = $product->images()->create([
                'disk' => 'public',
                'path' => '',
                'original_url' => $url,
                'position' => ++$position,
                'is_reference' => false,
            ])->id;
        }

        return $created;
    }

    /** الملفات لا تُحذف مع السجل تلقائياً، فنتركها تتراكم إن لم ننظفها هنا. */
    protected function deleteImageFiles(Product $product): void
    {
        foreach ($product->images as $image) {
            if ($image->path) {
                Storage::disk($image->disk)->delete($image->path);
            }
        }
    }
}
