{{--
    نموذج واحد للسلعة والخدمة.
    الحقول المشتركة تبقى كما هي، والمختلف يتبدّل عنوانه ووجوده حسب النوع —
    أرخص في الصيانة من نموذجين ينحرفان عن بعضهما مع الوقت.

    النموذج يُرسل إرسالاً عادياً (لا fetch): إن فشل التحقق يعيد لارافل
    القيم القديمة، وتُعيد الصفحة فتح النافذة على النوع نفسه.
--}}
<div
    x-show="form !== null"
    x-cloak
    class="fixed inset-0 z-[60] overflow-y-auto"
    role="dialog"
    aria-modal="true"
    aria-labelledby="product-form-title"
>
    <div
        x-show="form !== null"
        x-transition.opacity.duration.150ms
        @click="close()"
        class="fixed inset-0 bg-scrim/50 backdrop-blur-sm"
        aria-hidden="true"
    ></div>

    <div class="relative min-h-full grid place-items-center p-4">
        {{-- x-if لا x-show: الحقول تقرأ form.data، فوجودها قبل وجود form خطأ وقت تشغيل --}}
        <template x-if="form !== null">
            <form
                method="POST"
                enctype="multipart/form-data"
                {{--
                    إطار رسم إضافي بعد $nextTick: القادم من نافذة اختيار النوع
                    يفقد تركيزه حين تُزال تلك النافذة، فالتركيز قبلها يضيع.
                --}}
                x-init="$nextTick(() => requestAnimationFrame(() => $refs.firstField?.focus()))"
                @keydown.tab="
                    const f = [...$el.querySelectorAll('input:not([type=hidden]), textarea, select, button')]
                        .filter(el => el.offsetParent !== null);
                    if (! f.length) return;
                    const first = f[0], last = f[f.length - 1];
                    if ($event.shiftKey && document.activeElement === first) { $event.preventDefault(); last.focus(); }
                    else if (! $event.shiftKey && document.activeElement === last) { $event.preventDefault(); first.focus(); }
                "
                :action="form.mode === 'edit'
                    ? '{{ url('products') }}/' + form.id
                    : '{{ route('products.store') }}'"
                class="relative w-full max-w-2xl card shadow-pop motion-safe:animate-scale-in"
            >
            @csrf
            <template x-if="form.mode === 'edit'">
                <input type="hidden" name="_method" value="PUT">
            </template>

            {{-- علامات تُعيد فتح النافذة على حالتها بعد فشل التحقق --}}
            <input type="hidden" name="_mode" :value="form.mode">
            <input type="hidden" name="_id" :value="form.id ?? ''">
            <input type="hidden" name="type" :value="form.type">

            {{-- ---------- الرأس ---------- --}}
            <header class="sticky top-0 z-10 flex items-start justify-between gap-4 px-5 py-4 glass border-b border-line rounded-t-2xl">
                <div class="min-w-0">
                    <h2 id="product-form-title" class="text-base font-bold text-fg" x-text="labels.heading"></h2>
                    <p class="text-xs text-fg-muted mt-0.5">
                        ما تكتبه هنا يدخل البرومبت حرفياً — التفصيل يوفّر التعديل لاحقاً.
                    </p>
                </div>

                <button type="button" @click="close()" class="btn btn-ghost btn-sm btn-icon -m-1.5 shrink-0">
                    <x-icon name="close" class="w-5 h-5" />
                    <span class="sr-only">إغلاق</span>
                </button>
            </header>

            <div class="p-5 space-y-5">

                {{-- ---------- استيراد من رابط ---------- --}}
                {{-- يُملأ النموذج بدل أن يُنشأ سجل: المستخدم يراجع قبل أن يحفظ --}}
                <div class="rounded-xl border border-line bg-muted/50 p-3.5">
                    <label :for="'pf-import'" class="flex items-center justify-between gap-2 text-sm font-medium text-fg mb-2">
                        <span x-text="isService ? 'رابط الخدمة' : 'رابط المنتج'"></span>
                        <span class="text-xs font-normal text-fg-subtle">اختياري</span>
                    </label>

                    <div class="flex flex-col sm:flex-row gap-2">
                        <input
                            id="pf-import" type="url" dir="ltr"
                            x-model="importUrl"
                            @keydown.enter.prevent="importFromUrl()"
                            class="field flex-1 bg-card"
                            placeholder="https://example.com/products/123"
                        >

                        <button
                            type="button"
                            @click="importFromUrl()"
                            :disabled="importing || ! importUrl.trim()"
                            :data-busy="importing ? 'true' : null"
                            class="btn btn-secondary shrink-0"
                        >
                            <span class="inline-flex items-center gap-2">
                                <x-icon name="download" class="w-4 h-4" />
                                استيراد ({{ $importCost }} نقطة)
                            </span>
                        </button>
                    </div>

                    <p x-show="! importError" class="hint">
                        الصق رابط الصفحة لاستيراد بياناتها تلقائياً. لا تُخصم النقطة إن تعذّرت القراءة.
                    </p>

                    <p x-show="importError" x-cloak class="error-text" role="alert">
                        <x-icon name="alert-circle" class="w-3.5 h-3.5 mt-px" />
                        <span x-text="importError"></span>
                    </p>

                    {{-- الصور المستوردة تنزل إلى المعرض بالأسفل، فلا نكررها هنا --}}
                    <p x-show="importedImages.length" x-cloak class="hint">
                        <span x-text="importedImages.length"></span> صورة من المصدر أُضيفت إلى المعرض بالأسفل.
                    </p>
                </div>

                {{-- ---------- الصور ---------- --}}
                {{--
                    معرض واحد يجمع الموجود والمستورد والمرفوع حديثاً.
                    فصلها إلى ثلاثة أشرطة يخفي على المستخدم كم صورة سيحفظ فعلاً،
                    ويمنعه من اختيار مرجع بصري من بينها جميعاً.
                --}}
                <div>
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                        <span class="label mb-0" x-text="labels.images"></span>

                        <span class="text-xs text-fg-subtle tnum">
                            <span x-text="gallery.length"></span> من <span x-text="maxImages"></span>
                        </span>
                    </div>

                    <div
                        x-show="gallery.length"
                        x-cloak
                        class="grid grid-cols-3 sm:grid-cols-4 gap-2.5 mb-3"
                        role="group"
                        aria-label="صور العنصر — اختر الصورة الرئيسية أو احذف ما لا تريد"
                    >
                        <template x-for="item in gallery" :key="item.key">
                            <div class="relative aspect-square">
                                {{-- البطاقة نفسها هي زر اختيار المرجع البصري --}}
                                <button
                                    type="button"
                                    @click="setReference(item.key)"
                                    :aria-pressed="isReference(item.key) ? 'true' : 'false'"
                                    :title="isReference(item.key) ? 'هذه هي الصورة الرئيسية' : 'اجعلها الصورة الرئيسية'"
                                    class="block w-full h-full rounded-xl overflow-hidden border-2 transition"
                                    :class="isReference(item.key)
                                        ? 'border-brand-500 ring-2 ring-brand-500/30'
                                        : 'border-line hover:border-brand-300'"
                                >
                                    <img :src="item.url" alt="" class="w-full h-full object-cover">

                                    <span
                                        x-show="isReference(item.key)"
                                        class="absolute inset-x-0 bottom-0 bg-brand-600 text-white text-[10px] font-bold text-center py-0.5"
                                    >رئيسية</span>

                                    <span class="sr-only" x-text="isReference(item.key) ? 'الصورة الرئيسية' : 'اجعلها الصورة الرئيسية'"></span>
                                </button>

                                <button
                                    type="button"
                                    @click.stop="removeGalleryImage(item)"
                                    class="absolute -top-2 -end-2 grid place-items-center w-7 h-7 rounded-full
                                           bg-scrim text-white shadow-md hover:bg-danger transition"
                                >
                                    <x-icon name="close" class="w-4 h-4" />
                                    <span class="sr-only">حذف هذه الصورة</span>
                                </button>
                            </div>
                        </template>
                    </div>

                    {{-- الحذف لا يقع إلا عند الحفظ، فالتراجع ممكن ما دامت النافذة مفتوحة --}}
                    <p x-show="removedImageIds.length" x-cloak class="flex flex-wrap items-center gap-2 text-xs text-warning-fg mb-3">
                        <x-icon name="alert" class="w-3.5 h-3.5" />
                        <span>
                            ستُحذف <span class="tnum" x-text="removedImageIds.length"></span> صورة عند الحفظ.
                        </span>
                        <button type="button" @click="restoreRemovedImages()" class="font-semibold underline underline-offset-2 hover:text-fg">
                            تراجع
                        </button>
                    </p>

                    <label
                        x-show="! galleryFull"
                        class="flex flex-col items-center justify-center gap-1.5 w-full px-4 py-5 rounded-xl cursor-pointer text-center
                               border-2 border-dashed border-line-strong bg-muted/40
                               transition hover:border-brand-400 hover:bg-brand-50/40 dark:hover:bg-brand-500/5
                               has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-brand-500 has-[:focus-visible]:ring-offset-2"
                    >
                        <x-icon name="upload" class="w-5 h-5 text-fg-muted" />
                        <span class="text-sm font-medium text-brand-700 dark:text-brand-400">
                            <span x-text="isService ? 'إضافة صورة' : 'إضافة صور'"></span>
                        </span>
                        <span class="text-xs text-fg-subtle" x-text="labels.imagesHint"></span>

                        <input
                            type="file" name="images[]" accept="image/png,image/jpeg,image/webp"
                            class="sr-only"
                            :multiple="! isService"
                            @change="previewImages($event)"
                        >
                    </label>

                    <p x-show="galleryFull" x-cloak class="hint">
                        بلغت الحد الأقصى. احذف صورة لتتمكن من إضافة أخرى.
                    </p>

                    <p x-show="gallery.length > 1" x-cloak class="hint">
                        اضغط على أي صورة لجعلها الرئيسية — وهي المرجع البصري في توليد الصور.
                    </p>

                    {{-- ما يُرسل للخادم: المحذوف، والمرجع المختار --}}
                    <template x-for="id in removedImageIds" :key="`rm-${id}`">
                        <input type="hidden" name="removed_image_ids[]" :value="id">
                    </template>

                    <input type="hidden" name="reference" :value="reference">

                    @error('images.*')
                        <p class="error-text"><x-icon name="alert-circle" class="w-3.5 h-3.5 mt-px" /><span>{{ $message }}</span></p>
                    @enderror
                </div>

                {{-- ---------- الاسم ---------- --}}
                <div>
                    <label for="pf-title" class="label">
                        <span x-text="labels.title"></span>
                        <span class="text-danger-fg" aria-hidden="true">*</span>
                        <span class="sr-only">(حقل مطلوب)</span>
                    </label>

                    <input
                        id="pf-title" name="title" type="text" required maxlength="180"
                        x-ref="firstField"
                        x-model="form.data.title"
                        :placeholder="labels.titlePlaceholder"
                        class="field @error('title') field-invalid @enderror"
                    >

                    @error('title')
                        <p class="error-text"><x-icon name="alert-circle" class="w-3.5 h-3.5 mt-px" /><span>{{ $message }}</span></p>
                    @enderror
                </div>

                {{-- ---------- النص الوصفي الأساسي (المميزات / وصف الخدمة) ---------- --}}
                <div>
                    <label for="pf-features" class="label">
                        <span x-text="labels.body"></span>
                        <span class="text-danger-fg" aria-hidden="true">*</span>
                        <span class="sr-only">(حقل مطلوب)</span>
                    </label>

                    <textarea
                        id="pf-features" name="features" rows="5" required maxlength="5000"
                        x-model="form.data.features"
                        :placeholder="labels.bodyPlaceholder"
                        class="field @error('features') field-invalid @enderror"
                    ></textarea>

                    @error('features')
                        <p class="error-text"><x-icon name="alert-circle" class="w-3.5 h-3.5 mt-px" /><span>{{ $message }}</span></p>
                    @else
                        <p class="hint">سطر لكل نقطة يجعل النموذج يلتزم بالتعداد بدل صياغة فقرة مسترسلة.</p>
                    @enderror
                </div>

                {{-- ---------- خاص بالسلعة: المواصفات ---------- --}}
                <div x-show="! isService" x-cloak>
                    <label for="pf-specifications" class="label">
                        المواصفات <span class="font-normal text-fg-subtle">— اختياري</span>
                    </label>

                    <textarea
                        id="pf-specifications" name="specifications" rows="4" maxlength="5000"
                        x-model="form.data.specifications"
                        placeholder="الوزن: 250 جرام&#10;درجة التحميص: فاتحة"
                        class="field @error('specifications') field-invalid @enderror"
                    ></textarea>

                    @error('specifications')
                        <p class="error-text"><x-icon name="alert-circle" class="w-3.5 h-3.5 mt-px" /><span>{{ $message }}</span></p>
                    @else
                        <p class="hint">اتركه فارغاً إن لم تتوفر لديك مواصفات — لن نخترعها.</p>
                    @enderror
                </div>

                {{-- ---------- خاص بالخدمة: التسليمات ---------- --}}
                <div x-show="isService" x-cloak>
                    <label for="pf-deliverables" class="label">
                        ماذا يحصل العميل؟
                        <span class="text-danger-fg" aria-hidden="true">*</span>
                        <span class="sr-only">(حقل مطلوب)</span>
                    </label>

                    <textarea
                        id="pf-deliverables" name="deliverables" rows="4" maxlength="2000"
                        x-model="form.data.deliverables"
                        :required="isService"
                        placeholder="ما هي النتائج أو التسليمات التي يحصل عليها العميل؟"
                        class="field @error('deliverables') field-invalid @enderror"
                    ></textarea>

                    @error('deliverables')
                        <p class="error-text"><x-icon name="alert-circle" class="w-3.5 h-3.5 mt-px" /><span>{{ $message }}</span></p>
                    @enderror
                </div>

                {{-- ---------- الجمهور ---------- --}}
                <div>
                    <label for="pf-audience" class="label">الجمهور المستهدف</label>

                    <textarea
                        id="pf-audience" name="audience" rows="3" maxlength="1000"
                        x-model="form.data.audience"
                        :placeholder="isService ? 'من هو الجمهور المثالي لهذه الخدمة؟' : 'من هو الجمهور المثالي لهذا المنتج؟'"
                        class="field @error('audience') field-invalid @enderror"
                    ></textarea>

                    @error('audience')
                        <p class="error-text"><x-icon name="alert-circle" class="w-3.5 h-3.5 mt-px" /><span>{{ $message }}</span></p>
                    @else
                        <p class="hint">
                            مأخوذ من جمهور علامتك — عدّله إن كان هذا العنصر يخاطب شريحة أضيق.
                        </p>
                    @enderror
                </div>

                {{-- ---------- السعر ---------- --}}
                {{-- يبقى ظاهراً لأن هدف «البيع المباشر» ينص على ذكر السعر في المنشور --}}
                <div class="grid sm:grid-cols-[1fr_7rem] gap-4">
                    <div>
                        <label for="pf-price" class="label">
                            السعر <span class="font-normal text-fg-subtle">— اختياري</span>
                        </label>

                        <input
                            id="pf-price" name="price" type="number" step="0.01" min="0"
                            inputmode="decimal" dir="ltr" placeholder="0.00"
                            x-model="form.data.price"
                            class="field tnum @error('price') field-invalid @enderror"
                        >

                        @error('price')
                            <p class="error-text"><x-icon name="alert-circle" class="w-3.5 h-3.5 mt-px" /><span>{{ $message }}</span></p>
                        @else
                            <p class="hint">يُذكر في المنشور عند اختيار هدف «زيادة المبيعات المباشرة».</p>
                        @enderror
                    </div>

                    <div>
                        <label for="pf-currency" class="label">العملة</label>
                        <input
                            id="pf-currency" name="currency" maxlength="3" dir="ltr"
                            x-model="form.data.currency"
                            class="field uppercase"
                        >
                    </div>
                </div>

                {{-- ---------- حقائق البيع ---------- --}}
                {{-- ما يُكتب هنا يجوز للمحتوى ذكره بنصه؛ وما يُترك فارغاً لا يُذكر ولا يُخترع --}}
                <details
                    class="group rounded-xl border border-line"
                    :open="!! (form.data.compare_at_price || form.data.stock_status || form.data.rating_value || form.data.installment_providers.length || form.data.brand_name)"
                >
                    <summary class="flex items-center justify-between gap-2 px-3.5 py-3 cursor-pointer text-sm font-medium text-fg">
                        <span class="flex items-center gap-2">
                            <x-icon name="coins" class="w-4 h-4 text-fg-muted" />
                            حقائق البيع <span class="font-normal text-fg-subtle">— اختياري</span>
                        </span>
                        <x-icon name="chevron-down" class="w-4 h-4 text-fg-subtle transition group-open:rotate-180" />
                    </summary>

                    <div class="px-3.5 pb-3.5 space-y-4">
                        <p class="hint mt-0">كل ما تكتبه هنا يذكره المحتوى بنصه. ما تتركه فارغاً لن يُذكر، ولن نسمح للنموذج باختراعه.</p>

                        <div x-show="! isService" x-cloak>
                            <label for="pf-brand-name" class="label">العلامة التجارية المصنّعة</label>
                            <input
                                id="pf-brand-name" name="brand_name" type="text" maxlength="120"
                                x-model="form.data.brand_name" placeholder="مثال: حشوات بيسان"
                                class="field @error('brand_name') field-invalid @enderror"
                            >
                        </div>

                        <div class="grid sm:grid-cols-2 gap-4">
                            <div>
                                <label for="pf-compare" class="label">السعر قبل الخصم</label>
                                <input
                                    id="pf-compare" name="compare_at_price" type="number" step="0.01" min="0"
                                    inputmode="decimal" dir="ltr" placeholder="0.00"
                                    x-model="form.data.compare_at_price"
                                    class="field tnum @error('compare_at_price') field-invalid @enderror"
                                >
                                @error('compare_at_price')
                                    <p class="error-text"><x-icon name="alert-circle" class="w-3.5 h-3.5 mt-px" /><span>{{ $message }}</span></p>
                                @enderror
                            </div>

                            <div>
                                <label for="pf-sale-ends" class="label">ينتهي الخصم في</label>
                                <input
                                    id="pf-sale-ends" name="sale_ends_at" type="date" dir="ltr"
                                    x-model="form.data.sale_ends_at"
                                    :disabled="! form.data.compare_at_price"
                                    class="field tnum @error('sale_ends_at') field-invalid @enderror"
                                >
                                <p class="hint">بعده يتوقف المحتوى عن ذكر الخصم تلقائياً.</p>
                            </div>
                        </div>

                        <div>
                            <label for="pf-stock" class="label">التوفر</label>
                            <select id="pf-stock" name="stock_status" x-model="form.data.stock_status" class="field">
                                <option value="">غير محدد</option>
                                @foreach (\App\Models\Product::STOCK as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label for="pf-rating" class="label">التقييم في متجرك</label>
                                <input
                                    id="pf-rating" name="rating_value" type="number" step="0.1" min="1" max="5"
                                    inputmode="decimal" dir="ltr" placeholder="4.8"
                                    x-model="form.data.rating_value"
                                    class="field tnum @error('rating_value') field-invalid @enderror"
                                >
                            </div>

                            <div>
                                <label for="pf-rating-count" class="label">عدد التقييمات</label>
                                <input
                                    id="pf-rating-count" name="rating_count" type="number" step="1" min="1"
                                    inputmode="numeric" dir="ltr" placeholder="120"
                                    x-model="form.data.rating_count"
                                    class="field tnum @error('rating_count') field-invalid @enderror"
                                >
                            </div>

                            @if ($errors->has('rating_value') || $errors->has('rating_count'))
                                <p class="error-text col-span-2">
                                    <x-icon name="alert-circle" class="w-3.5 h-3.5 mt-px" />
                                    <span>{{ $errors->first('rating_value') ?: $errors->first('rating_count') }}</span>
                                </p>
                            @else
                                <p class="hint col-span-2 mt-0">كما يظهر للمشترين في صفحة المنتج. الاثنان معاً أو لا شيء.</p>
                            @endif
                        </div>

                        <fieldset>
                            <legend class="label">التقسيط</legend>
                            <div class="flex flex-wrap items-center gap-2">
                                @foreach (\App\Models\Product::INSTALLMENT_PROVIDERS as $value => $label)
                                    <label class="choice items-center py-2">
                                        <input
                                            type="checkbox" name="installment_providers[]" value="{{ $value }}"
                                            x-model="form.data.installment_providers"
                                            class="w-4 h-4 rounded border-line-strong bg-card text-brand-600 focus:ring-brand-500"
                                        >
                                        <span class="text-sm text-fg">{{ $label }}</span>
                                    </label>
                                @endforeach

                                <label class="flex items-center gap-2 text-sm text-fg-muted ms-auto">
                                    على
                                    <input
                                        name="installment_count" type="number" min="2" max="12" dir="ltr"
                                        x-model="form.data.installment_count"
                                        :disabled="! form.data.installment_providers.length"
                                        class="field tnum w-16 py-1.5"
                                        aria-label="عدد الدفعات"
                                    >
                                    دفعات
                                </label>
                            </div>
                        </fieldset>
                    </div>
                </details>

                {{-- ---------- خاص بالخدمة: ملاحظات ---------- --}}
                <div x-show="isService" x-cloak>
                    <label for="pf-notes" class="label">
                        ملاحظات إضافية <span class="font-normal text-fg-subtle">— اختياري</span>
                    </label>

                    <textarea
                        id="pf-notes" name="notes" rows="3" maxlength="2000"
                        x-model="form.data.notes"
                        placeholder="أي تفاصيل إضافية تساعد المساعد الذكي في تقديم خدمتك…"
                        class="field"
                    ></textarea>
                </div>

                {{-- ---------- الوصف المختصر ---------- --}}
                {{-- هو ما يظهر في البطاقة ويدخل ورقة المرجع، ولذلك يستحق حقلاً لا اشتقاقاً صامتاً --}}
                <div class="rounded-xl border border-brand-200 dark:border-brand-500/30 bg-brand-50/40 dark:bg-brand-500/5 p-3.5">
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                        <label for="pf-summary" class="flex items-center gap-2 text-sm font-medium text-fg">
                            <x-icon name="sparkles" class="w-4 h-4 text-brand-600 dark:text-brand-400" />
                            الوصف المختصر
                        </label>

                        <button
                            type="button"
                            @click="regenerateSummary()"
                            :disabled="summarising || ! form.data.title.trim()"
                            :data-busy="summarising ? 'true' : null"
                            class="btn btn-ghost btn-sm text-brand-700 dark:text-brand-400 hover:bg-brand-100/60 dark:hover:bg-brand-500/10"
                        >
                            <span class="inline-flex items-center gap-1.5">
                                <x-icon name="refresh" class="w-3.5 h-3.5" />
                                إعادة توليد ({{ $importCost }} نقطة)
                            </span>
                        </button>
                    </div>

                    <textarea
                        id="pf-summary" name="summary" rows="4" maxlength="600"
                        x-model="form.data.summary"
                        class="field bg-card @error('summary') field-invalid @enderror"
                        placeholder="يُشتق تلقائياً من المميزات عند الحفظ، أو اكتبه بنفسك، أو ولّده بالذكاء الاصطناعي."
                    ></textarea>

                    @error('summary')
                        <p class="error-text"><x-icon name="alert-circle" class="w-3.5 h-3.5 mt-px" /><span>{{ $message }}</span></p>
                    @enderror

                    <p x-show="summaryError" x-cloak class="error-text" role="alert">
                        <x-icon name="alert-circle" class="w-3.5 h-3.5 mt-px" />
                        <span x-text="summaryError"></span>
                    </p>

                    <p x-show="! summaryError" class="hint">هذا النص يظهر في البطاقة ويدخل ورقة المرجع.</p>
                </div>

                {{-- ---------- الخيارات ---------- --}}
                <div class="space-y-2.5 pt-1">
                    <label class="choice items-start">
                        <input type="hidden" name="is_primary" value="0">
                        <input
                            type="checkbox" name="is_primary" value="1"
                            x-model="form.data.is_primary"
                            class="mt-0.5 w-4 h-4 rounded border-line-strong bg-card text-brand-600 focus:ring-brand-500"
                        >
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-fg">اجعله المرجع الأساسي</span>
                            <span class="block text-xs text-fg-muted mt-0.5 leading-relaxed">
                                يُختار افتراضياً في «كتابة المحتوى».
                            </span>
                        </span>
                    </label>

                    <label class="choice items-start">
                        <input type="hidden" name="is_active" value="0">
                        <input
                            type="checkbox" name="is_active" value="1"
                            x-model="form.data.is_active"
                            class="mt-0.5 w-4 h-4 rounded border-line-strong bg-card text-brand-600 focus:ring-brand-500"
                        >
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-fg">متاح للتوليد</span>
                            <span class="block text-xs text-fg-muted mt-0.5 leading-relaxed">
                                أوقفه لإخفائه من قوائم «كتابة المحتوى» والاستوديو دون حذفه.
                            </span>
                        </span>
                    </label>
                </div>
            </div>

            {{-- ---------- الذيل ---------- --}}
            <footer class="sticky bottom-0 flex items-center gap-2.5 px-5 py-4 glass border-t border-line rounded-b-2xl">
                <button type="submit" class="btn-primary flex-1 sm:flex-none">
                    <span>حفظ</span>
                </button>

                <button type="button" @click="close()" class="btn-secondary flex-1 sm:flex-none">
                    <span>إلغاء</span>
                </button>

                <template x-if="form.mode === 'create'">
                    <span class="hidden sm:block text-xs text-fg-subtle ms-auto">
                        تُجهَّز الورقة المرجعية تلقائياً بعد الحفظ.
                    </span>
                </template>
            </footer>
            </form>
        </template>
    </div>
</div>

{{-- عارض الورقة المرجعية: النص الذي يقرأه النموذج فعلاً، معروضاً كما هو --}}
<div
    x-data="{ open: false, title: '', sheet: '' }"
    x-on:spec-sheet.window="open = true; title = $event.detail.title; sheet = $event.detail.sheet"
    @keydown.escape.window="open = false"
>
    <div x-show="open" x-cloak class="fixed inset-0 z-[70] grid place-items-center p-4" role="dialog" aria-modal="true" aria-label="الورقة المرجعية">
        <div x-show="open" x-transition.opacity.duration.150ms @click="open = false" class="absolute inset-0 bg-scrim/50 backdrop-blur-sm" aria-hidden="true"></div>

        <div x-show="open" x-transition:enter="motion-safe:animate-scale-in" class="relative w-full max-w-lg card shadow-pop p-5">
            <div class="flex items-start justify-between gap-4 mb-3">
                <div class="min-w-0">
                    <h2 class="text-base font-bold text-fg truncate" x-text="title"></h2>
                    <p class="text-xs text-fg-muted mt-0.5">هذا النص بالضبط هو ما يقرأه النموذج عند كل توليد.</p>
                </div>

                <button type="button" @click="open = false" class="btn btn-ghost btn-sm btn-icon -m-1.5 shrink-0">
                    <x-icon name="close" class="w-5 h-5" />
                    <span class="sr-only">إغلاق</span>
                </button>
            </div>

            <pre class="panel p-3.5 max-h-80 overflow-auto text-xs leading-relaxed text-fg-muted whitespace-pre-wrap font-sans" x-text="sheet"></pre>
        </div>
    </div>
</div>
