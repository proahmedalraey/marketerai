{{-- اختيار النموذج فعلي (يُرسَل مع الطلب) — يظهر فقط حين مزود الصور OpenRouter. --}}
<div
    data-popover="model"
    x-show="popover === 'model'"
    x-cloak
    x-transition:enter="transition ease-out duration-150"
    x-transition:enter-start="opacity-0 translate-y-1"
    x-transition:enter-end="opacity-100 translate-y-0"
    x-transition:leave="transition ease-in duration-100"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    class="absolute bottom-full start-0 mb-2 z-20 w-72 max-w-[90vw] p-1.5 studio-popover"
>
    <template x-for="(m, key) in models" :key="key">
        <button
            type="button" @click="selectModel(key)"
            :class="model === key ? 'bg-muted' : 'hover:bg-muted'"
            class="flex items-center gap-2 w-full rounded-lg p-2.5 text-start transition"
        >
            <span class="flex-1 min-w-0">
                <span class="block text-sm font-semibold text-fg" x-text="m.label"></span>
                <span class="block text-[11px] text-fg-subtle" x-text="m.hint"></span>
            </span>
            <span class="chip-neutral shrink-0 text-[10px] tnum" x-show="capsLabel(key)" x-text="capsLabel(key)"></span>
            <x-icon name="check" class="w-4 h-4 text-brand-600 dark:text-brand-400 shrink-0" x-show="model === key" />
        </button>
    </template>
</div>
