<?php

namespace App\Http\Controllers;

use App\Models\GenerationJob;
use App\Models\Product;
use App\Services\Credits\DailyCapReachedException;
use App\Services\Credits\InsufficientCreditsException;
use App\Services\Credits\CreditService;
use App\Services\Import\DTO\DiscoveryResult;
use App\Services\Import\ImportService;
use App\Services\Import\ProductEnricher;
use App\Services\Import\StoreCrawler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ProductImportController extends Controller
{
    /** مسح متجر كامل. مجاني: المستخدم يراجع قبل أن يدفع. */
    public function scan(Request $request, ImportService $imports)
    {
        $data = $request->validate(
            ['store_url' => ['required', 'string', 'max:255']],
            [],
            ['store_url' => 'رابط المتجر'],
        );

        $job = $imports->scan($this->brand(), $data['store_url'], $request->user()->id);

        return redirect()->route('products.index', ['import' => $job->uuid]);
    }

    /** قراءة دفعة إضافية من الروابط المكتشفة. */
    public function more(Request $request, GenerationJob $job, ImportService $imports)
    {
        abort_unless($job->brand_id === $this->brand()->id, 404);

        $limit = (int) $request->input('limit', StoreCrawler::READ_BATCH);

        $next = $imports->readMore($job, max(1, min($limit, StoreCrawler::CANDIDATE_CAP)));

        return redirect()->route('products.index', ['import' => $next->uuid]);
    }

    /** استيراد المحدد: هنا فقط تُخصم النقاط. */
    public function store(Request $request, ImportService $imports)
    {
        $data = $request->validate([
            'job' => ['required', 'string'],
            'keys' => ['required', 'array', 'min:1', 'max:'.StoreCrawler::CANDIDATE_CAP],
            'keys.*' => ['string'],
        ], [], ['keys' => 'المنتجات المحددة']);

        $scan = GenerationJob::where('uuid', $data['job'])->firstOrFail();

        $result = DiscoveryResult::fromArray($scan->result ?? []);

        // نعيد القراءة من نتيجة المسح لا من الطلب: لا نثق ببيانات يرسلها المتصفح
        $selected = collect($result->items)
            ->filter(fn ($item) => in_array($item->key(), $data['keys'], true))
            ->map(fn ($item) => $item->toArray())
            ->values()
            ->all();

        if ($selected === []) {
            return back()->withErrors(['keys' => 'لم نجد المنتجات المحددة في نتيجة المسح. أعد المسح وحاول مجدداً.']);
        }

        try {
            $job = $imports->import($this->brand(), $selected, $result->platform, $request->user()->id);
        } catch (InsufficientCreditsException|DailyCapReachedException $e) {
            return back()->withErrors(['credits' => $e->getMessage()]);
        }

        return redirect()->route('products.index', ['importing' => $job->uuid]);
    }

    /**
     * استيراد رابط واحد لملء النموذج المفتوح.
     *
     * متزامن لا في طابور: المستخدم واقف أمام النموذج ينتظر الحقول،
     * وتحويله إلى استطلاع مهمة يضاعف التعقيد مقابل ثانيتين.
     */
    public function single(
        Request $request,
        StoreCrawler $crawler,
        ProductEnricher $enricher,
        CreditService $credits,
    ): JsonResponse {
        $data = $request->validate(['url' => ['required', 'string', 'max:2000']]);

        try {
            $discovered = $crawler->read($data['url']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (! $discovered) {
            return response()->json([
                'message' => 'تعذّرت قراءة هذه الصفحة. تأكد أنها صفحة منتج عامة لا تتطلب تسجيل دخول.',
            ], 422);
        }

        $brand = $this->brand();

        try {
            $held = $credits->hold($brand, ProductEnricher::OPERATION);
        } catch (InsufficientCreditsException|DailyCapReachedException $e) {
            return response()->json(['message' => $e->getMessage()], 402);
        }

        // منتج مؤقت غير محفوظ: الإثراء يحتاج الشكل لا السجل
        $draft = new Product([
            'brand_id' => $brand->id,
            'type' => 'good',
            'title' => $discovered->title,
            'summary' => $discovered->summary,
            'price' => $discovered->price,
            'currency' => strtoupper($discovered->currency ?: 'SAR'),
            'category' => $discovered->category,
            'sku' => $discovered->sku,
            'brand_name' => $discovered->brandName,
        ]);

        try {
            $fields = $enricher->fieldsFor($draft);
        } catch (\Throwable $e) {
            $credits->refund($brand, $held, null, ProductEnricher::OPERATION, 'فشل استيراد رابط واحد');

            return response()->json(['message' => 'تعذّر تحليل المنتج. لم تُخصم أي نقاط.'], 502);
        }

        $credits->settle($brand, $held, $held, null, ProductEnricher::OPERATION);

        return response()->json([
            'title' => $discovered->title,
            'features' => $fields['features'] ?? $discovered->summary,
            'specifications' => $fields['specifications'] ?? '',
            'audience' => $fields['audience'] ?? $brand->audience,
            'price' => $discovered->price !== null ? (string) $discovered->price : '',
            'currency' => strtoupper($discovered->currency ?: 'SAR'),
            'image_urls' => $discovered->imageUrls,
            'brand_name' => $discovered->brandName ?? '',
            // حقائق البيع من الصفحة نفسها، يراجعها التاجر قبل الحفظ
            ...collect($discovered->salesFacts())
                ->map(fn ($value) => is_array($value) ? $value : (string) $value)
                ->all(),
            'credits_charged' => $held,
        ]);
    }

    /**
     * إعادة توليد الوصف المختصر من محتوى النموذج المفتوح.
     * منفصل عن الحفظ حتى يجرّب المستخدم صياغات قبل أن يلتزم بواحدة.
     */
    public function summary(Request $request, ProductEnricher $enricher, CreditService $credits): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'features' => ['nullable', 'string', 'max:5000'],
            'specifications' => ['nullable', 'string', 'max:5000'],
            'audience' => ['nullable', 'string', 'max:1000'],
            'price' => ['nullable', 'numeric'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $brand = $this->brand();

        try {
            $held = $credits->hold($brand, ProductEnricher::OPERATION);
        } catch (InsufficientCreditsException|DailyCapReachedException $e) {
            return response()->json(['message' => $e->getMessage()], 402);
        }

        $draft = new Product($data + ['brand_id' => $brand->id, 'type' => 'good']);

        try {
            $summary = $enricher->summaryFrom($draft);
        } catch (\Throwable) {
            $credits->refund($brand, $held, null, ProductEnricher::OPERATION, 'فشل توليد الوصف المختصر');

            return response()->json(['message' => 'تعذّر توليد الوصف. لم تُخصم أي نقاط.'], 502);
        }

        if (blank($summary)) {
            $credits->refund($brand, $held, null, ProductEnricher::OPERATION, 'وصف فارغ');

            return response()->json(['message' => 'عاد الوصف فارغاً. لم تُخصم أي نقاط.'], 502);
        }

        $credits->settle($brand, $held, $held, null, ProductEnricher::OPERATION);

        return response()->json(['summary' => $summary, 'credits_charged' => $held]);
    }
}
