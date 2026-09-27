{{--
    تبويب «من خطة المحتوى»: محتوى الخطة الشهرية لشهر واحد، ولكل كاروسيل شرائحه كاملة
    وزر «توليد صور الكاروسيل». التوليد يعيد لتبويب «الاستوديو» حيث يظهر التقدم والصور.

    الغلاف أولاً ثم البقية بأسلوبه (قرار البند 4.3 في خطة التدقيق): لا يدفع التاجر ثمن
    ست صور قبل أن يرى أسلوبها — فالزر يتبدّل حسب ما وُلّد: غلاف ← أكمل الشرائح ← جاهز.
--}}
@php
    $prevMonth = $planMonth->subMonth()->format('Y-m');
    $nextMonth = $planMonth->addMonth()->format('Y-m');
    $costLabel = fn (float $credits) => fmod($credits, 1.0) === 0.0 ? (int) $credits : $credits;
@endphp

<div class="flex items-center justify-between gap-3 mb-4">
    {{-- RTL: السابق على اليمين --}}
    <a href="{{ route('studio.index', ['tab' => 'plan', 'plan_month' => $prevMonth]) }}"
       class="grid place-items-center w-10 h-10 rounded-xl border border-line bg-card text-fg-muted hover:text-fg hover:border-brand-300 transition"
       title="الشهر السابق">
        <x-icon name="chevron-right" class="w-4 h-4" />
        <span class="sr-only">الشهر السابق</span>
    </a>

    <div class="text-center">
        <h2 class="text-sm font-bold text-fg">{{ $planMonth->translatedFormat('F') }} <span class="tnum">{{ $planMonth->year }}</span></h2>
        <a href="{{ route('content.plan', ['month' => $planMonth->format('Y-m')]) }}"
           class="text-[11px] text-fg-subtle hover:text-brand-700 dark:hover:text-brand-400 hover:underline underline-offset-4">
            الخطة كاملة
        </a>
    </div>

    <a href="{{ route('studio.index', ['tab' => 'plan', 'plan_month' => $nextMonth]) }}"
       class="grid place-items-center w-10 h-10 rounded-xl border border-line bg-card text-fg-muted hover:text-fg hover:border-brand-300 transition"
       title="الشهر التالي">
        <x-icon name="chevron-left" class="w-4 h-4" />
        <span class="sr-only">الشهر التالي</span>
    </a>
</div>

@if ($planItems->isEmpty())
    <x-empty-state
        icon="calendar"
        :title="'لا محتوى في خطة '.$planMonth->translatedFormat('F').' بعد'"
        description="أضف محتوى للخطة الشهرية من «كتابة المحتوى»، أو تنقّل بين الأشهر بالأسهم."
    />
