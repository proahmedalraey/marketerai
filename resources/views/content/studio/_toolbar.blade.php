{{--
    شريط التأليف العائم — يطابق تخطيط الصور المرجعية: برومبت + صف أدوات
    (رفع/صوت/تحسين/هوية بصرية/ممحاة/عدد/جودة+دقة/نموذج/نسبة) + زر توليد بتكلفة حية.

    كل الأدوات حقيقية: الإدخال الصوتي (Web Speech API داخل المتصفح)، وتحسين الوصف (العصا)،
    والنموذج والدقة+الجودة والنسبة وحقول المرجع.

    القوائم المنبثقة تُغلق بنقرة خارج الشريط كله (click.outside واحد هنا) لا خارج كل قائمة:
    كانت كل قائمة تغلق الأخرى أثناء حركة إخفائها، فتظهر القائمة الجديدة وتختفي فوراً.
--}}
<div class="sticky bottom-4 z-30">
    <form
        method="POST" action="{{ route('studio.generate') }}"
        @click.outside="closePopovers()" @submit="stopVoice()"
        class="studio-dock p-3 space-y-2.5"
    >
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
                id="studio-prompt" name="prompt" x-model="prompt" x-ref="prompt"
                rows="1" required maxlength="1500" :readonly="enhancing"
                :class="enhancing && 'opacity-60'"
                class="field flex-1 resize-none py-2.5"
                placeholder="صف المشهد الذي تتخيّله… أو اسحب صورة هنا كمرجع"
            ></textarea>

            {{-- إدخال صوتي بالعربية: يُكتب في حقل الوصف أثناء الكلام، ونقرة ثانية توقفه --}}
            <button
                type="button" @click="toggleVoice()" :disabled="enhancing"
                :title="voiceTitle" :aria-pressed="listening ? 'true' : 'false'"
                :class="listening ? 'bg-danger-soft text-danger-fg ring-2 ring-danger/30' : (speechSupported ? '' : 'opacity-50')"
                class="btn btn-ghost btn-icon relative"
            >
                <x-icon name="mic" class="w-4 h-4" />
                <span x-show="listening" x-cloak class="absolute top-2 end-2 w-2 h-2 rounded-full bg-danger motion-safe:animate-ping"></span>
                <span class="sr-only" x-text="listening ? 'إيقاف الإدخال الصوتي' : 'إدخال صوتي'">إدخال صوتي</span>
            </button>

            {{-- تحسين الوصف بالذكاء: مهمة طابور صغيرة (قاعدة §1)، والأصل يُحفظ للتراجع --}}
            <button
                type="button" @click="enhance()" :disabled="! canEnhance"
                :title="enhanceTitle" :aria-busy="enhancing ? 'true' : 'false'"
                :class="canEnhance ? 'text-brand-700 dark:text-brand-400 hover:bg-brand-50 dark:hover:bg-brand-950' : 'opacity-50 cursor-not-allowed'"
                class="btn btn-ghost btn-icon"
            >
                <x-icon name="wand" class="w-4 h-4" x-show="! enhancing" />
                <x-icon name="refresh" class="w-4 h-4 motion-safe:animate-spin" x-show="enhancing" x-cloak />
                <span class="sr-only">تحسين الوصف بالذكاء الاصطناعي</span>
            </button>
        </div>

        {{-- حالة تحسين الوصف: جارٍ / تم (مع تراجع) / خطأ --}}
        <div x-show="listening || voiceError || enhancing || enhanceError || canUndoEnhance" x-cloak class="flex items-center gap-2 px-1 text-xs" role="status" aria-live="polite">
            <span x-show="listening" class="flex items-center gap-1.5 text-danger-fg">
                <span class="w-2 h-2 rounded-full bg-danger motion-safe:animate-pulse"></span>
                يستمع… تحدّث بالعربية، واضغط الميكروفون للإيقاف
            </span>
            <span x-show="! listening && voiceError" class="flex items-center gap-1.5 text-danger-fg">
                <x-icon name="mic" class="w-3.5 h-3.5" />
                <span x-text="voiceError"></span>
            </span>
            <span x-show="! listening && ! voiceError && enhancing" class="flex items-center gap-1.5 text-fg-muted">
                <x-icon name="wand" class="w-3.5 h-3.5 text-brand-600 dark:text-brand-400" />
                يحسّن الوصف… عادةً بضع ثوانٍ
            </span>
            <span x-show="! listening && ! voiceError && ! enhancing && enhanceError" class="flex items-center gap-1.5 text-danger-fg">
                <x-icon name="alert" class="w-3.5 h-3.5" />
                <span x-text="enhanceError"></span>
            </span>
            <span x-show="! listening && ! voiceError && ! enhancing && ! enhanceError && canUndoEnhance" class="flex items-center gap-2 text-fg-muted">
                <x-icon name="check" class="w-3.5 h-3.5 text-success-fg" />
                حُسّن الوصف — راجعه قبل التوليد
                <button type="button" @click="undoEnhance()" class="font-semibold text-brand-700 dark:text-brand-400 underline underline-offset-4">تراجع</button>
            </span>
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
