import Alpine from 'alpinejs';
import registerContentCalendar from './content-calendar';
import registerCarouselEditor from './carousel-editor';
import registerContentEditor from './content-editor';
import registerContentWriter from './content-writer';
import registerImageStudio from './image-studio';
import registerOperations from './operations';
import registerVoiceover from './voiceover';

/* ==========================================================================
   1. المظهر (فاتح / داكن / تبع النظام)
   القيمة تُطبَّق في <head> قبل الرسم (انظر layouts/app) فلا يومض البياض.
   ========================================================================== */
const THEME_KEY = 'theme';

const applyTheme = (value) => {
    const dark = value === 'dark'
        || (value === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);

    document.documentElement.classList.toggle('dark', dark);
};

Alpine.store('theme', {
    value: localStorage.getItem(THEME_KEY) || 'system',

    init() {
        applyTheme(this.value);

        // من اختار «تبع النظام» يتبعه فعلاً حتى لو تغيّر والصفحة مفتوحة
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
            if (this.value === 'system') applyTheme('system');
        });
    },

    set(value) {
        this.value = value;
        localStorage.setItem(THEME_KEY, value);
        applyTheme(value);
    },

    toggle() {
        this.set(document.documentElement.classList.contains('dark') ? 'light' : 'dark');
    },

    get isDark() {
        return document.documentElement.classList.contains('dark');
    },
});

/* ==========================================================================
   2. درج التنقل على الجوال، وطي القائمة الجانبية على سطح المكتب
   ========================================================================== */
const NAV_COLLAPSED_KEY = 'nav-collapsed';

Alpine.store('nav', {
    open: false,
    // يخص سطح المكتب فقط (lg+) — انظر قاعدة data-collapsed في app.css،
    // فهي مقيّدة بـ min-width حتى لا يتأثر بها الدرج المنسدل على الجوال.
    collapsed: localStorage.getItem(NAV_COLLAPSED_KEY) === '1',

    show() {
        this.open = true;
        document.documentElement.classList.add('overflow-hidden');
    },

    hide() {
        this.open = false;
        document.documentElement.classList.remove('overflow-hidden');
    },

    toggle() {
        this.open ? this.hide() : this.show();
    },

    toggleCollapsed() {
        this.collapsed = !this.collapsed;
        try {
            localStorage.setItem(NAV_COLLAPSED_KEY, this.collapsed ? '1' : '0');
        } catch {
            // تصفح خاص أو تخزين محجوب: التفضيل لن يُحفظ، والطي يبقى يعمل لهذه الجلسة فقط
        }
    },
});

/* ==========================================================================
   2b. أقسام القائمة الجانبية القابلة للطي
   الحالة الافتراضية لكل قسم (قبل أي تفضيل محفوظ) هي كونه يحوي الصفحة
   الحالية أم لا؛ Blade يحسبها ويمررها كـ fallback عند كل نداء.
   ========================================================================== */
const NAV_GROUPS_KEY = 'nav-groups-open';

const readNavGroupsState = () => {
    try {
        return JSON.parse(localStorage.getItem(NAV_GROUPS_KEY) || '{}');
    } catch {
        return {};
    }
};

Alpine.store('navGroups', {
    state: readNavGroupsState(),

    isOpen(key, fallback) {
        return key in this.state ? this.state[key] : fallback;
    },

    toggle(key, fallback) {
        this.state = { ...this.state, [key]: !this.isOpen(key, fallback) };

        try {
            localStorage.setItem(NAV_GROUPS_KEY, JSON.stringify(this.state));
        } catch {
            // نفس الحال: لا يوقف العمل، فقط لا يُحفظ بين الزيارات
        }
    },
});

/* ==========================================================================
   3. نسخ النص مع تأكيد مرئي
   ========================================================================== */
