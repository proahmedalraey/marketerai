{{--
    "تلقائي" ليست نسبة أبعاد فعلية — مجرد مفتاح واجهة يرسل 1:1 (تبسيط موثّق
    في docs/image-studio-redesign-plan.md). باقي النسب الـ13 حقيقية بالكامل.
--}}
<div
    data-popover="ratio"
    x-show="popover === 'ratio'"
    x-cloak
    x-transition:enter="transition ease-out duration-150"
    x-transition:enter-start="opacity-0 translate-y-1"
    x-transition:enter-end="opacity-100 translate-y-0"
    x-transition:leave="transition ease-in duration-100"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    class="absolute bottom-full start-0 mb-2 z-20 w-72 max-w-[90vw] max-h-[70vh] overflow-y-auto p-3.5 studio-popover"
>
    <p class="label mb-2">تلقائي ومربع</p>
    <div class="grid grid-cols-2 gap-1.5 mb-3.5">
        <button
            type="button" @click="setAutoRatio()"
            :class="autoRatio ? 'border-brand-500 bg-brand-50 dark:bg-brand-950' : 'border-line hover:bg-muted'"
            class="flex flex-col items-center gap-1.5 rounded-lg border p-2.5"
        >
            <span class="block w-6 h-6 border-2 border-dashed border-current rounded-[3px] text-fg-subtle" aria-hidden="true"></span>
            <span class="text-[11px] font-medium text-fg">تلقائي</span>
        </button>

        <button
            type="button" @click="setRatio('1:1')"
            :class="! autoRatio && aspectRatio === '1:1' ? 'border-brand-500 bg-brand-50 dark:bg-brand-950' : 'border-line hover:bg-muted'"
            class="flex flex-col items-center gap-1.5 rounded-lg border p-2.5"
        >
            <span class="block w-6 h-6 border-2 border-current rounded-[3px] text-fg-subtle" aria-hidden="true"></span>
            <span class="text-[11px] font-medium text-fg tnum">1:1</span>
        </button>
    </div>

    @foreach (['horizontal' => 'أفقي', 'vertical' => 'عمودي'] as $group => $groupLabel)
        @if (! empty($ratioGroups[$group]))
            <p class="label mb-2">{{ $groupLabel }}</p>
            <div class="grid grid-cols-3 gap-1.5 mb-3.5">
                @foreach ($ratioGroups[$group] as $key)
                    @php $r = $ratios[$key]; @endphp
                    <button
                        type="button" @click="setRatio(@js($key))"
                        :class="! autoRatio && aspectRatio === @js($key) ? 'border-brand-500 bg-brand-50 dark:bg-brand-950' : 'border-line hover:bg-muted'"
                        class="flex flex-col items-center gap-1.5 rounded-lg border p-2.5"
                    >
                        <span class="grid place-items-center h-6" aria-hidden="true">
                            <span
                                class="block border-2 border-current rounded-[2px] text-fg-subtle"
                                style="width: {{ $group === 'horizontal' ? 22 : round(22 * $r['w'] / $r['h']) }}px; height: {{ $group === 'horizontal' ? round(22 * $r['h'] / $r['w']) : 22 }}px"
                            ></span>
                        </span>
                        <span class="text-[11px] font-medium text-fg tnum">{{ $key }}</span>
                    </button>
                @endforeach
            </div>
        @endif
    @endforeach
</div>
