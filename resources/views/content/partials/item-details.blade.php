@php
    $itemPlatforms = $item->scheduledPosts->isNotEmpty()
        ? $item->scheduledPosts->pluck('platform')->unique()
        : collect([$item->platform]);
@endphp

<div class="card p-4">
    <a
        href="{{ route('content.plan', array_filter(['month' => $month->format('Y-m'), 'date' => $selectedDate])) }}"
        class="inline-flex items-center gap-1.5 text-xs font-medium text-fg-muted hover:text-fg mb-4"
    >
        <x-icon name="chevron-right" class="w-3.5 h-3.5" />
        العودة للقائمة
    </a>

    <h3 class="text-sm font-bold text-fg mb-3">تفاصيل المحتوى</h3>

    <div class="space-y-3.5 text-sm">
        <div>
            <span class="label">المنصات</span>
            <div class="flex flex-wrap gap-1.5">
                @foreach ($itemPlatforms as $p)
                    <span class="chip-neutral">
                        <x-icon :name="$platforms[$p]['icon'] ?? 'globe'" class="w-3.5 h-3.5" />
                        {{ $platforms[$p]['label'] ?? $p }}
                    </span>
                @endforeach
            </div>
        </div>

        @if ($item->planned_for)
            <div>
                <span class="label">تاريخ النشر</span>
                <p class="text-fg tnum">{{ $item->planned_for->locale('ar')->translatedFormat('j F Y') }}</p>
            </div>
        @endif

        <div>
            <span class="label">العنوان</span>
            <p class="text-fg">{{ \Illuminate\Support\Str::limit($item->previewText(), 80) ?: 'بلا عنوان' }}</p>
        </div>

        <div>
            <span class="label">المحتوى</span>

            @if ($item->format->value === 'carousel' && $item->slides())
                <ol class="space-y-1.5">
                    @foreach ($item->slides() as $index => $slide)
                        <li class="rounded-lg bg-muted px-3 py-2">
                            <span class="block text-[11px] font-semibold text-brand-700 dark:text-brand-400 mb-0.5">
                                شريحة {{ $index + 1 }} · {{ config("content.slide_roles.{$slide['role']}.label", $slide['role']) }}
                            </span>
                            <span class="block text-sm text-fg-muted leading-relaxed">{{ $slide['text'] }}</span>
                        </li>
                    @endforeach
                </ol>
            @else
                <p class="text-sm text-fg-muted leading-relaxed">{{ $item->previewText() ?: 'بلا نص' }}</p>
            @endif
        </div>
    </div>

    <div class="mt-4 pt-3 border-t border-line space-y-2">
        <a href="{{ route('content.show', $item) }}" class="btn-secondary btn-sm w-full">
            <x-icon name="sparkles" class="w-4 h-4" />
            <span>تحسين بالذكاء الاصطناعي</span>
        </a>

        <div class="flex items-center gap-2">
            <a href="{{ route('content.show', $item) }}" class="btn btn-dark btn-sm flex-1">
                <x-icon name="pencil" class="w-4 h-4" />
                <span>تعديل</span>
            </a>

            <x-confirm
                :action="route('content.destroy', $item)"
                label="حذف هذا المحتوى"
                title="حذف هذا المحتوى؟"
                message="سيُحذف النص وشرائحه. الصور المولّدة تبقى في الاستوديو."
                :icon-only="false"
                class="flex-1 justify-center"
            />
        </div>
    </div>
</div>
