<?php

namespace App\Http\Controllers;

use App\Models\ContentItem;
use App\Models\MediaAsset;
use App\Services\Brand\ClaimConflicts;
use App\Services\Content\ContentGenerationService;
use App\Services\Content\ContentSchema;
use App\Services\Credits\DailyCapReachedException;
use App\Services\Credits\InsufficientCreditsException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ContentPlanController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:100'],
            'item' => ['nullable', 'integer'],
        ]);

        $month = CarbonImmutable::createFromFormat('Y-m', $request->query('month', now()->format('Y-m')))
            ->startOfMonth();

        $monthStart = $month->startOfMonth();
        $monthEnd = $month->endOfMonth();

        // منشور واحد قد يمتد لعدة منصات عبر ScheduledPost؛ العناصر القديمة (قبل هذه الميزة)
        // بلا صفوف جدولة تُعرض بمنصتها الوحيدة، فلا يفقد التقويم شيئاً أُضيف بالطريقة السابقة
        $monthItems = ContentItem::inPlan()
            ->whereBetween('planned_for', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->with('scheduledPosts')
            ->get();

        $byDay = $monthItems->groupBy(fn (ContentItem $item) => $item->planned_for->toDateString());

        $viewingItem = $request->filled('item')
            ? ContentItem::inPlan()->with(['product', 'mediaAssets', 'scheduledPosts'])->find($request->integer('item'))
            : null;

        $selectedDate = $request->query('date');
        $search = $request->query('q');

        $dayItems = collect();

        if (! $viewingItem && ($selectedDate || $search)) {
            $dayItems = ContentItem::inPlan()
                ->with(['product', 'mediaAssets', 'scheduledPosts'])
                ->when($selectedDate, fn ($q) => $q->whereDate('planned_for', $selectedDate))
                ->when(! $selectedDate, fn ($q) => $q->whereBetween('planned_for', [$monthStart->toDateString(), $monthEnd->toDateString()]))
                ->when($search, fn ($q) => $q->where('caption', 'like', '%'.$search.'%'))
                ->latest()
                ->get();
        }

        return view('content.plan', [
            'month' => $month,
            'weeks' => $this->calendarWeeks($month, $byDay),
            'selectedDate' => $selectedDate,
            'search' => $search,
            'dayItems' => $dayItems,
            'viewingItem' => $viewingItem,
            'platforms' => config('content.platforms'),
            'monthTotal' => $monthItems->count(),
            'drafts' => ContentItem::drafts()->latest()->limit(30)->get(['id', 'caption', 'platform', 'format']),
            'mediaGallery' => MediaAsset::where('kind', 'image')->latest()->take(24)->get(),
        ]);
    }

    /**
     * أسابيع الشهر: الأحد أول يوم (كما في التقويم السعودي)، مع أيام من الشهر
     * المجاور لإتمام الأسبوع — تُعرض فارغة بلا رقم أو منصّات.
     *
     * @return array<int, array<int, array{date: CarbonImmutable, inMonth: bool, platforms: Collection, count: int}>>
     */
    protected function calendarWeeks(CarbonImmutable $month, Collection $byDay): array
    {
        $cursor = $month->startOfMonth()->startOfWeek(CarbonImmutable::SUNDAY);
        $end = $month->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY);

        $weeks = [];

        while ($cursor->lte($end)) {
            $week = [];

            for ($i = 0; $i < 7; $i++) {
                $items = $byDay->get($cursor->toDateString(), collect());

                $week[] = [
                    'date' => $cursor,
                    'inMonth' => $cursor->month === $month->month,
                    'platforms' => $items->flatMap(fn (ContentItem $item) => $item->scheduledPosts->isNotEmpty()
                        ? $item->scheduledPosts->pluck('platform')
                        : collect([$item->platform]))->unique()->values(),
                    'count' => $items->count(),
                ];

                $cursor = $cursor->addDay();
            }

            $weeks[] = $week;
        }

        return $weeks;
    }

    public function show(ContentItem $contentItem)
    {
        $contentItem->load(['product', 'mediaAssets']);

        return view('content.show', ['item' => $contentItem]);
    }

    public function update(Request $request, ContentItem $contentItem, ContentGenerationService $service)
    {
        $data = $request->validate([
            'caption' => ['nullable', 'string', 'max:4000'],
            'status' => ['nullable', 'in:draft,ready,scheduled,published,archived'],
            'planned_for' => ['nullable', 'date'],
            'slides' => ['nullable', 'array', 'max:12'],
            // نص وحده (الشكل القديم) أو صف كامل: الأصل، الدور، النص، طبقات الهوك
            'slides.*' => ['nullable'],
            'slides.*.text' => ['nullable', 'string', 'max:600'],
            'slides.*.role' => ['nullable', 'in:hook,promise,pull,harvest,ask'],
            'slides.*.kicker' => ['nullable', 'string', 'max:160'],
            'slides.*.focal' => ['nullable', 'string', 'max:160'],
            'slides.*.tail' => ['nullable', 'string', 'max:300'],
            'tweets' => ['nullable', 'array', 'max:12'],
            'tweets.*' => ['nullable', 'string', 'max:600'],
        ], [], ['slides' => 'الشرائح', 'tweets' => 'التغريدات']);

        $body = $contentItem->body;
        $moves = null;

        if (isset($data['slides'])) {
            [$body['slides'], $moves] = $this->rebuildSlides($contentItem->slides(), $data['slides']);

            if ($contentItem->format->value === 'carousel' && count($body['slides']) < 3) {
                return back()->withInput()->withErrors(['slides' => 'الكاروسيل ثلاث شرائح على الأقل.']);
            }
        }

        if (isset($data['tweets'])) {
            $body['tweets'] = array_values(array_filter(array_map('trim', array_map('strval', $data['tweets']))));

            if ($contentItem->format->value === 'thread' && $body['tweets'] === []) {
                return back()->withInput()->withErrors(['tweets' => 'الثريد تغريدة واحدة على الأقل.']);
            }
        }

        if (isset($data['caption'])) {
            $body['caption'] = $data['caption'];
        }

        DB::transaction(function () use ($contentItem, $data, $body, $moves) {
            $contentItem->update([
                'body' => $body,
                'caption' => $data['caption'] ?? $contentItem->caption,
                'status' => $data['status'] ?? $contentItem->status,
                'planned_for' => $data['planned_for'] ?? $contentItem->planned_for,
                // تاريخ نشر لمسودة = قرار بإضافتها للخطة
                'in_plan' => $contentItem->in_plan || filled($data['planned_for'] ?? null),
            ]);

            // الصورة تتبع شريحتها إلى موضعها الجديد؛ صورة شريحة محذوفة تُفصل وتبقى في الاستوديو
            if ($moves !== null) {
                foreach ($contentItem->mediaAssets()->whereNotNull('slide_index')->get() as $asset) {
                    $target = $moves[$asset->slide_index] ?? null;

                    if ($target !== $asset->slide_index) {
                        $asset->update(['slide_index' => $target]);
                    }
                }
            }
        });

        // التاجر صحّح النص بيده: التنبيه يتبع النص الحالي لا نص التوليد
        $contentItem->update(['quality' => $service->recheck($contentItem)]);

        return back()->with('status', $contentItem->needsReview()
            ? 'حُفظت التعديلات، وما زالت فيها نقاط تحتاج مراجعة.'
            : 'حُفظت التعديلات.');
    }

    /**
     * إعادة كتابة شريحة واحدة أو تحسينها بتوجيه (البند 4.4): نقطة واحدة.
     */
    public function rewriteSlide(Request $request, ContentItem $contentItem, int $index, ContentGenerationService $service, ClaimConflicts $conflicts)
    {
        abort_unless(isset($contentItem->slides()[$index]), 404);

        $data = $request->validate([
            'direction' => ['nullable', Rule::in(array_keys(config('content.slide_directions', [])))],
            'note' => ['nullable', 'string', 'max:200'],
        ], [], ['direction' => 'التوجيه', 'note' => 'ملاحظتك']);

        // ملاحظة لن تُنفَّذ تُرفض قبل الخصم، كتعليمات المنشور
        if ($problems = $conflicts->inStatement((string) ($data['note'] ?? ''))) {
            return back()->withErrors(['note' => $problems]);
        }

        try {
            $job = $service->dispatchSlideRewrite(
                $this->brand(), $contentItem, $index, $data['direction'] ?? null, $data['note'] ?? null, $request->user()->id,
            );
        } catch (InsufficientCreditsException|DailyCapReachedException $e) {
            return back()->withErrors(['credits' => $e->getMessage()]);
        }

        return redirect()->route('content.show', [$contentItem, 'job' => $job->uuid]);
    }

    /**
     * يبني الشرائح بترتيبها الجديد من صفوف النموذج.
     *
     * كل صف يحمل أصله (موضعه قبل التعديل)، فتتبعه صورته وتوجيهه البصري.
     * الصف الفارغ يُحذف. المرتجع: الشرائح، وخريطة «الموضع القديم ← الجديد».
     *
     * @return array{0: array<int, array>, 1: array<int, int>}
     */
    protected function rebuildSlides(array $old, array $submitted): array
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
                'visual' => $base['visual'] ?? null,
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

    public function destroy(ContentItem $contentItem)
    {
        $contentItem->delete();

        // المسودة تُحذف من صفحة الكتابة، فالعودة إليها
        return redirect()->route($contentItem->in_plan ? 'content.plan' : 'content.generator')
            ->with('status', 'حُذف المحتوى.');
    }
}