Alpine.data('copyText', (text) => ({
    copied: false,
    timer: null,

    async copy() {
        try {
            await navigator.clipboard.writeText(text);
        } catch {
            // متصفحات بلا صلاحية الحافظة أو اتصال غير آمن: نرجع للطريقة القديمة
            const area = document.createElement('textarea');
            area.value = text;
            area.setAttribute('readonly', '');
            area.style.cssText = 'position:fixed;inset-inline-start:-9999px';
            document.body.appendChild(area);
            area.select();
            document.execCommand('copy');
            area.remove();
        }

        this.copied = true;
        clearTimeout(this.timer);
        this.timer = setTimeout(() => (this.copied = false), 2000);
    },
}));

/* ==========================================================================
   3b. صفحة المنتجات والخدمات
   النوافذ والتحديد الجماعي في مكان واحد، لأنها تتشارك حالة واحدة:
   لا يجوز فتح نافذة وشريط تحديد معاً.
   ========================================================================== */
Alpine.data('productsIndex', (config = {}) => ({
    products: config.products || {},
    pageIds: config.pageIds || [],
    brandAudience: config.brandAudience || '',

    chooser: false,
    form: null,      // { mode: 'create'|'edit', id, type, data }
    previews: [],

    // معرض صور العنصر أثناء التعديل
    existingImages: [],
    removedImageIds: [],
    reference: '',
    selected: [],

    // استيراد رابط واحد داخل النموذج
    importUrl: '',
    importing: false,
    importError: '',
    importedImages: [],

    // توليد الوصف المختصر
    summarising: false,
    summaryError: '',

    /*
     * تفضيل عرض لكل متصفح: يخص هذا الجهاز وحده ولا يعني الخادم شيئاً،
     * فمكانه التخزين المحلي لا قاعدة البيانات.
     */
    view: 'grid',
    size: 2,
    /*
       الحدود متباعدة عمداً. مع auto-fill يتغير عدد الأعمدة عند عتبات،
       فحدود متقاربة تعطي خطوتين بالعدد نفسه ويبدو المقبض معطّلاً.
    */
    sizes: [
        { key: 'xs', min: '9rem',  label: 'صغير جداً' },
        { key: 'sm', min: '12rem', label: 'صغير' },
        { key: 'md', min: '16rem', label: 'متوسط' },
        { key: 'lg', min: '22rem', label: 'كبير' },
        { key: 'xl', min: '30rem', label: 'كبير جداً' },
    ],

    init() {
        // إعادة فتح النافذة بعد فشل التحقق، أو عند الوصول برابط ?add / ?edit
        if (config.initial?.form) {
            this.form = config.initial.form;

            // هذا المسار لا يمر بـ startEdit، فلا بد من تحميل المعرض صراحة
            if (this.form.mode === 'edit') this.hydrateGallery(this.products[this.form.id]);
        } else if (config.initial?.chooser) {
            this.chooser = true;
        }

        this.$watch('modalOpen', (open) =>
            document.documentElement.classList.toggle('overflow-hidden', open));

        this.restoreLayout();
    },

    // ---------- العرض والحجم ----------
    restoreLayout() {
        try {
            const view = localStorage.getItem('products.view');
            const size = parseInt(localStorage.getItem('products.size'), 10);

            if (view === 'list' || view === 'grid') this.view = view;
            if (Number.isInteger(size) && this.sizes[size]) this.size = size;
        } catch {
            // وضع التصفح الخاص أو تخزين محجوب: نبقى على الافتراضي
        }
    },

    persistLayout() {
        try {
            localStorage.setItem('products.view', this.view);
            localStorage.setItem('products.size', String(this.size));
        } catch {}
    },

    setView(view) {
        this.view = view;
        this.persistLayout();
    },

    setSize(value) {
        this.size = Math.max(0, Math.min(this.sizes.length - 1, Number(value)));
        this.persistLayout();
    },

    stepSize(delta) {
        this.setSize(this.size + delta);
    },

    get sizeKey() {
        return this.sizes[this.size].key;
    },

    get sizeLabel() {
        return this.sizes[this.size].label;
    },

    get gridStyle() {
        return `--card-min: ${this.sizes[this.size].min}`;
    },

    get modalOpen() {
        return this.chooser || this.form !== null;
    },

    // ---------- النوافذ ----------
    blank(type) {
        return {
            type,
            title: '', features: '', specifications: '', deliverables: '',
            audience: this.brandAudience, notes: '', summary: '', price: '', currency: 'SAR',
            brand_name: '', compare_at_price: '', sale_ends_at: '', stock_status: '',
            rating_value: '', rating_count: '', installment_providers: [], installment_count: 4,
            is_primary: false, is_active: true,
        };
    },

    startCreate(type) {
        this.chooser = false;
        this.resetGallery();
        this.resetImport();
        this.form = { mode: 'create', id: null, type, data: this.blank(type) };
    },

    startEdit(id) {
        const product = this.products[id];
        if (!product) return;

        this.resetGallery();
        this.resetImport();

        this.hydrateGallery(product);

        // العناصر التي سبقت هذا الحقل ترث جمهور العلامة بدل أن تفتح فارغة
        const data = { ...product, audience: product.audience || this.brandAudience };

        this.form = { mode: 'edit', id, type: product.type, data };
    },

    close() {
        this.chooser = false;
        this.form = null;
        this.resetGallery();
        this.resetImport();
    },

    // ---------- معرض الصور ----------
    /** يملأ المعرض بصور العنصر ويضبط المرجع البصري على المحفوظ. */
    hydrateGallery(product) {
        this.existingImages = (product?.images || []).map((image) => ({ ...image }));

        const reference = this.existingImages.find((image) => image.is_reference)
            ?? this.existingImages[0];

        this.reference = reference ? `existing:${reference.id}` : '';
    },

    resetGallery() {
        this.previews = [];
        this.existingImages = [];
        this.removedImageIds = [];
        this.reference = '';
    },

    /** الصور الباقية بعد ما وُسم للحذف — الحذف الفعلي لا يقع إلا عند الحفظ. */
    get keptImages() {
        return this.existingImages.filter((image) => ! this.removedImageIds.includes(image.id));
    },

    /**
     * المعرض الموحّد: الموجود ثم المستورد ثم المرفوع حديثاً.
     * مفتاح كل صورة يخبر الخادم أيها اختار المستخدم مرجعاً بصرياً.
     */
    get gallery() {
        return [
            ...this.keptImages.map((image) => ({ key: `existing:${image.id}`, url: image.url, id: image.id, kind: 'existing' })),
            ...this.importedImages.map((url) => ({ key: `url:${url}`, url, kind: 'url' })),
            ...this.previews.map((preview, index) => ({ key: `new:${index}`, url: preview.url, name: preview.name, kind: 'new' })),
        ];
    },

    get galleryFull() {
        return this.gallery.length >= this.maxImages;
    },

    /**
     * ما يُرسل للخادم يطابق ما يراه المستخدم.
     * لو تركناه فارغاً لاختار الخادم أول صورة ضمنياً، وهو تطابق
     * صامت ينكسر أول ما يتغير الترتيب.
     */
    get effectiveReference() {
        return this.reference || this.gallery[0]?.key || '';
    },

    isReference(key) {
        return this.reference === key
            || (this.reference === '' && this.gallery[0]?.key === key);
    },

    setReference(key) {
        this.reference = key;
    },

    removeGalleryImage(item) {
        if (item.kind === 'existing') this.removedImageIds.push(item.id);
        else if (item.kind === 'url') this.removeImportedImage(item.url);
        else this.previews = this.previews.filter((preview) => preview.url !== item.url);

        // المرجع المحذوف ينتقل لأول صورة باقية بدل أن يبقى معلّقاً على غير موجود
        if (this.reference === item.key) {
            this.reference = this.gallery[0]?.key ?? '';
        }
    },

    restoreRemovedImages() {
        this.removedImageIds = [];
    },

    resetImport() {
        this.importUrl = '';
        this.importing = false;
        this.importError = '';
        this.importedImages = [];
        this.summarising = false;
        this.summaryError = '';
    },

    removeImportedImage(url) {
        this.importedImages = this.importedImages.filter((item) => item !== url);
    },

    async regenerateSummary() {
        if (this.summarising || ! this.form?.data.title.trim()) return;

        this.summarising = true;
        this.summaryError = '';

        try {
            const response = await fetch(config.summaryUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=\"csrf-token\"]')?.content ?? '',
                },
                body: JSON.stringify({
                    title: this.form.data.title,
                    features: this.form.data.features,
                    specifications: this.form.data.specifications,
                    audience: this.form.data.audience,
                    price: this.form.data.price || null,
                    currency: this.form.data.currency,
                }),
            });

            const data = await response.json();

            if (! response.ok) {
                this.summaryError = data.message || 'تعذّر توليد الوصف.';

                return;
            }

            this.form.data.summary = data.summary;
        } catch {
            this.summaryError = 'تعذّر الاتصال بالخادم.';
        } finally {
            this.summarising = false;
        }
    },

    /**
     * يقرأ صفحة المنتج ويملأ الحقول أمام المستخدم.
     * متزامن لا في طابور: المستخدم واقف ينتظر، والعملية ثوانٍ.
     */
    async importFromUrl() {
        if (! this.importUrl.trim() || this.importing) return;

        this.importing = true;
        this.importError = '';

        try {
            const response = await fetch(config.singleImportUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=\"csrf-token\"]')?.content ?? '',
                },
                body: JSON.stringify({ url: this.importUrl.trim() }),
            });

            const data = await response.json();

            if (! response.ok) {
                this.importError = data.message || 'تعذّر استيراد هذا الرابط.';

                return;
            }

            // لا نمسح ما كتبه المستخدم بقيمة فارغة عائدة من المصدر
            for (const field of ['title', 'features', 'specifications', 'audience', 'summary', 'price', 'currency',
                'brand_name', 'compare_at_price', 'sale_ends_at', 'stock_status', 'rating_value', 'rating_count']) {
                if (data[field]) this.form.data[field] = data[field];
            }

            // التقسيط كما أعلنته صفحة المنتج: مزودون وعدد دفعات
            if (Array.isArray(data.installments) && data.installments.length) {
                this.form.data.installment_providers = data.installments.map((plan) => plan.provider);
                this.form.data.installment_count = data.installments[0].count || 4;
            }

            this.importedImages = data.image_urls || [];
            this.importUrl = '';

            // نجاح جزئي: البيانات وصلت والتحليل وحده تعثّر
            this.importError = data.warning || '';
        } catch {
            this.importError = 'تعذّر الاتصال بالخادم.';
        } finally {
            this.importing = false;
        }
    },

    get isService() {
        return this.form?.type === 'service';
    },

    get maxImages() {
        return this.isService ? 1 : 6;
    },

    get labels() {
        return this.isService
            ? { heading: this.form?.mode === 'edit' ? 'تعديل الخدمة' : 'إضافة خدمة جديدة',
                title: 'اسم الخدمة', titlePlaceholder: 'مثال: استشارة تسويقية',
                body: 'وصف الخدمة', bodyPlaceholder: 'اشرح خدمتك بالتفصيل…',
                images: 'صورة توضيحية', imagesHint: 'صورة واحدة — PNG أو JPG (الحد الأقصى 10MB)' }
            : { heading: this.form?.mode === 'edit' ? 'تعديل المنتج' : 'إضافة منتج جديد',
                title: 'اسم المنتج', titlePlaceholder: 'مثال: كيس حبيبات شوكولاتة 5 كجم',
                body: 'المميزات', bodyPlaceholder: 'اذكر المميزات والتفاصيل الكاملة للمنتج: ما هو، وطريقة استخدامه، والخامات، والمقاسات، ومحتويات العلبة…',
                images: 'صور المنتج (حتى 6 صور)', imagesHint: 'PNG أو JPG (الحد الأقصى 10MB لكل صورة)' };
    },

    previewImages(event) {
        // المرفوع يُقص على المساحة المتبقية لا على الحد الأقصى كاملاً
        const room = Math.max(0, this.maxImages - this.keptImages.length - this.importedImages.length);

        this.previews = Array.from(event.target.files)
            .slice(0, room)
            .map((file) => ({ name: file.name, url: URL.createObjectURL(file) }));
    },

    // ---------- التحديد الجماعي ----------
    get allSelected() {
        return this.pageIds.length > 0 && this.selected.length === this.pageIds.length;
    },

    toggleAll() {
        this.selected = this.allSelected ? [] : [...this.pageIds];
    },

    clearSelection() {
        this.selected = [];
        this.confirmingDelete = false;
    },

    // الحذف الجماعي لا رجعة فيه: الشريط نفسه يتحول إلى سؤال
    // بدل نافذة confirm() التي لا تذكر ما سيُحذف ولا يمكن تنسيقها
    confirmingDelete: false,

    setBulkAction(action) {
        this.$refs.bulkAction.value = action;
    },
}));

