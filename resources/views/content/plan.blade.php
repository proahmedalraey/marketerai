@extends('layouts.app')
@section('title', 'الخطة الشهرية')
@section('subtitle', 'ما أضفته من «كتابة المحتوى» بتاريخ نشره — منه تنطلق الصور والجدولة')

@section('actions')
    {{-- الرأس يُرسم خارج نطاق x-data الخاص بالصفحة، فنتخاطب معه بحدث لا بمتغير --}}
    <a href="{{ route('content.plan.export', ['month' => $month->format('Y-m')]) }}" class="btn-secondary btn-sm">
        <x-icon name="download" class="w-4 h-4" />
        <span class="hidden sm:inline">تصدير Excel</span>
        <span class="sm:hidden sr-only">تصدير Excel</span>
    </a>

    <button type="button" @click="$dispatch('open-schedule')" class="btn-primary btn-sm">
        <x-icon name="plus" class="w-4 h-4" />
        <span class="hidden sm:inline">منشور جديد</span>
        <span class="sm:hidden sr-only">منشور جديد</span>
    </button>
@endsection

@section('content')

<div
    x-data="contentCalendar({
        platforms: @js(collect($platforms)->map(fn ($p) => ['label' => $p['label'], 'icon' => $p['icon'], 'caption_max' => $p['caption_max']])),
        drafts: @js($drafts->map(fn ($d) => ['id' => $d->id, 'label' => \Illuminate\Support\Str::limit($d->caption ?: $d->previewText(), 60) ?: 'محتوى بلا نص', 'platform' => $d->platform, 'caption' => $d->caption])),
        defaultDate: @js($selectedDate ?? now()->toDateString()),
        pageIds: @js($dayItems->pluck('id')->all()),
    })"
    @open-schedule.window="openSchedule()"
    @keydown.escape.window="scheduleOpen = false; mediaOpen = false; confirmingDelete = false"
>

    <div class="grid lg:grid-cols-[22rem_minmax(0,1fr)] gap-5 items-start">

        {{-- ================= العمود الأيسر: بحث + قائمة اليوم أو تفاصيل عنصر ================= --}}
        <div class="space-y-4">
            @if ($viewingItem)
                @include('content.partials.item-details', ['item' => $viewingItem, 'platforms' => $platforms, 'month' => $month, 'selectedDate' => $selectedDate])
            @else
                <form method="GET" role="search" data-no-busy>
                    <input type="hidden" name="month" value="{{ $month->format('Y-m') }}">
                    @if ($selectedDate)
                        <input type="hidden" name="date" value="{{ $selectedDate }}">
                    @endif

                    <div class="relative">
                        <span class="absolute inset-y-0 start-0 grid place-items-center w-11 text-fg-subtle pointer-events-none">
                            <x-icon name="search" class="w-[18px] h-[18px]" />
                        </span>
                        <label for="plan-search" class="sr-only">ابحث في محتوياتك</label>
                        <input
                            id="plan-search" name="q" type="search" value="{{ $search }}"
                            class="field ps-11 border-transparent bg-muted shadow-none focus:bg-card"
                            placeholder="ابحث في محتوياتك..."
                        >
                    </div>
                </form>

                @include('content.partials.day-list', [
                    'items' => $dayItems, 'selectedDate' => $selectedDate, 'search' => $search, 'platforms' => $platforms,
                    'month' => $month,
                ])

                @include('content.partials.bulk-bar')
            @endif
        </div>

        {{-- ================= الشبكة الشهرية ================= --}}
        @include('content.partials.calendar-grid', [
            'month' => $month, 'weeks' => $weeks, 'selectedDate' => $selectedDate, 'monthTotal' => $monthTotal, 'platforms' => $platforms,
        ])
    </div>

    @include('content.partials.schedule-modal', ['platforms' => $platforms])
    @include('content.partials.media-picker-modal', ['mediaGallery' => $mediaGallery])
</div>
@endsection
