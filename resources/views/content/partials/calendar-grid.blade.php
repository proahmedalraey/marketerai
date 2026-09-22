@php
    $todayStr = now()->toDateString();
    $weekDays = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
@endphp

<div class="card p-4">
    <div class="flex items-center justify-between mb-4">
        <a href="{{ route('content.plan', ['month' => $month->subMonthNoOverflow()->format('Y-m')]) }}" class="btn btn-ghost btn-sm btn-icon">
            <x-icon name="chevron-right" class="w-4 h-4" />
            <span class="sr-only">الشهر السابق</span>
        </a>

        <h2 class="text-sm font-bold text-fg">{{ $month->locale('ar')->translatedFormat('F Y') }}</h2>

        <a href="{{ route('content.plan', ['month' => $month->addMonthNoOverflow()->format('Y-m')]) }}" class="btn btn-ghost btn-sm btn-icon">
            <x-icon name="chevron-left" class="w-4 h-4" />
            <span class="sr-only">الشهر التالي</span>
        </a>
    </div>

    <div class="grid grid-cols-7 gap-1.5 text-center mb-2">
        @foreach ($weekDays as $day)
            <span class="text-[11px] font-semibold text-fg-subtle py-1">{{ $day }}</span>
        @endforeach
    </div>

    <div class="grid grid-cols-7 gap-1.5">
        @foreach ($weeks as $week)
            @foreach ($week as $cell)
                @if (! $cell['inMonth'])
                    <div></div>
                @else
                    @php
                        $dateStr = $cell['date']->toDateString();
                        $isSelected = $selectedDate === $dateStr;
                        $isToday = ! $isSelected && $todayStr === $dateStr;
                    @endphp
                    <a
                        href="{{ route('content.plan', array_filter(['month' => $month->format('Y-m'), 'date' => $dateStr])) }}"
                        class="aspect-square rounded-xl border flex flex-col items-center justify-center gap-1 text-sm font-semibold transition
                            {{ $isSelected ? 'bg-fg text-fg-inverse border-fg' : ($isToday ? 'border-fg text-fg' : 'border-line text-fg hover:border-line-strong hover:bg-muted') }}"
                    >
                        <span class="tnum">{{ $cell['date']->day }}</span>

                        @if ($cell['platforms']->isNotEmpty())
                            <span class="flex items-center gap-0.5">
                                @foreach ($cell['platforms']->take(3) as $platform)
                                    <x-icon :name="$platforms[$platform]['icon'] ?? 'globe'" class="w-3 h-3 {{ $isSelected ? 'text-fg-inverse' : 'text-fg-subtle' }}" />
                                @endforeach
                            </span>
                        @endif
                    </a>
                @endif
            @endforeach
        @endforeach
    </div>

    <div class="mt-4 pt-3 border-t border-line flex items-center justify-end">
        <span class="text-xs text-fg-subtle">
            <span class="font-bold text-fg tnum">{{ $monthTotal }}</span> إجمالي المنشورات هذا الشهر
        </span>
    </div>
</div>