/* ==========================================================================
   3c. استيراد المنتجات من متجر
   ========================================================================== */
Alpine.data('storeImport', (config = {}) => ({
    open: !!config.open,
    keys: config.keys || [],
    cost: config.cost || 1,
    selected: [],
    progress: 8,

    init() {
        // المسح الناجح يفتح على «الكل محدد»: المستخدم جاء ليستورد لا ليستبعد
        this.selected = [...this.keys];

        this.$watch('open', (open) =>
            document.documentElement.classList.toggle('overflow-hidden', open));

        if (this.open) document.documentElement.classList.add('overflow-hidden');

        if (config.pollUuid) this.track(config.pollUuid);
    },

    get allSelected() {
        return this.keys.length > 0 && this.selected.length === this.keys.length;
    },

    toggleAll() {
        this.selected = this.allSelected ? [] : [...this.keys];
    },

    /**
     * المهمة تعمل في الطابور، والنتيجة تُرسم على الخادم.
     * لذلك ننتظر انتهاءها ثم نعيد تحميل الصفحة بنفس الرابط.
     */
    track(uuid) {
        window.pollJob(uuid, {
            onUpdate: (d) => { this.progress = Math.max(this.progress, d.progress ?? 0); },
            onDone: () => { this.progress = 100; setTimeout(() => window.location.reload(), 600); },
        });
    },
}));

