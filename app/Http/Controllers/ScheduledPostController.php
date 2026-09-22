<?php

namespace App\Http\Controllers;

use App\Enums\ContentStatus;
use App\Enums\PostStatus;
use App\Models\Brand;
use App\Models\ContentItem;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * جدولة منشور على التقويم («الخطة الشهرية»): تذكير داخلي فقط، بلا نشر فعلي
 * على Instagram/Facebook/TikTok — لا اتصال حقيقي بحسابات هذه المنصات بعد.
 */
class ScheduledPostController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'source' => ['required', Rule::in(['existing', 'new'])],
            'content_item_id' => ['required_if:source,existing', 'nullable', 'integer'],
            'caption' => ['required_if:source,new', 'nullable', 'string', 'max:4000'],
            'platforms' => ['required', 'array', 'min:1'],
            'platforms.*' => [Rule::in(array_keys(config('content.platforms')))],
            'overrides' => ['nullable', 'array'],
            'overrides.*' => ['nullable', 'string', 'max:4000'],
            'mode' => ['required', Rule::in(['draft', 'now', 'schedule'])],
            'scheduled_date' => ['required_if:mode,schedule', 'nullable', 'date', 'after_or_equal:today'],
            'scheduled_time' => ['nullable', 'date_format:H:i'],
            'privacy' => ['nullable', Rule::in(['public', 'private'])],
        ], [], [
            'content_item_id' => 'المحتوى المختار',
            'caption' => 'النص',
            'platforms' => 'المنصات',
            'scheduled_date' => 'تاريخ الجدولة',
        ]);

        $brand = $this->brand();

        $contentItem = $data['source'] === 'existing'
            ? ContentItem::findOrFail($data['content_item_id'])
            : $this->createAdHocItem($brand, $data);

        DB::transaction(function () use ($contentItem, $data) {
            // إعادة جدولة تستبدل الجدول السابق بدل تكديسه — الزر «جدولة» يُقرأ كضبط الجدول لا إضافة إليه
            $contentItem->scheduledPosts()->delete();

            if ($data['mode'] === 'draft') {
                return;
            }

            $scheduledAt = $data['mode'] === 'now'
                ? CarbonImmutable::now()
                : CarbonImmutable::parse($data['scheduled_date'].' '.($data['scheduled_time'] ?? '09:00'));

            $contentItem->update([
                'in_plan' => true,
                'planned_for' => $scheduledAt->toDateString(),
                'status' => ContentStatus::Scheduled,
            ]);

            foreach ($data['platforms'] as $platform) {
                $contentItem->scheduledPosts()->create([
                    'platform' => $platform,
                    'caption_override' => $data['overrides'][$platform] ?? null,
                    'mode' => 'reminder',
                    'privacy' => $data['privacy'] ?? 'public',
                    'scheduled_at' => $scheduledAt,
                    'status' => PostStatus::Queued,
                ]);
            }
        });

        return redirect()
            ->route('content.plan', array_filter([
                'month' => CarbonImmutable::parse($contentItem->planned_for ?? now())->format('Y-m'),
                'date' => $contentItem->planned_for?->toDateString(),
            ]))
            ->with('status', $data['mode'] === 'draft' ? 'حُفظ كمسودة.' : 'أُضيف إلى الخطة الشهرية.');
    }

    protected function createAdHocItem(Brand $brand, array $data): ContentItem
    {
        $platform = $data['platforms'][0];

        return ContentItem::create([
            'brand_id' => $brand->id,
            'goal' => array_key_first(config('content.goals')),
            'platform' => $platform,
            'format' => 'post',
            'language' => 'ar',
            'body' => ['caption' => $data['caption']],
            'caption' => $data['caption'],
            'status' => ContentStatus::Draft,
            'in_plan' => false,
        ]);
    }

    public function bulkDestroy(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $items = ContentItem::inPlan()->whereIn('id', $data['ids'])->get();

        if ($items->isEmpty()) {
            return back()->withErrors(['ids' => 'لم يُحدَّد أي عنصر.']);
        }

        $count = $items->count();
        $items->each->delete();

        return back()->with('status', "حُذف {$count} عنصراً.");
    }

    public function export(Request $request)
    {
        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'range' => ['nullable', Rule::in(['month', 'all'])],
        ]);

        $query = ContentItem::inPlan()->with('scheduledPosts')->orderBy('planned_for');

        if (($data['range'] ?? 'month') === 'month') {
            $month = CarbonImmutable::createFromFormat('Y-m', $data['month'] ?? now()->format('Y-m'))->startOfMonth();
            $query->whereBetween('planned_for', [$month->toDateString(), $month->endOfMonth()->toDateString()]);
        }

        $items = $query->get();
        $filename = 'خطة-المحتوى-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($items) {
            $out = fopen('php://output', 'w');
            // BOM حتى يقرأ Excel النص العربي بلا تشويه (لا حزمة Excel مضافة بعد؛ CSV يفتح فيه مباشرة)
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['التاريخ', 'المنصات', 'الحالة', 'المحتوى']);

            foreach ($items as $item) {
                $platforms = $item->scheduledPosts->isNotEmpty()
                    ? $item->scheduledPosts->pluck('platform')->map(fn ($p) => config("content.platforms.{$p}.label", $p))->implode('، ')
                    : $item->platformLabel();

                fputcsv($out, [
                    optional($item->planned_for)->toDateString(),
                    $platforms,
                    $item->status->label(),
                    Str::limit($item->previewText(), 200),
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
