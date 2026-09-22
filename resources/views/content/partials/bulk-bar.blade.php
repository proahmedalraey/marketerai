{{--
    شريط التحديد الجماعي، على نمط products/partials/bulk-bar.blade.php:
    حركة CSS ثابتة لا x-transition حتى لا يتأخر الظهور عند التحديد السريع.
--}}
<form
    method="POST"
    action="{{ route('content.bulk-delete') }}"
    x-show="selected.length > 0"
    x-cloak
    class="fixed inset-x-0 bottom-0 z-40 px-4 pb-safe pt-3 pointer-events-none motion-safe:animate-fade-up"
    data-no-busy
>
    @csrf

    <template x-for="id in selected" :key="id">
        <input type="hidden" name="ids[]" :value="id">
    </template>

    <div
        class="pointer-events-auto mx-auto w-full max-w-3xl card shadow-pop p-2.5 flex flex-wrap items-center gap-2"
        role="region"
        aria-label="إجراءات المحتوى المحدد"
    >
        <div class="flex items-center gap-2.5 px-1.5 min-w-0">
            <span class="grid place-items-center w-8 h-8 shrink-0 rounded-lg bg-brand-600 text-white text-xs font-bold tnum" x-text="selected.length"></span>
            <span class="text-sm font-medium text-fg" aria-live="polite">
                <span x-text="selected.length === 1 ? 'عنصر محدد' : 'عناصر محددة'"></span>
            </span>
        </div>

        <template x-if="confirmingDelete">
            <div class="flex items-center gap-2 ms-auto">
                <span class="text-sm font-medium text-danger-fg">حذف نهائي، بلا تراجع.</span>

                <button type="button" @click="confirmingDelete = false" class="btn-secondary btn-sm">
                    <span>إلغاء</span>
                </button>

                <button type="submit" class="btn btn-sm bg-danger text-white hover:brightness-110">
                    <span x-text="`نعم، احذف ${selected.length}`"></span>
                </button>
            </div>
        </template>

        <template x-if="! confirmingDelete">
            <div class="flex items-center gap-2 ms-auto">
                <button type="button" @click="confirmingDelete = true" class="btn btn-ghost btn-sm text-danger-fg hover:bg-danger-soft">
                    <x-icon name="trash" class="w-4 h-4" />
                    <span class="hidden sm:inline">حذف</span>
                </button>

                <button type="button" @click="clearSelection()" class="btn btn-ghost btn-sm btn-icon">
                    <x-icon name="close" class="w-4 h-4" />
                    <span class="sr-only">إلغاء التحديد</span>
                </button>
            </div>
        </template>
    </div>
</form>
