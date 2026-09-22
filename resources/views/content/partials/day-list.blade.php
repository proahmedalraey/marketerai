@php
    $dateLabel = $selectedDate ? \Carbon\CarbonImmutable::parse($selectedDate)->locale('ar')->translatedFormat('j F') : null;
@endphp

<div class="card p-4">
    @if ($dateLabel)
        <h3 class="text-sm font-bold text-fg mb-1">منشورات يوم {{ $dateLabel }}</h3>
        <p class="text-xs text-fg-subtle mb-3 tnum">{{ $items->count() }} {{ $items->count() === 1 ? 'منشور' : 'منشورات' }}</p>
    @elseif ($search)
        <h3 class="text-sm font-bold text-fg mb-1">نتائج البحث عن «{{ $search }}»</h3>
        <p class="text-xs text-fg-subtle mb-3 tnum">{{ $items->count() }} نتيجة</p>
    @endif

    @if ($items->isNotEmpty())
        <label class="flex items-center gap-2 text-xs font-medium text-fg-muted mb-2.5 cursor-pointer">
            <input type="checkbox" class="w-4 h-4 rounded border-line-strong bg-card text-brand-600 focus:ring-brand-500" :checked="allSelected" @change="toggleAll()">
            تحديد الكل
        </label>

        <ul class="space-y-2">
            @foreach ($items as $item)
                @php
                    $itemPlatforms = $item->scheduledPosts->isNotEmpty()
                        ? $item->scheduledPosts->pluck('platform')->unique()
                        : collect([$item->platform]);
                @endphp
                <li class="rounded-xl border border-line p-3 flex items-start gap-2.5">
                    <input type="checkbox" class="w-4 h-4 mt-1 shrink-0 rounded border-line-strong bg-card text-brand-600 focus:ring-brand-500" value="{{ $item->id }}" x-model="selected">

                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-1.5 flex-wrap mb-1">
                            @foreach ($itemPlatforms as $p)
                                <x-icon :name="$platforms[$p]['icon'] ?? 'globe'" class="w-3.5 h-3.5 text-fg-subtle" />
                            @endforeach
                            <span class="{{ $item->status->chipClasses() }}">{{ $item->status->label() }}</span>

                            @if ($item->needsReview())
                                <span class="chip-danger">
                                    <x-icon name="alert-circle" class="w-3.5 h-3.5" />
                                    راجع قبل النشر
                                </span>
                            @endif

                            @if ($item->offersEndingBeforePublish()->isNotEmpty())
                                <span class="chip-warning">
                                    <x-icon name="calendar" class="w-3.5 h-3.5" />
                                    العرض ينتهي قبل موعد النشر
                                </span>
                            @endif
                        </div>

                        <p class="text-sm text-fg truncate">{{ $item->previewText() ?: 'بلا نص' }}</p>
                        <p class="text-[11px] text-fg-subtle mt-0.5">{{ $item->format->label() }} · {{ $item->platformLabel() }}</p>
                    </div>

                    <a
                        href="{{ route('content.plan', array_filter(['month' => $month->format('Y-m'), 'date' => $selectedDate, 'item' => $item->id])) }}"
                        class="btn btn-ghost btn-sm btn-icon shrink-0"
                    >
                        <x-icon name="pencil" class="w-4 h-4" />
                        <span class="sr-only">تفاصيل المحتوى</span>
                    </a>
                </li>
            @endforeach
        </ul>
    @else
        <x-empty-state icon="calendar" title="اختر يوم من التقويم" description="لعرض محتوياته المجدولة" />
    @endif
</div>
