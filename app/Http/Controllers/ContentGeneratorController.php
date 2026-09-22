<?php

namespace App\Http\Controllers;

use App\Enums\ContentStatus;
use App\Models\ContentItem;
use App\Models\GenerationJob;
use App\Models\Product;
use App\Services\Content\ContentFormats;
use App\Services\Content\ContentGenerationService;
use App\Services\Credits\DailyCapReachedException;
use App\Services\Credits\InsufficientCreditsException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * «كتابة المحتوى»: الهدف ← المنتج ← المنصة ← الشكل ← اللغة واللهجة، بمثال واجهة المنافس.
 *
 * ما يُكتب هنا مسودة تبقى في الصفحة حتى يضيفها التاجر للخطة الشهرية بتاريخ،
 * أو يحذفها، أو يعيد المحاولة بالإعدادات نفسها.
 */
class ContentGeneratorController extends Controller
{
    /** أقصى عدد إعدادات في دفعة واحدة. */
    public const MAX_BATCH = 10;

    /** المسودات المعروضة في «محتوى جاهز للنشر»، الأحدث أولاً. */
    public const DRAFTS_SHOWN = 30;

    public function index(Request $request)
    {
        $drafts = ContentItem::drafts()->with('product')->latest()->limit(self::DRAFTS_SHOWN)->get();

        return view('content.generator', [
            'products' => Product::where('is_active', true)->orderByDesc('is_primary')->orderBy('title')->get(['id', 'title', 'is_primary']),
            'goals' => collect(config('content.goals'))->map(fn ($goal) => $goal['label']),
            'platforms' => config('content.platforms'),
            'formats' => ContentFormats::forBrowser(),
            'languages' => config('content.languages'),
            'dialects' => collect(config('dialects'))->map(fn ($dialect) => $dialect['label']),
            'drafts' => $drafts,
            'suggestedDates' => $this->suggestedDates($drafts->count()),
            'jobs' => $this->trackedJobs($request),
            // «عرض النتائج» من لوحة «إنتاجاتي» يفتح المسودة التي تخص تلك العملية
            'focusIndex' => max(0, $drafts->search(fn ($draft) => $draft->id === (int) $request->query('focus'))),
        ]);
    }

    public function store(Request $request, ContentGenerationService $service)
    {
        $data = $request->validate([
            'mode' => ['nullable', 'in:single,batch'],
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH],
            'items.*.goal' => ['required', 'string', Rule::in(array_keys(config('content.goals')))],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.platform' => ['required', 'string', Rule::in(array_keys(config('content.platforms')))],
            'items.*.format' => ['required', 'string', Rule::in(array_keys(config('content.formats')))],
            'items.*.option' => ['nullable', 'string', 'max:16'],
            'items.*.language' => ['required', 'string', Rule::in(array_keys(config('content.languages')))],
            'items.*.dialect' => ['nullable', 'string', Rule::in(array_keys(config('dialects')))],
            'items.*.filming' => ['nullable', 'boolean'],
        ], [
            'items.required' => 'اختر إعدادات المحتوى أولاً.',
            'items.max' => 'الدفعة تتسع لـ '.self::MAX_BATCH.' إعدادات على الأكثر.',
        ], [
            'items.*.goal' => 'الهدف من المحتوى',
            'items.*.platform' => 'المنصة',
            'items.*.format' => 'شكل المحتوى',
            'items.*.language' => 'اللغة',
            'items.*.dialect' => 'اللهجة',
        ]);

        $items = $this->checkedItems($data['items']);

        try {
            $jobs = $service->dispatchBatch($this->brand(), $items, $request->user()->id);
        } catch (InsufficientCreditsException|DailyCapReachedException $e) {
            return back()->withErrors(['credits' => $e->getMessage()]);
        }

        $count = count($jobs);

