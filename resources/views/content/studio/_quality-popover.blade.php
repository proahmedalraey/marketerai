<div
    x-show="popover === 'quality'"
    x-cloak
    x-transition.opacity.duration.150ms
    @click.outside="popover = null"
    class="absolute bottom-full start-0 mb-2 z-20 w-80 max-w-[90vw] p-3.5 card shadow-pop motion-safe:animate-scale-in"
>
    <p class="label mb-2">الدقة</p>
    <div class="grid grid-cols-3 gap-1.5 mb-3.5">
        <template x-for="(res, key) in resolutions" :key="key">
            <button
                type="button" @click="resolution = key"
                :class="resolution === key ? 'border-brand-500 bg-brand-50 dark:bg-brand-950 text-brand-700 dark:text-brand-300' : 'border-line text-fg hover:bg-muted'"
                class="rounded-lg border py-2 text-center transition"
            >
                <span class="block text-sm font-bold tnum" x-text="res.label"></span>
                <span class="block text-[10px] text-fg-subtle mt-0.5" x-text="res.hint"></span>
            </button>
        </template>
    </div>

    <p class="label mb-2">الجودة</p>
    <div class="space-y-1">
        <template x-for="(lvl, key) in qualityLevels" :key="key">
            <button
                type="button" @click="quality = key"
                :class="quality === key ? 'bg-muted' : 'hover:bg-muted'"
                class="flex items-center gap-2 w-full rounded-lg p-2 text-start transition"
            >
                <span class="flex-1 min-w-0">
                    <span class="block text-sm font-medium text-fg" x-text="lvl.label"></span>
                    <span class="block text-[11px] text-fg-subtle leading-snug" x-text="lvl.hint"></span>
                </span>
                <span class="chip-neutral shrink-0 tnum" x-text="costFor(key) + ' نقطة'"></span>
            </button>
        </template>
    </div>

    <p class="text-[11px] text-fg-subtle leading-relaxed mt-3 pt-3 border-t border-line">
        المستويات الأعلى تضيف تفاصيل أدق وتستغرق وقتاً أطول.
    </p>
</div>