/* ==========================================================================
   4. متابعة حالة مهمة التوليد
   ========================================================================== */
Alpine.data('jobTracker', (jobId, { redirectTo = null, resultKey = null } = {}) => ({
    status: 'queued',
    label: 'في الانتظار',
    progress: 6,
    failed: false,
    // انتهاء مهلة الاستطلاع ليس فشلاً: المهمة قد تكون في الطابور ونقاطها محجوزة.
    // قول «فشل وأُرجعت نقاطك» هنا كان كذباً حين يتوقف العامل أو يتأخر.
    timedOut: false,
    error: null,
    finished: false,

    init() {
        window.pollJob(jobId, {
            onUpdate: (d) => {
                this.status = d.status ?? this.status;
                this.label = d.label ?? this.label;
                // لا نسمح للشريط بالتراجع للخلف: التراجع يقرأ كخلل لا كتحديث
                this.progress = Math.max(this.progress, d.progress ?? 0);
            },
            onDone: (d) => {
                this.finished = true;
                this.timedOut = d.status === 'timeout';
                this.failed = ['failed', 'cancelled'].includes(d.status);
                this.error = d.error ?? null;

                if (this.failed || this.timedOut) return;

                this.progress = 100;

                const hasResult = !resultKey || (d.result?.[resultKey]?.length ?? 0) > 0;

                if (redirectTo && hasResult) {
                    setTimeout(() => (window.location.href = redirectTo), 900);
                }
            },
        });
    },
}));

