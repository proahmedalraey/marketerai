{{--
    شريط التأليف العائم — يطابق تخطيط الصور المرجعية: برومبت + صف أدوات
    (رفع/صوت/تحسين/هوية بصرية/ممحاة/عدد/جودة+دقة/نموذج/نسبة) + زر توليد بتكلفة حية.

    النموذج والصوت وتحسين البرومبت شكلية (قرار §1/§2) — معطّلة بوسم "قريباً".
    الدقة+الجودة والنسبة وحقول المرجع حقيقية وتُرسَل فعلياً مع النموذج.
--}}
<div class="sticky bottom-4 z-30">
    <form method="POST" action="{{ route('studio.generate') }}" class="card shadow-pop p-3 space-y-2.5">
        @csrf

        <input type="hidden" name="aspect_ratio" :value="aspectRatio">
        <input type="hidden" name="quality" :value="qualityKey">
        <input type="hidden" name="count" :value="count">
        @if ($modelsRoutable)
            <input type="hidden" name="model" :value="model">
        @endif
        <input type="hidden" name="use_brand_identity" :value="useBrandIdentity ? 1 : 0">
        <input type="hidden" name="product_id" :value="referenceMode === 'product' ? productId : ''">
        <input type="hidden" name="reference_asset_id" :value="referenceMode === 'gallery' ? referenceAssetId : ''">

        {{-- تنبيه ضبط تلقائي بعد تبديل النموذج --}}
        <p x-show="notice" x-cloak x-transition class="flex items-center gap-1.5 px-2 py-1.5 rounded-lg bg-warning-soft text-warning-fg text-xs" role="status">
            <x-icon name="info" class="w-3.5 h-3.5 shrink-0" />
            <span x-text="notice"></span>
        </p>

        {{-- شريحة المرجع المختار --}}
        <div x-show="referenceMode" x-cloak class="flex items-center gap-2 px-1">
            <span class="relative shrink-0 w-9 h-9 rounded-lg overflow-hidden bg-muted grid place-items-center">
                <img x-show="referenceAssetUrl" :src="referenceAssetUrl" class="w-full h-full object-cover" alt="">
                <x-icon x-show="!referenceAssetUrl" name="package" class="w-4 h-4 text-fg-subtle" />
            </span>
            <span class="text-xs text-fg-muted flex-1 min-w-0 truncate">
                <span x-show="referenceMode === 'product'">منتج مرجعي مختار</span>
                <span x-show="referenceMode === 'gallery'">صورة من المعرض كمرجع</span>
                <span x-show="referenceMode === 'upload'">صورة مرفوعة كمرجع</span>
            </span>
            <button type="button" @click="clearReference()" class="btn btn-ghost btn-sm btn-icon">
                <x-icon name="close" class="w-3.5 h-3.5" />
                <span class="sr-only">إزالة المرجع</span>
            </button>
        </div>

        {{-- صف البرومبت --}}
        <div class="flex items-end gap-2">
            <div class="relative shrink-0">
                <button
                    type="button" @click="togglePopover('upload')"
                    :aria-expanded="popover === 'upload' ? 'true' : 'false'"
                    class="btn btn-ghost btn-icon" title="إضافة صورة مرجعية"
                >
                    <x-icon name="image" class="w-4 h-4" />
                    <span class="sr-only">إضافة صورة مرجعية</span>
                </button>

                @include('content.studio._upload-popover')
            </div>

            <textarea
                id="studio-prompt" name="prompt" x-model="prompt"
                rows="1" required maxlength="1500"
                class="field flex-1 resize-none py-2.5"
                placeholder="صف المشهد الذي تتخيّله… أو اسحب صورة هنا كمرجع"
            ></textarea>

            <button type="button" disabled title="قريباً — إدخال صوتي" class="btn btn-ghost btn-icon opacity-50 cursor-not-allowed">
                <x-icon name="mic" class="w-4 h-4" />
                <span class="sr-only">إدخال صوتي (قريباً)</span>
            </button>

            <button type="button" disabled title="قريباً — تحسين البرومبت بالذكاء" class="btn btn-ghost btn-icon opacity-50 cursor-not-allowed">
                <x-icon name="wand" class="w-4 h-4" />
                <span class="sr-only">تحسين البرومبت (قريباً)</span>
            </button>
        </div>

        {{-- صف الأدوات --}}
        <div class="flex flex-wrap items-center gap-1.5">
            <button
                type="button" @click="useBrandIdentity = ! useBrandIdentity"
                :class="useBrandIdentity ? 'bg-brand-50 dark:bg-brand-950 text-brand-700 dark:text-brand-400 border-brand-300' : 'text-fg-muted border-line hover:bg-muted'"
                class="chip border min-h-9 transition" title="حقن ألوان علامتك ونمطك البصري في البرومبت"
            >
                <x-icon name="palette" class="w-3.5 h-3.5" />
                تفعيل الهوية البصرية
            </button>

            <button type="button" @click="clearPrompt()" class="btn btn-ghost btn-icon" title="تفريغ البرومبت">
                <x-icon name="eraser" class="w-4 h-4" />
                <span class="sr-only">تفريغ البرومبت</span>
            </button>

            {{-- عداد العدد --}}
            <div class="flex items-center gap-1 chip border border-line px-1">
                <button type="button" @click="count = Math.max(1, count - 1)" class="btn btn-ghost btn-icon !w-7 !h-7 !min-h-0">
                    <x-icon name="minus" class="w-3.5 h-3.5" />
                    <span class="sr-only">إنقاص العدد</span>
                </button>
                <span class="w-4 text-center text-sm font-semibold tnum" x-text="count"></span>
                <button type="button" @click="count = Math.min(4, count + 1)" class="btn btn-ghost btn-icon !w-7 !h-7 !min-h-0">
                    <x-icon name="plus" class="w-3.5 h-3.5" />
                    <span class="sr-only">زيادة العدد</span>
                </button>
            </div>

            {{-- الدقة + الجودة --}}
            <div class="relative">
                <button
                    type="button" @click="togglePopover('quality')"
                    :aria-expanded="popover === 'quality' ? 'true' : 'false'"
                    aria-label="الدقة والجودة"
                    class="chip border border-line text-fg min-h-9 hover:bg-muted transition"
                >
                    <x-icon name="sliders" class="w-3.5 h-3.5" />
                    <span x-text="qualityLevels[quality]?.label"></span>
                    ·
                    <span class="tnum" x-text="resolutions[resolution]?.label"></span>
                    <x-icon name="chevron-down" class="w-3 h-3 text-fg-subtle" />
                </button>

                @include('content.studio._quality-popover')
            </div>

            {{-- النموذج: فعّال حين مزود الصور OpenRouter، وإلا عرض للقراءة للنموذج المُعدّ --}}
            <div class="relative">
                @if ($modelsRoutable)
                    <button
                        type="button" @click="togglePopover('model')"
                        :aria-expanded="popover === 'model' ? 'true' : 'false'"
                        aria-label="النموذج"
                        class="chip border border-line text-fg min-h-9 hover:bg-muted transition"
                    >
                        <span x-text="models[model]?.label"></span>
                        <x-icon name="settings" class="w-3.5 h-3.5 text-fg-subtle" />
                    </button>

                    @include('content.studio._model-popover')
                @else
                    <span
                        class="chip border border-line text-fg-muted min-h-9"
                        title="النموذج المُعدّ في إعدادات المنصة. التبديل بين النماذج متاح حين يكون مزود الصور OpenRouter."
                    >
                        <x-icon name="settings" class="w-3.5 h-3.5 text-fg-subtle" />
                        {{ $activeModel }}
                    </span>
                @endif
            </div>

            {{-- نسبة الأبعاد --}}
            <div class="relative">
                <button
                    type="button" @click="togglePopover('ratio')"
                    :aria-expanded="popover === 'ratio' ? 'true' : 'false'"
                    aria-label="نسبة الأبعاد"
                    class="chip border border-line text-fg min-h-9 hover:bg-muted transition"
                >
                    <span class="block w-3 h-3 border-2 border-current rounded-[2px]" aria-hidden="true"></span>
                    <span class="tnum" x-text="autoRatio ? 'تلقائي' : aspectRatio"></span>
                    <x-icon name="chevron-down" class="w-3 h-3 text-fg-subtle" />
                </button>

                @include('content.studio._ratio-popover')
            </div>

            {{-- زر التوليد --}}
            <button type="submit" class="btn-primary btn-lg ms-auto">
                <x-icon name="sparkles" class="w-4 h-4" />
                توليد
                <span class="tnum" x-text="totalCostLabel"></span>
                نقطة
            </button>
        </div>
    </form>
</div>
