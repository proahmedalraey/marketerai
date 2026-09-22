/* ==========================================================================
   صفحة «كتابة المحتوى»
   الهدف ← المنتج ← المنصة ← شكل المحتوى وحقله التابع ← اللغة واللهجة.
   كل حقل يظهر حين يصير له معنى: الشكل بعد المنصة، واللهجة مع العربية،
   وسكربت التصوير مع أشكال الفيديو والستوري.

   الدفعة تعيش في المتصفح حتى تُرسل: تبقى إن حُدّثت الصفحة أو فشل التحقق،
   وتُفرَّغ حين يؤكد الخادم أنه استلمها.
   ========================================================================== */

const FIELD_NAMES = {
    goal: 'الهدف من المحتوى',
    product: 'المنتج',
    platform: 'المنصة',
    format: 'شكل المحتوى',
    option: null, // اسمه من الشكل نفسه: «مدة الفيديو»، «عدد التغريدات في الثريد»…
    language: 'اللغة',
    dialect: 'اللهجة',
};

export default function registerContentWriter(Alpine) {
    Alpine.data('contentWriter', (config = {}) => ({
        goals: config.goals || {},
        products: config.products || {},
        platforms: config.platforms || {},
        formats: config.formats || {},
        languages: config.languages || {},
        dialects: config.dialects || {},
        balance: config.balance ?? 0,
        maxBatch: config.maxBatch || 10,

        form: {
            goal: '',
            product: config.defaultProduct || '',
            platform: '',
            format: '',
            option: '',
            language: 'ar',
            dialect: config.defaultDialect || '',
            filming: false,
        },

        batch: [],
        outgoing: [],
        mode: 'single',
        showErrors: false,
        notice: '',
        announcement: '',

        init() {
            this.restoreBatch();

            if (config.clearBatch) this.clearBatch();

            // فشل تحقق الخادم في إرسال مفرد: يعود النموذج كما كان
            if (config.initial) this.restoreForm(config.initial);
        },

        // ---------- الحقول التابعة ----------
        get platformFormats() {
            return this.platforms[this.form.platform]?.formats || [];
        },

        get formatMeta() {
            return this.formats[this.form.format] || null;
        },

        get optionMeta() {
            return this.formatMeta?.option || null;
        },

        get needsDialect() {
            return this.form.language === 'ar';
        },

        get canFilm() {
            return !!this.formatMeta?.filming;
        },

        /** المنصة الجديدة قد لا تدعم الشكل المختار: نفرغه بدل أن نرسل ما لا يصح. */
        choosePlatform() {
            if (!this.platformFormats.includes(this.form.format)) {
                this.form.format = '';
                this.form.option = '';
            }

            this.syncFormat();
        },

        chooseFormat(value) {
            this.form.format = value;
            this.syncFormat();
        },

        syncFormat() {
            const choices = (this.optionMeta?.choices || []).map((choice) => choice.value);

            if (!choices.includes(this.form.option)) this.form.option = '';
            if (!this.canFilm) this.form.filming = false;
        },

        // ---------- التسميات ----------
        formatLabel(key) {
            return this.formats[key]?.label || '';
        },

        choiceLabel(format, value) {
            return (this.formats[format]?.option?.choices || []).find((choice) => choice.value === value)?.label || '';
        },

        productLabel(key) {
            return this.products[key] || '';
        },

        // ---------- الاكتمال ----------
        get missing() {
            const missing = [];

            if (!this.form.goal) missing.push('goal');
            if (!this.form.product) missing.push('product');
            if (!this.form.platform) missing.push('platform');
            if (this.form.platform && !this.form.format) missing.push('format');
            if (this.optionMeta && !this.form.option) missing.push('option');
            if (!this.form.language) missing.push('language');
            if (this.needsDialect && !this.form.dialect) missing.push('dialect');

            return missing;
        },

        get complete() {
            return this.missing.length === 0;
        },

        invalid(field) {
            return this.showErrors && this.missing.includes(field);
        },

        fieldName(field) {
            return field === 'option' ? (this.optionMeta?.label || '') : FIELD_NAMES[field];
        },

        /** يُظهر الناقص بعينه ويأخذ التركيز إلى أوله، بدل زر معطّل لا يقول لماذا. */
        ensureComplete() {
            if (this.complete) {
                this.showErrors = false;

                return true;
            }

            this.showErrors = true;
            this.announcement = 'أكمل: ' + this.missing.map((field) => this.fieldName(field)).join('، ');

            this.$nextTick(() => {
                const first = this.$root.querySelector('[data-invalid="true"]');

                first?.scrollIntoView({ block: 'center', behavior: 'smooth' });
                first?.querySelector('select, input')?.focus({ preventScroll: true });
            });

            return false;
        },

        snapshot() {
            return {
                goal: this.form.goal,
                product: this.form.product,
                platform: this.form.platform,
                format: this.form.format,
                option: this.optionMeta ? this.form.option : '',
                language: this.form.language,
                dialect: this.needsDialect ? this.form.dialect : '',
                filming: this.canFilm && !!this.form.filming,
            };
        },

        // ---------- الدفعة ----------
        addToBatch() {
            if (!this.ensureComplete()) return;

            if (this.batch.length >= this.maxBatch) {
                this.notice = `الدفعة تتسع لـ ${this.maxBatch} إعدادات على الأكثر. أنشئها أولاً ثم ابدأ دفعة جديدة.`;

                return;
            }

            this.batch.push({ key: `${Date.now()}-${Math.random().toString(36).slice(2, 7)}`, ...this.snapshot() });
            this.notice = '';
            this.announcement = `أُضيف الإعداد رقم ${this.batch.length} إلى الدفعة.`;
            this.persist();
        },

        removeFromBatch(index) {
            this.batch.splice(index, 1);
            this.persist();
        },

        clearBatch() {
            this.batch = [];
            this.persist();
        },

        costOf(item) {
            return this.formats[item.format]?.cost ?? 1;
        },

        get batchCost() {
            return this.batch.reduce((sum, item) => sum + this.costOf(item), 0);
        },

        get singleCost() {
            return this.formatMeta?.cost ?? null;
        },

        get batchTooExpensive() {
            return this.batchCost > this.balance;
        },

        // ---------- الإرسال ----------
        generateSingle() {
            if (!this.ensureComplete()) return;

            this.send('single', [this.snapshot()]);
        },

        generateBatch() {
            if (this.batch.length) this.send('batch', this.batch);
        },

        /**
         * الحقول المخفية تُبنى من الإعدادات ثم يُرسل النموذج بعد رسمها.
         * requestSubmit لا submit: يطلق حدث الإرسال فتدخل الأزرار حالة الانتظار.
         */
        send(mode, items) {
            this.mode = mode;
            this.outgoing = items.map((item) => ({
                ...item,
                product_id: item.product === 'none' ? '' : item.product,
            }));

            this.$nextTick(() => this.$refs.form.requestSubmit(
                mode === 'batch' ? this.$refs.batchButton : this.$refs.singleButton,
            ));
        },

        // ---------- الحفظ في المتصفح ----------
        persist() {
            try {
                localStorage.setItem(config.storageKey, JSON.stringify(this.batch));
            } catch {
                // تصفح خاص أو تخزين محجوب: تبقى الدفعة ما بقيت الصفحة مفتوحة
            }
        },

        restoreBatch() {
            try {
                const saved = JSON.parse(localStorage.getItem(config.storageKey) || '[]');

                // إعداد حُفظ ثم تغيّرت قوائم المنصة (منتج حُذف، شكل أُزيل) لا يُرسل
                this.batch = Array.isArray(saved)
                    ? saved.filter((item) => this.goals[item.goal] && this.products[item.product]
                        && (this.platforms[item.platform]?.formats || []).includes(item.format))
                        .slice(0, this.maxBatch)
                    : [];
            } catch {
                this.batch = [];
            }
        },

        restoreForm(initial) {
            const product = initial.product_id ? String(initial.product_id) : 'none';

            Object.assign(this.form, {
                goal: this.goals[initial.goal] ? initial.goal : '',
                product: this.products[product] ? product : '',
                platform: this.platforms[initial.platform] ? initial.platform : '',
                format: initial.format || '',
                option: initial.option || '',
                language: this.languages[initial.language] ? initial.language : 'ar',
                dialect: this.dialects[initial.dialect] ? initial.dialect : this.form.dialect,
                filming: ['1', 'true', true].includes(initial.filming),
            });

            this.choosePlatform();
        },
    }));

    /**
     * «محتوى جاهز للنشر»: محتوى واحد في كل مرة، وتنقّل بالأرقام كما عند المنافس.
     */
    Alpine.data('draftPager', (total = 0, start = 0) => ({
        page: start,
        total,

        go(page) {
            this.page = Math.max(0, Math.min(this.total - 1, page));
            this.$root.scrollIntoView({ block: 'start', behavior: 'smooth' });
        },

        /** نافذة من خمسة أرقام حول الحالي، فلا يطول الشريط مع ثلاثين مسودة. */
        get pages() {
            const size = Math.min(5, this.total);
            const start = Math.max(0, Math.min(this.page - 2, this.total - size));

            return Array.from({ length: size }, (_, i) => start + i);
        },
    }));

    /**
     * متابعة مهام الكتابة الجارية: كلها معاً، ثم تحديث الصفحة لتظهر النتائج.
     */
    Alpine.data('writerJobs', (uuids = []) => ({
        progress: 6,
        done: 0,
        total: uuids.length,
        timedOut: false,

        init() {
            const states = {};

            uuids.forEach((uuid) => window.pollJob(uuid, {
                onUpdate: (d) => {
                    states[uuid] = d.progress ?? 0;
                    const sum = Object.values(states).reduce((a, b) => a + b, 0);
                    this.progress = Math.max(this.progress, Math.round(sum / this.total));
                },
                onDone: (d) => {
                    if (d.status === 'timeout') this.timedOut = true;

                    states[uuid] = 100;
                    this.done++;

                    if (this.done === this.total && !this.timedOut) {
                        this.progress = 100;
                        setTimeout(() => window.location.reload(), 700);
                    }
                },
            }));
        },
    }));
}