        return redirect()
            ->route('content.generator', ['jobs' => collect($jobs)->pluck('uuid')->implode(',')])
            ->with('status', $count === 1
                ? 'بدأت الكتابة. تظهر النتيجة هنا خلال ثوانٍ.'
                : "بدأت كتابة {$count} محتويات. تظهر النتائج هنا حين تجهز، وتتابعها من «إنتاجاتي» في أي صفحة.")
            // الدفعة أُرسلت: تُفرَّغ من المتصفح. فشل التحقق لا يصل هنا فتبقى كما هي
            ->with('batch_sent', ($data['mode'] ?? 'single') === 'batch');
    }

    /** «إعادة المحاولة»: الإعدادات نفسها في محتوى جديد، والسابق باقٍ. */
    public function retry(Request $request, ContentItem $contentItem, ContentGenerationService $service)
    {
        if (! ContentFormats::get($contentItem->variant)) {
            return back()->withErrors(['credits' => 'هذا المحتوى كُتب قبل صفحة «كتابة المحتوى»؛ أنشئه من جديد بإعداداتك.']);
        }

        try {
            $job = $service->retry($this->brand(), $contentItem, $request->user()->id);
        } catch (InsufficientCreditsException|DailyCapReachedException $e) {
            return back()->withErrors(['credits' => $e->getMessage()]);
        }

        return redirect()
            ->route('content.generator', ['jobs' => $job->uuid])
            ->with('status', 'نكتب نسخة جديدة بالإعدادات نفسها. النسخة السابقة باقية حتى تحذفها.');
    }

    public function addToPlan(Request $request, ContentItem $contentItem)
    {
        $data = $request->validate(
            ['planned_for' => ['required', 'date', 'after_or_equal:today']],
            ['planned_for.after_or_equal' => 'اختر تاريخ نشر من اليوم فصاعداً.'],
            ['planned_for' => 'تاريخ النشر'],
        );

        $contentItem->update([
            'in_plan' => true,
            'planned_for' => $data['planned_for'],
            'status' => ContentStatus::Ready,
        ]);

        $date = $contentItem->planned_for->locale('ar')->translatedFormat('j F');

        return redirect()->route('content.generator')
            ->with('status', "أُضيف إلى الخطة الشهرية بتاريخ {$date}.");
    }

    /**
     * ما لا تقوله قواعد التحقق وحدها: الشكل متاح على المنصة، والحقل التابع
     * من اختياراته، واللهجة مع العربية، والمنتج من منتجات هذه العلامة.
     *
     * @return array<int, array>
     */
    protected function checkedItems(array $items): array
    {
        $errors = [];
        $productIds = Product::whereIn('id', collect($items)->pluck('product_id')->filter())->pluck('id')->all();

        $checked = collect($items)->values()->map(function (array $item, int $i) use (&$errors, $productIds) {
            $format = $item['format'];
            $prefix = "items.{$i}";

            if (! ContentFormats::allowedOn($item['platform'], $format)) {
                $errors["{$prefix}.format"] = 'شكل المحتوى «'.ContentFormats::label($format).'» غير متاح على '.config("content.platforms.{$item['platform']}.label").'.';
            }

            $option = isset($item['option']) ? (string) $item['option'] : null;

            if (ContentFormats::optionKey($format) && ! ContentFormats::choice($format, $option)) {
                $errors["{$prefix}.option"] = 'اختر '.ContentFormats::optionLabel($format).'.';
            }

            if ($item['language'] === 'ar' && blank($item['dialect'] ?? null)) {
                $errors["{$prefix}.dialect"] = 'اختر اللهجة.';
            }

            if (filled($item['product_id'] ?? null) && ! in_array((int) $item['product_id'], $productIds, true)) {
                $errors["{$prefix}.product_id"] = 'المنتج المختار غير موجود.';
            }

            return [
                'goal' => $item['goal'],
                'platform' => $item['platform'],
                'format' => $format,
                'option' => ContentFormats::optionKey($format) ? $option : null,
                'product_id' => filled($item['product_id'] ?? null) ? (int) $item['product_id'] : null,
                'language' => $item['language'],
                'dialect' => $item['language'] === 'ar' ? ($item['dialect'] ?? null) : null,
                // الخيار لا يصل لشكل لا يدعمه، بدل أن يُطلب ثم يُتجاهل
                'filming' => ContentFormats::supportsFilming($format) && filter_var($item['filming'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        })->all();

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $checked;
    }

    /** مهام الكتابة الجارية التي أُرسلت من هذه الصفحة (?jobs=…)، لمتابعتها. */
    protected function trackedJobs(Request $request): Collection
    {
        $uuids = collect(explode(',', (string) $request->query('jobs')))
            ->map(fn ($uuid) => trim($uuid))
            ->filter(fn ($uuid) => preg_match('/^[0-9a-f-]{36}$/i', $uuid))
            ->take(self::MAX_BATCH);

        return $uuids->isEmpty() ? collect()
            : GenerationJob::whereIn('uuid', $uuids)->where('type', 'content')->get();
    }

    /**
     * تاريخ نشر مقترح لكل مسودة: أول الأيام القادمة التي لا منشور لها في الخطة،
     * يوم لكل مسودة بترتيبها.
     *
     * @return array<int, string>
     */
    protected function suggestedDates(int $count): array
    {
        $tomorrow = CarbonImmutable::today()->addDay();

        $taken = ContentItem::inPlan()
            ->whereDate('planned_for', '>=', $tomorrow)
            ->pluck('planned_for')
            ->map(fn ($date) => $date->toDateString())
            ->flip();

        $dates = [];

        for ($day = $tomorrow; count($dates) < $count && $day->lte($tomorrow->addDays(90)); $day = $day->addDay()) {
            if (! isset($taken[$day->toDateString()])) {
                $dates[] = $day->toDateString();
            }
        }

        return array_pad($dates, $count, $tomorrow->toDateString());
    }
}
