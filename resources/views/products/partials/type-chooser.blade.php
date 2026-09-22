{{--
    خطوة اختيار النوع قبل النموذج.
    السلعة والخدمة تطلبان حقولاً مختلفة جوهرياً، فسؤال واحد مقدماً
    أرخص من نموذج يخفي نصف حقوله ويُظهر نصفها بعد اختيار في منتصفه.
--}}
<div
    x-show="chooser"
    x-cloak
    class="fixed inset-0 z-[60] grid place-items-center p-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="chooser-title"
>
    <div
        x-show="chooser"
        x-transition.opacity.duration.150ms
        @click="chooser = false"
        class="absolute inset-0 bg-scrim/50 backdrop-blur-sm"
        aria-hidden="true"
    ></div>

    <div
        x-show="chooser"
        x-transition:enter="motion-safe:animate-scale-in"
        x-init="$watch('chooser', open => open && $nextTick(() => $refs.firstChoice?.focus()))"
        class="relative w-full max-w-md card shadow-pop p-6"
    >
        <div class="flex items-start justify-between gap-4 mb-1">
            <h2 id="chooser-title" class="text-base font-bold text-fg">ماذا تريد إضافته؟</h2>

            <button type="button" @click="chooser = false" class="btn btn-ghost btn-sm btn-icon -m-1.5">
                <x-icon name="close" class="w-5 h-5" />
                <span class="sr-only">إغلاق</span>
            </button>
        </div>

        <p class="text-sm text-fg-muted leading-relaxed">اختر نوع العنصر الذي تريد إضافته إلى قائمتك.</p>

        <div class="grid grid-cols-2 gap-3 mt-5">
            <button
                type="button"
                x-ref="firstChoice"
                @click="startCreate('good')"
                class="flex flex-col items-center gap-2.5 p-5 rounded-xl border border-line bg-card text-center
                       transition hover:border-brand-400 hover:bg-brand-50/40 dark:hover:bg-brand-500/5"
            >
                <span class="grid place-items-center w-11 h-11 rounded-xl bg-muted text-fg-muted">
                    <x-icon name="package" class="w-5 h-5" />
                </span>
                <span class="text-sm font-semibold text-fg">سلعة</span>
                <span class="text-xs text-fg-subtle leading-relaxed">عنصر مادي تبيعه</span>
            </button>

            <button
                type="button"
                @click="startCreate('service')"
                class="flex flex-col items-center gap-2.5 p-5 rounded-xl border border-line bg-card text-center
                       transition hover:border-brand-400 hover:bg-brand-50/40 dark:hover:bg-brand-500/5"
            >
                <span class="grid place-items-center w-11 h-11 rounded-xl bg-muted text-fg-muted">
                    <x-icon name="zap" class="w-5 h-5" />
                </span>
                <span class="text-sm font-semibold text-fg">خدمة</span>
                <span class="text-xs text-fg-subtle leading-relaxed">خدمة تقدّمها للعملاء</span>
            </button>
        </div>
    </div>
</div>
