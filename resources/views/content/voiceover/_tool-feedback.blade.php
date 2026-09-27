{{-- نتيجة أدوات النص: تراجع عن آخر تعديل آلي، أو سبب تعذّره --}}
<div x-show="undo || (toolError && toolErrorFor !== 'direction') || listening" x-cloak class="flex flex-wrap items-center gap-2 text-xs">
    <span x-show="listening" class="inline-flex items-center gap-1.5 text-danger-fg">
        <span class="w-2 h-2 rounded-full bg-danger animate-pulse-dot"></span>
        يستمع… تحدّث وسيُضاف كلامك للنص
    </span>

    <template x-if="undo">
        <span class="inline-flex items-center gap-2 text-success-fg">
            <x-icon name="check-circle" class="w-4 h-4" />
            <span x-text="undo.tool === 'enhance' ? 'جُهّز للإلقاء بلا تغيير في كلماتك.' : 'شُكّل النص.'"></span>
            <button type="button" @click="undoTool()" class="inline-flex items-center gap-1 font-semibold text-fg-muted hover:text-fg underline-offset-4 hover:underline">
                <x-icon name="undo" class="w-3.5 h-3.5" />
                تراجع
            </button>
        </span>
    </template>

    <p x-show="toolError && toolErrorFor !== 'direction'" class="error-text mt-0" role="alert">
        <x-icon name="alert-circle" class="w-4 h-4 shrink-0" />
        <span x-text="toolError"></span>
    </p>
</div>
