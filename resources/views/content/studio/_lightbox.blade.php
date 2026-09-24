{{-- عارض الصورة بالحجم الكامل. "استخدم كمرجع" حقيقي؛ المفضّلة شكلية (قرار §4). --}}
<div
    x-show="lightbox"
    x-cloak
    class="fixed inset-0 z-[70] overflow-y-auto"
    role="dialog"
    aria-modal="true"
>
    <div
        x-show="lightbox"
        x-transition.opacity.duration.150ms
        @click="closeLightbox()"
        class="fixed inset-0 bg-scrim/80 backdrop-blur-sm"
        aria-hidden="true"
    ></div>

    <template x-if="lightbox">
        <div class="relative min-h-full grid place-items-center p-4">
            <div class="relative max-w-3xl w-full motion-safe:animate-scale-in">
                <img :src="lightbox.url" :alt="lightbox.prompt" class="w-full max-h-[80vh] object-contain rounded-xl bg-muted">

                <button
                    type="button" @click="closeLightbox()"
                    class="absolute top-2 end-2 grid place-items-center w-9 h-9 rounded-lg bg-scrim/70 text-white backdrop-blur-sm hover:bg-scrim"
                >
                    <x-icon name="close" class="w-4 h-4" />
                    <span class="sr-only">إغلاق</span>
                </button>

                <button
                    type="button" @click="togglePin()"
                    :title="lightbox.pinned ? 'إلغاء التثبيت' : 'تثبيت'"
                    class="absolute top-2 start-2 grid place-items-center w-9 h-9 rounded-lg bg-scrim/70 backdrop-blur-sm hover:bg-scrim transition"
                    :class="lightbox.pinned ? 'text-brand-400' : 'text-white'"
                >
                    <x-icon name="heart" class="w-4 h-4" ::class="lightbox.pinned && 'fill-current'" />
                    <span class="sr-only" x-text="lightbox.pinned ? 'إلغاء التثبيت' : 'تثبيت'"></span>
                </button>

                <div class="absolute inset-x-0 bottom-0 flex items-center gap-2 p-3 bg-gradient-to-t from-black/70 to-transparent rounded-b-xl">
                    <p class="flex-1 min-w-0 truncate text-xs text-white/90" x-text="lightbox.prompt"></p>

                    <button
                        type="button" @click="useAsReference()"
                        class="inline-flex items-center gap-1.5 px-3 min-h-9 rounded-lg bg-white/90 text-gray-900 text-xs font-semibold hover:bg-white shrink-0"
                    >
                        <x-icon name="target" class="w-3.5 h-3.5" />
                        استخدم كمرجع
                    </button>
                </div>
            </div>
        </div>
    </template>
</div>