/* ==========================================================================
   5. أسئلة هوية العلامة
   الإجابة الشحيحة («نبيع عطور»، «جودة عالية»، «الجميع») هي أول سبب لأوصاف
   عامة أو مختلَقة: النموذج يملأ الفراغ من عنده. لذلك ننبّه قبل التوليد،
   ونعرض تعبئة الحقول من صفحة المتجر نفسها.
   ========================================================================== */
const GENERIC_ADVANTAGES = ['جودة عالية', 'أسعار مناسبة', 'أسعار منافسة', 'خدمة ممتازة', 'أفضل جودة', 'أفضل الأسعار', 'جودة ممتازة'];
const VAGUE_AUDIENCES = ['الجميع', 'للجميع', 'الكل', 'كل الناس', 'جميع الفئات', 'كل الفئات'];

Alpine.data('brandAnswers', (config = {}) => ({
    type: config.type || 'good',
    questions: config.questions || {},
    f: { ...(config.fields || {}) },
    prefilling: false,
    prefillMessage: null,
    prefillError: false,
    filledFromStore: [],

    words(value) {
        return (value || '').trim().split(/\s+/).filter(Boolean).length;
    },

    get hints() {
        const advantages = (this.f.advantages || '').replace(/\s+/g, ' ').trim();
        const audience = (this.f.audience || '').trim();

        return {
            one_liner: this.f.one_liner && this.words(this.f.one_liner) < 5
                ? 'قصير جداً — اذكر الفئات الرئيسية التي تبيعها. الوصف لن يحوي أكثر مما تكتبه هنا.'
                : null,
            advantages: advantages && (this.words(advantages) < 3 || GENERIC_ADVANTAGES.includes(advantages))
                ? '«جودة عالية» تقولها كل المتاجر — ما الذي يميزك فعلاً؟ وكالة، توصيل، ضمان، فروع، سرعة التجهيز…'
                : null,
            audience: audience && (VAGUE_AUDIENCES.includes(audience) || this.words(audience) < 3)
                ? 'حدّد الفئة والمكان: «أصحاب المقاهي في الرياض» يُكتب له محتوى أدق بكثير من «الجميع».'
                : null,
        };
    },

    /** يملأ الفارغ فقط: ما كتبه المستخدم بنفسه لا يُستبدل بتخمين من الصفحة. */
    async prefill() {
        if (!this.f.store_url || this.prefilling) return;

        this.prefilling = true;
        this.prefillMessage = null;
        this.prefillError = false;

        try {
            const res = await fetch(config.prefillUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
                body: JSON.stringify({ store_url: this.f.store_url }),
            });

            const data = await res.json().catch(() => ({}));

            if (!res.ok) {
                this.prefillError = true;
                this.prefillMessage = res.status === 429
                    ? 'محاولات كثيرة متتالية — انتظر بضع دقائق ثم جرّب.'
                    : (data.message || 'تعذّرت التعبئة من الرابط.');
                return;
            }

            const found = Object.entries(data.fields || {}).filter(([, v]) => (v || '').trim());
            const filled = found.filter(([k]) => !(this.f[k] || '').trim()).map(([k]) => k);

            filled.forEach((k) => { this.f[k] = data.fields[k]; });
            this.filledFromStore = filled;

            const n = filled.length;
            const count = n === 1 ? 'حقلاً واحداً' : n === 2 ? 'حقلين' : `${n} حقول`;

            this.prefillMessage = n
                ? `عبّأنا ${count} من صفحة المتجر — راجعها قبل الحفظ.${found.length > n ? ' ما كتبته بنفسك لم نغيّره.' : ''}`
                : 'لم نغيّر شيئاً: كل ما وجدناه في الصفحة مكتوب عندك مسبقاً.';
        } catch {
            this.prefillError = true;
            this.prefillMessage = 'تعذّر الاتصال. تحقق من الإنترنت وجرّب مجدداً.';
        } finally {
            this.prefilling = false;
        }
    },
}));