@else
    <div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-4 items-start">
        @foreach ($planItems as $item)
            @php
                $slides = $item->slides();
                $isCarousel = $item->format->value === 'carousel' && $slides !== [];
                $images = $isCarousel ? $item->slideImages() : [];
                $cover = $images[0] ?? null;
                $missing = $isCarousel ? count(array_diff(array_keys($slides), array_keys($images))) : 0;
            @endphp

            <article class="card p-4 flex flex-col gap-3">
                <header class="flex items-center gap-1.5 flex-wrap">
                    @if ($kind = $item->kindLabel())
                        <span class="chip-neutral">{{ $kind }}</span>
                    @endif
                    <span class="chip-info">
                        <x-icon :name="$item->format->icon()" class="w-3.5 h-3.5" />
                        {{ $item->format->label() }}
                    </span>
                    <span class="ms-auto text-xs text-fg-subtle">
                        {{ $item->platformLabel() }}
                        @if ($item->planned_for)
                            · <span class="tnum">{{ $item->planned_for->translatedFormat('j F') }}</span>
                        @endif
                    </span>
                </header>

                @if ($isCarousel)
                    <ol class="space-y-2">
                        @foreach ($slides as $index => $slide)
                            <li class="flex items-start gap-2.5 rounded-xl bg-muted/80 px-3 py-2.5">
                                <p class="flex-1 min-w-0 text-[13px] leading-relaxed text-fg line-clamp-2">
                                    <span class="text-fg-muted">الشريحة {{ $index + 1 }}:</span>
                                    {{ $slide['text'] ?? '' }}
                                </p>
                                @if ($image = $images[$index] ?? null)
                                    <img src="{{ $image->thumbUrl() }}" alt="صورة الشريحة {{ $index + 1 }}" loading="lazy" decoding="async"
                                         class="w-10 h-10 rounded-lg object-cover shrink-0 border border-line">
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @else
                    <p class="rounded-xl bg-muted/80 px-3 py-2.5 text-[13px] leading-relaxed text-fg line-clamp-4">
                        {{ Str::limit($item->previewText(), 280) }}
                    </p>
                @endif

                <footer class="flex items-center gap-2 mt-auto pt-1">
                    <span class="flex-1 min-w-0 truncate text-xs text-fg-subtle">{{ $item->product?->title }}</span>

                    @if ($isCarousel)
                        <button type="button" @click="openCarouselEditor({{ $item->id }}, 0)" class="btn btn-ghost btn-sm btn-icon" title="تعديل الكاروسيل">
                            <x-icon name="layers" class="w-3.5 h-3.5" />
                            <span class="sr-only">تعديل الكاروسيل</span>
                        </button>
                    @endif

                    <a href="{{ route('content.show', $item) }}" class="btn btn-ghost btn-sm btn-icon" title="فتح المحتوى">
                        <x-icon name="pencil" class="w-3.5 h-3.5" />
                        <span class="sr-only">فتح المحتوى</span>
                    </a>

                    @if ($isCarousel)
                        @if (! $cover)
                            <form method="POST" action="{{ route('studio.carousel', $item) }}">
                                @csrf
                                <input type="hidden" name="stage" value="cover">
                                <input type="hidden" name="return" value="studio">
                                <button type="submit" class="btn btn-sm studio-plan-action"
                                        title="يبدأ بالغلاف ({{ $costLabel($slideImageCost) }} نقطة) لترى أسلوبه، ثم تكمل بقية الشرائح بأسلوبه">
                                    <x-icon name="image" class="w-3.5 h-3.5" />
                                    <span>توليد صور الكاروسيل</span>
                                </button>
                            </form>
                        @elseif ($missing)
                            <form method="POST" action="{{ route('studio.carousel', $item) }}">
                                @csrf
                                <input type="hidden" name="stage" value="cover">
                                <input type="hidden" name="return" value="studio">
                                <button type="submit" class="btn btn-ghost btn-sm btn-icon" title="غلاف آخر · {{ $costLabel($slideImageCost) }} نقطة">
                                    <x-icon name="refresh" class="w-3.5 h-3.5" />
                                    <span class="sr-only">غلاف آخر</span>
                                </button>
                            </form>

                            <form method="POST" action="{{ route('studio.carousel', $item) }}">
                                @csrf
                                <input type="hidden" name="stage" value="rest">
                                <input type="hidden" name="return" value="studio">
                                <button type="submit" class="btn btn-sm studio-plan-action"
                                        title="بقية الشرائح بأسلوب الغلاف · {{ $costLabel($missing * $slideImageCost) }} نقطة">
                                    <x-icon name="check" class="w-3.5 h-3.5" />
                                    <span>أكمل {{ $missing }} {{ $missing === 1 ? 'شريحة' : 'شرائح' }}</span>
                                </button>
                            </form>
                        @else
                            <span class="chip-success">
                                <x-icon name="check-circle" class="w-3.5 h-3.5" />
                                الصور جاهزة
                            </span>
                        @endif
                    @endif
                </footer>
            </article>
        @endforeach
    </div>
@endif
