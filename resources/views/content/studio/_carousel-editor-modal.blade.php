{{--
    نافذة «نظام الكاروسيل» (تعديل الكاروسيل من قائمة الصورة أو بطاقة الخطة).
    المنطق في resources/js/carousel-editor.js والرسم في carousel-render.js.
    لا يتغير شيء حتى «حفظ» (يستبدل) أو «حفظ كنسخة جديدة» (الأصل كما هو).
--}}
<div
    x-data="carouselEditor"
    @carousel-editor:open.window="openWith($event.detail)"
    @keydown.escape.window="open && (editingText ? stopEditing() : requestClose())"
    x-show="open"
    x-cloak
    class="fixed inset-0 z-[75] overflow-y-auto"
    role="dialog"
    aria-modal="true"
    aria-labelledby="carousel-editor-title"
>
    <div x-show="open" x-transition.opacity.duration.150ms @click="requestClose()" class="fixed inset-0 bg-scrim/60 backdrop-blur-sm" aria-hidden="true"></div>

    <div class="relative min-h-full grid place-items-center p-3 sm:p-6">
        <div x-show="open" x-transition.opacity.duration.150ms class="relative w-full max-w-xl card shadow-pop flex flex-col max-h-[94vh]">

            {{-- الرأس --}}
            <header class="flex items-center gap-2 px-4 py-3 border-b border-line shrink-0">
                <button type="button" @click="requestClose()" class="btn btn-ghost btn-sm rounded-full border border-line">
                    <x-icon name="chevron-right" class="w-3.5 h-3.5" />
                    رجوع
                </button>
                <h2 id="carousel-editor-title" class="flex-1 text-center text-sm font-bold text-fg">نظام الكاروسيل</h2>
                <button type="button" @click="requestClose()" class="grid place-items-center w-8 h-8 rounded-full border border-line text-fg-muted hover:bg-muted">
                    <x-icon name="close" class="w-3.5 h-3.5" />
                    <span class="sr-only">إغلاق</span>
                </button>
            </header>

            {{-- التحميل / الخطأ --}}
            <div x-show="loading" class="p-10 grid place-items-center text-sm text-fg-muted">
                <x-icon name="refresh" class="w-5 h-5 motion-safe:animate-spin mb-2" />
                يفتح الكاروسيل…
            </div>
            <div x-show="! loading && loadError" x-cloak class="p-6">
                <div class="alert-danger" role="alert"><x-icon name="alert-circle" class="w-5 h-5 shrink-0" /><p x-text="loadError"></p></div>
            </div>

            <template x-if="! loading && ! loadError && data">
                <div class="flex flex-col min-h-0">
                    <div class="overflow-y-auto px-4 sm:px-5 py-4 space-y-4">

                        {{-- العنوان والحالة والتراجع --}}
                        <div class="flex items-center gap-2">
                            <h3 class="flex-1 min-w-0 truncate text-base font-bold text-fg" x-text="data.title"></h3>
                            <span class="text-[11px]" :class="dirty ? 'text-warning-fg' : 'text-fg-subtle'" x-text="dirty ? 'غير محفوظ' : 'محفوظ'"></span>
                            <button type="button" @click="redo()" :disabled="! canRedo" class="grid place-items-center w-8 h-8 rounded-lg border border-line text-fg-muted hover:bg-muted disabled:opacity-40" title="إعادة">
                                <x-icon name="redo" class="w-3.5 h-3.5" />
                                <span class="sr-only">إعادة</span>
                            </button>
                            <button type="button" @click="undo()" :disabled="! canUndo" class="grid place-items-center w-8 h-8 rounded-lg border border-line text-fg-muted hover:bg-muted disabled:opacity-40" title="تراجع">
                                <x-icon name="undo" class="w-3.5 h-3.5" />
                                <span class="sr-only">تراجع</span>
                            </button>
                        </div>

                        <p class="rounded-xl border border-warning/30 bg-warning-soft text-warning-fg text-xs leading-relaxed px-3 py-2.5">
                            تعدّل الكاروسيل بحرية (النص والصور) — لا يتغيّر شيء حتى تضغط «حفظ» لاستبدالها، أو «حفظ كنسخة جديدة».
                        </p>

                        {{-- رسالة العملية الأخيرة --}}
                        <p x-show="message" x-cloak
                           :class="{ 'bg-success-soft text-success-fg': messageTone === 'success', 'bg-info-soft text-info-fg': messageTone === 'info', 'bg-danger-soft text-danger-fg': messageTone === 'error' }"
                           class="rounded-xl text-xs px-3 py-2" role="status" aria-live="polite" x-text="message"></p>

                        {{-- شريط الشرائح --}}
                        <div class="flex items-start justify-center gap-2 flex-wrap" role="tablist" aria-label="الشرائح">
                            <template x-for="(item, index) in slides" :key="item.key">
                                <div class="relative">
                                    <button type="button" role="tab" @click="select(index)"
                                            :aria-selected="current === index ? 'true' : 'false'"
                                            :class="current === index ? 'ring-2 ring-fg border-fg' : 'border-line hover:border-fg-subtle'"
                                            class="relative block w-12 rounded-lg border overflow-hidden bg-muted"
                                            :style="`aspect-ratio: ${ratioCss}`">
                                        <canvas :data-strip="item.key" class="w-full h-full"></canvas>
                                        <span class="absolute inset-x-0 bottom-0 text-[10px] font-bold text-white bg-black/45 tnum" x-text="index + 1"></span>
                                        <span x-show="hasIssue(item)" class="absolute top-1 end-1 w-1.5 h-1.5 rounded-full bg-danger" title="فيها ما يحتاج مراجعة"></span>
                                    </button>
                                    <button type="button" x-show="current === index" @click="removeSlide(index)"
                                            class="absolute -top-2 -start-2 grid place-items-center w-5 h-5 rounded-full bg-card border border-line text-fg-muted hover:text-danger-fg shadow-sm"
                                            title="حذف الشريحة">
                                        <x-icon name="close" class="w-3 h-3" />
                                        <span class="sr-only">حذف الشريحة</span>
                                    </button>
                                </div>
                            </template>

                            <button type="button" @click="addSlide()"
                                    class="grid place-items-center w-12 rounded-lg border border-dashed border-line-strong text-fg-subtle hover:text-fg hover:bg-muted"
                                    :style="`aspect-ratio: ${ratioCss}`" title="شريحة جديدة">
                                <x-icon name="plus" class="w-4 h-4" />
                                <span class="sr-only">شريحة جديدة</span>
                            </button>
                        </div>

                        {{-- المعاينة: النص يُحرَّر في مكانه --}}
                        <div class="flex justify-center">
                            <div x-ref="stage" @click.outside="editingText && ! drag && stopEditing()"
                                 class="relative w-60 sm:w-64 rounded-2xl overflow-visible"
                                 :style="`aspect-ratio: ${ratioCss}; container-type: inline-size`">
                                <canvas x-ref="preview" @click="startEditing()" class="w-full h-full rounded-2xl shadow-md cursor-text bg-muted select-none"></canvas>

                                <template x-if="editingText && slide">
                                    <div class="absolute" :style="boxStyle">
                                        {{-- شريط تنسيق النص --}}
                                        <div class="absolute bottom-full mb-3 left-1/2 -translate-x-1/2 flex items-center gap-1 px-2 py-1.5 rounded-2xl bg-card shadow-pop border border-line z-10 whitespace-nowrap"
                                             style="font-family: inherit">
                                            <button type="button" @click="toggleBold()" :class="box.bold && 'bg-muted'" class="w-7 h-7 rounded-lg text-sm font-bold text-fg hover:bg-muted">B</button>
                                            <button type="button" @click="toggleItalic()" :class="box.italic && 'bg-muted'" class="w-7 h-7 rounded-lg text-sm italic text-fg hover:bg-muted">I</button>
                                            <span class="w-px h-5 bg-line mx-0.5"></span>
                                            <template x-for="hex in [data.brand.primary, ...swatches].filter(Boolean)" :key="hex">
                                                <button type="button" @click="setColor(hex)"
                                                        :class="box.color === hex.toUpperCase() ? 'ring-2 ring-offset-1 ring-fg' : ''"
                                                        class="w-5 h-5 rounded-full border border-black/10" :style="`background:${hex}`" :title="hex"></button>
                                            </template>
                                            <label class="relative w-5 h-5 rounded-full cursor-pointer border border-black/10" title="لون آخر"
                                                   style="background: conic-gradient(#ef4444,#f59e0b,#84cc16,#06b6d4,#6366f1,#d946ef,#ef4444)">
                                                <input type="color" class="absolute inset-0 opacity-0 cursor-pointer" @input="setColor($event.target.value)">
                                            </label>
                                            <span class="w-px h-5 bg-line mx-0.5"></span>
                                            <button type="button" @click="resetLayout()" class="grid place-items-center w-7 h-7 rounded-lg text-fg-muted hover:bg-muted" title="موضع الشكل الافتراضي">
                                                <x-icon name="refresh" class="w-3.5 h-3.5" />
                                            </button>
                                            <button type="button" @click="hideText()" class="grid place-items-center w-7 h-7 rounded-lg text-fg-muted hover:text-danger-fg hover:bg-danger-soft" title="حذف النص من الشريحة">
                                                <x-icon name="trash" class="w-3.5 h-3.5" />
                                            </button>
                                        </div>

                                        {{-- المربع: الإطار يسحب للنقل، والنص يُكتب فيه --}}
                                        <div class="relative rounded-md outline outline-2 outline-sky-500 cursor-move p-[1.2cqw] -m-[1.2cqw]" @pointerdown.self="startDrag($event, 'move')" dir="rtl">
                                            <template x-if="! parts">
                                                <div data-edit-field="text" contenteditable="true" @input="onText('text', $event.target)"
                                                     class="outline-none cursor-text whitespace-pre-wrap text-right min-h-[1em]" :style="textStyle('body')"
                                                     data-placeholder="اكتب نص الشريحة"></div>
                                            </template>
                                            <template x-if="parts">
                                                <div>
                                                    <div data-edit-field="kicker" contenteditable="true" @input="onText('kicker', $event.target)" class="outline-none cursor-text whitespace-pre-wrap text-right" :style="textStyle('kicker')"></div>
                                                    <div data-edit-field="focal" contenteditable="true" @input="onText('focal', $event.target)" class="outline-none cursor-text whitespace-pre-wrap text-right" :style="textStyle('focal')"></div>
                                                    <div data-edit-field="tail" contenteditable="true" @input="onText('tail', $event.target)" class="outline-none cursor-text whitespace-pre-wrap text-right" :style="textStyle('tail')"></div>
                                                </div>
                                            </template>

                                            {{-- مقابض: طرفا العرض، ودائرة حجم الخط، ومقبض سفلي للنقل --}}
                                            <span @pointerdown="startDrag($event, 'right')" class="absolute top-1/2 -right-1.5 -translate-y-1/2 w-2.5 h-7 rounded-full bg-white border-2 border-sky-500 cursor-ew-resize touch-none"></span>
                                            <span @pointerdown="startDrag($event, 'left')" class="absolute top-1/2 -left-1.5 -translate-y-1/2 w-2.5 h-7 rounded-full bg-white border-2 border-sky-500 cursor-ew-resize touch-none"></span>
                                            <span @pointerdown="startDrag($event, 'scale')" class="absolute -bottom-2.5 -left-2.5 w-5 h-5 rounded-full bg-white border-2 border-sky-500 cursor-nesw-resize touch-none" title="حجم الخط"></span>
                                            <span @pointerdown="startDrag($event, 'move')" class="absolute -bottom-1.5 left-1/2 -translate-x-1/2 w-7 h-2.5 rounded-full bg-white border-2 border-sky-500 cursor-move touch-none"></span>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <p class="text-[11px] text-fg-subtle leading-relaxed text-center">
                            اضغط على النص لتعديله أو تغيير لونه، واسحبه من طرفه لتحريكه، واسحب المقبض الجانبي لتغيير عرض الصندوق، والدائرة لتكبير النص.
                            <button type="button" x-show="slide?.layout?.hidden" @click="startEditing()" class="font-semibold text-brand-700 dark:text-brand-400 underline underline-offset-4">إظهار النص</button>
                        </p>

                        {{-- صورة الشريحة ووصفها --}}
                        <section class="rounded-2xl border border-line p-3.5 space-y-2.5" x-show="slide">
                            <div class="flex items-center gap-2">
                                <button type="button" role="switch" :aria-checked="slide?.image ? 'true' : 'false'" @click="slide.image = ! slide.image"
                                        :class="slide?.image ? 'bg-fg' : 'bg-line-strong'"
                                        class="relative w-10 h-6 rounded-full transition shrink-0">
                                    <span :class="slide?.image ? '-translate-x-4' : 'translate-x-0'"
                                          class="absolute top-1 start-1 w-4 h-4 rounded-full bg-white shadow transition"></span>
                                </button>
                                <span class="text-sm font-semibold text-fg">صورة لهذه الشريحة</span>
                                <span class="ms-auto text-[11px] text-fg-subtle" x-show="slide?.image && ! hasImage">نقترح صورة هنا</span>
                                <span class="ms-auto text-[11px] text-fg-subtle" x-show="! slide?.image">خلفية بلون علامتك</span>
                            </div>

                            <div x-show="slide?.image" class="space-y-2">
                                <label class="block text-xs font-semibold text-fg" for="carousel-visual">وصف الصورة — عدّله أو اتركه كما هو</label>
                                <textarea id="carousel-visual" x-model="slide.visual" rows="4" maxlength="2000" class="field text-xs leading-relaxed"
                                          placeholder="يُشتق تلقائياً من نص الشريحة إن تركته فارغاً"></textarea>
                                <div class="flex items-center justify-between gap-2 text-[11px]">
                                    <button type="button" @click="rewriteVisual()" :disabled="busy !== ''" class="font-semibold text-fg underline underline-offset-4 disabled:opacity-50">
                                        <span x-show="busy !== 'visual'">أعد كتابة الوصف من نص الشريحة</span>
                                        <span x-show="busy === 'visual'" x-cloak>يكتب الوصف…</span>
                                    </button>
                                    <span class="text-fg-subtle tnum"><span x-text="(slide?.visual || '').length"></span> / 2000</span>
                                </div>
                                <button type="button" @click="regenerateImage()" :disabled="busy !== ''" class="btn btn-sm rounded-full border border-line-strong text-fg hover:bg-muted">
                                    <x-icon name="refresh" class="w-3.5 h-3.5" ::class="busy === 'image' && 'motion-safe:animate-spin'" />
                                    <span x-text="(hasImage ? 'أعد إنشاء الصورة' : 'أنشئ الصورة') + ' · ' + imageCostLabel"></span>
                                </button>
                            </div>
                        </section>

                        <div class="divider"></div>

                        {{-- إعدادات الكاروسيل --}}
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm font-semibold text-fg">جودة الصور</span>
                            <select x-model="design.quality" class="field w-auto min-h-9 py-1 text-xs rounded-full" aria-label="جودة الصور">
                                <template x-for="(tier, key) in data.tiers" :key="key">
                                    <option :value="key" x-text="`${tier.label} · ${tier.credits} نقطة`" :selected="design.quality === key"></option>
                                </template>
                            </select>
                        </div>

                        <div class="flex items-center justify-between gap-3">
                            <span class="text-sm font-semibold text-fg">التاريخ على الشرائح</span>
                            <div class="flex items-center p-1 rounded-full bg-muted" :title="datesAvailable ? '' : 'حدّد تاريخ نشر للمنشور في الخطة ليظهر هنا'">
                                <template x-for="mode in [['none', 'بدون'], ['month', 'الشهر'], ['day_month', 'اليوم والشهر']]" :key="mode[0]">
                                    <button type="button" @click="design.date = mode[0]" :disabled="mode[0] !== 'none' && ! datesAvailable"
                                            :class="design.date === mode[0] ? 'bg-card text-fg font-semibold shadow-sm' : 'text-fg-muted hover:text-fg'"
                                            class="px-3 min-h-8 rounded-full text-xs transition disabled:opacity-40" x-text="mode[1]"></button>
                                </template>
                            </div>
                        </div>

                        <div class="space-y-2">
                            <span class="block text-sm font-semibold text-fg">شكل الكاروسيل</span>
                            <div class="grid grid-cols-3 sm:grid-cols-6 gap-2">
                                <template x-for="key in templateKeys" :key="key">
                                    <button type="button" @click="design.template = key"
                                            :class="design.template === key ? 'ring-2 ring-fg border-fg' : 'border-line hover:border-fg-subtle'"
                                            class="rounded-xl border p-1 text-center transition">
                                        <canvas :data-template="key" class="w-full rounded-lg bg-muted" :style="`aspect-ratio: ${ratioCss}`"></canvas>
                                        <span class="block mt-1 text-[11px] font-medium text-fg" x-text="templateLabel(key)"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>

                    {{-- التذييل --}}
                    <footer class="flex items-center gap-2 px-4 sm:px-5 py-3 border-t border-line shrink-0">
                        <template x-if="confirmClose">
                            <div class="flex flex-1 flex-wrap items-center gap-2">
                                <span class="text-xs text-warning-fg flex-1">لديك تعديلات غير محفوظة.</span>
                                <button type="button" @click="close()" class="btn btn-ghost btn-sm text-danger-fg">تجاهلها وأغلق</button>
                                <button type="button" @click="confirmClose = false" class="btn-secondary btn-sm">متابعة التعديل</button>
                            </div>
                        </template>
                        <template x-if="! confirmClose">
                            <div class="flex flex-1 flex-wrap items-center gap-2">
                                <button type="button" @click="startOver()" :disabled="! dirty || busy !== ''" class="btn btn-ghost btn-sm text-fg-muted disabled:opacity-40">ابدأ من جديد</button>
                                <span class="ms-auto text-xs text-fg-subtle">التعديل <strong class="text-fg">مجاني</strong></span>
                                <button type="button" @click="saveCopy()" :disabled="busy !== ''" class="btn-secondary btn-sm rounded-full">
                                    <span x-show="busy !== 'copy'">حفظ كنسخة جديدة</span>
                                    <span x-show="busy === 'copy'" x-cloak>يحفظ…</span>
                                </button>
                                <button type="button" @click="save()" :disabled="busy !== '' || ! dirty" class="btn-primary btn-sm rounded-full px-5">
                                    <span x-show="busy !== 'save'">حفظ</span>
                                    <span x-show="busy === 'save'" x-cloak>يحفظ…</span>
                                </button>
                            </div>
                        </template>
                    </footer>
                </div>
            </template>
        </div>
    </div>
</div>