/**
 * استطلاع حالة مهمة توليد حتى تكتمل.
 * فترة الاستطلاع تتباعد تدريجياً حتى لا نُغرق الخادم في المهام الطويلة.
 */
window.pollJob = function (jobId, { onUpdate, onDone, interval = 2000, timeout = 180000 } = {}) {
    const startedAt = Date.now();
    let wait = interval;

    const tick = async () => {
        if (Date.now() - startedAt > timeout) {
            onDone?.({ status: 'timeout' });
            return;
        }

        try {
            const res = await fetch(`/api/jobs/${jobId}`, {
                headers: { Accept: 'application/json' },
            });

            const data = await res.json();

            onUpdate?.(data);

            if (['completed', 'partial', 'failed', 'cancelled'].includes(data.status)) {
                onDone?.(data);
                return;
            }
        } catch {
            // خطأ شبكة مؤقت: نكمل المحاولة
        }

        wait = Math.min(wait * 1.15, 6000);
        setTimeout(tick, wait);
    };

    tick();
};

// محرر المحتوى ومركّب الشرائح في ملفه: أكبر من أن يسكن هنا
registerContentEditor(Alpine);
registerContentWriter(Alpine);
registerContentCalendar(Alpine);
registerImageStudio(Alpine);
registerCarouselEditor(Alpine);
registerOperations(Alpine);
registerVoiceover(Alpine);

