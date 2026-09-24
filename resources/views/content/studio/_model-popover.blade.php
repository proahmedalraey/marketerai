{{--
    اختيار النموذج شكلي حالياً (قرار §2): التوليد الفعلي يستخدم دوماً المزوّد
    النشط في الإعدادات بصرف النظر عن الاختيار هنا. التوجيه الفعلي موثّق في
    docs/image-studio-redesign-plan.md.
--}}
<div
    x-show="popover === 'model'"
    x-cloak
    x-transition.opacity.duration.150ms
    @click.outside="popover = null"
    class="absolute bottom-full start-0 mb-2 z-20 w-72 max-w-[90vw] p-1.5 card shadow-pop motion-safe:animate-scale-in"
>
    <template x-for="(m, key) in models" :key="key">
        <button
            type="button" @click="model = key; popover = null"
            :class="model === key ? 'bg-muted' : 'hover:bg-muted'"
            class="flex items-center gap-2 w-full rounded-lg p-2.5 text-start transition"
        >
            <span class="flex-1 min-w-0">
                <span class="block text-sm font-semibold text-fg" x-text="m.label"></span>
                <span class="block text-[11px] text-fg-subtle" x-text="m.hint"></span>
            </span>
            <x-icon name="check" class="w-4 h-4 text-brand-600 dark:text-brand-400 shrink-0" x-show="model === key" />
        </button>
    </template>
</div>