window.Alpine = Alpine;
Alpine.start();

/* ==========================================================================
   5. تغذية راجعة فورية عند الإرسال
   أي زر إرسال يدخل حالة انتظار مباشرة، فلا يضغط المستخدم مرتين
   ولا يظن أن النقرة ضاعت.
   ========================================================================== */
document.addEventListener('submit', (event) => {
    const form = event.target;

    if (!(form instanceof HTMLFormElement) || form.dataset.noBusy !== undefined) return;

    // النماذج غير الصالحة لا تُرسل أصلاً، فلا نقفل أزرارها
    if (typeof form.checkValidity === 'function' && !form.checkValidity()) return;

    form.querySelectorAll('button[type="submit"], button:not([type])').forEach((button) => {
        button.dataset.busy = 'true';
        button.setAttribute('aria-busy', 'true');
    });
}, true);

// العودة بزر الرجوع تعيد الصفحة من الذاكرة بأزرارها مقفلة — نحررها
window.addEventListener('pageshow', (event) => {
    if (!event.persisted) return;

    document.querySelectorAll('[data-busy="true"]').forEach((button) => {
        delete button.dataset.busy;
        button.removeAttribute('aria-busy');
    });
});

/* ==========================================================================
   6. النماذج التي تُرسل ذاتياً عند تغيير فلتر
   ========================================================================== */
document.addEventListener('change', (event) => {
    const control = event.target;

    if (control?.dataset?.autoSubmit !== undefined) {
        control.form?.requestSubmit();
    }
});

/* ==========================================================================
   7. رسائل التحقق في المتصفح بالعربية
   رسالة المتصفح الأصلية تتبع لغة المتصفح لا لغة الصفحة: «Please fill out
   this field.» في منصة عربية (رصدها تدقيق المنافس P2). نكتبها نحن.
   ========================================================================== */
const validityMessage = (field) => {
    const v = field.validity;

    if (v.valueMissing) {
        return field.type === 'checkbox' ? 'يجب تحديد هذا الخيار للمتابعة.'
            : field.tagName === 'SELECT' ? 'اختر قيمة من القائمة.'
            : 'هذا الحقل مطلوب.';
    }
    if (v.typeMismatch) {
        return field.type === 'email' ? 'أدخل بريداً إلكترونياً صحيحاً.'
            : field.type === 'url' ? 'أدخل رابطاً صحيحاً يبدأ بـ https://'
            : 'القيمة غير صالحة.';
    }
    if (v.tooShort) return `أدخل ${field.minLength} أحرف على الأقل.`;
    if (v.tooLong) return `لا تتجاوز ${field.maxLength} حرفاً.`;
    if (v.rangeUnderflow) return `القيمة لا تقل عن ${field.min}.`;
    if (v.rangeOverflow) return `القيمة لا تتجاوز ${field.max}.`;
    if (v.stepMismatch) return 'القيمة غير مقبولة بهذه الدقة.';
    if (v.badInput) return 'أدخل رقماً صحيحاً.';
    if (v.patternMismatch) return field.title || 'الصيغة غير صحيحة.';

    return '';
};

document.addEventListener('invalid', (event) => {
    const field = event.target;

    if (!(field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement || field instanceof HTMLSelectElement)) return;

    // نمسح رسالتنا السابقة أولاً، وإلا بقي الحقل «غير صالح» بسببها وحدها
    field.setCustomValidity('');
    if (!field.validity.valid) field.setCustomValidity(validityMessage(field));
}, true);

['input', 'change'].forEach((type) => document.addEventListener(type, (event) => {
    if (typeof event.target?.setCustomValidity === 'function') event.target.setCustomValidity('');
}, true));
