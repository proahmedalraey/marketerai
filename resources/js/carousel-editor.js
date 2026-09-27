/* ==========================================================================
   نافذة «نظام الكاروسيل» في استوديو الصور
   تعديل حر للشرائح (النص وموضعه ولونه وحجمه، وصورة كل شريحة ووصفها، وشكل
   الكاروسيل والتاريخ) — لا يتغير شيء في الخادم حتى «حفظ» أو «حفظ كنسخة جديدة».
   الرسم نفسه في carousel-render.js (مشترك مع صفحة المحتوى).

   ما يحتاج نموذجاً (صورة جديدة، إعادة كتابة وصف الصورة) مهمة طابور تُستطلع هنا
   دون مغادرة النافذة (قاعدة §1: لا نموذج داخل طلب HTTP).
   ========================================================================== */

import {
    COMPOSE_SIZES, loadFont, renderSlide, resolveBox, textSizes, templateFor, hasParts, TEMPLATES, FALLBACK_FONT, arabicDigits,
} from './carousel-render';

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

const request = async (url, { method = 'GET', body } = {}) => {
    const response = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrf(),
            ...(body ? { 'Content-Type': 'application/json' } : {}),
        },
        body: body ? JSON.stringify(body) : undefined,
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        const first = Object.values(data.errors || {})[0];
        throw new Error(data.message && !first ? data.message : (Array.isArray(first) ? first[0] : data.message) || 'تعذّر إكمال الطلب.');
    }

    return data;
};

/** يستطلع مهمة طابور حتى تنتهي (حتى 4 دقائق للصور) ويعيد نتيجتها أو يرمي سبب فشلها. */
const pollJob = async (statusUrl, seconds = 240) => {
    for (let i = 0; i < seconds; i++) {
        await new Promise((resolve) => setTimeout(resolve, i < 3 ? 800 : 1500));

        const job = await request(statusUrl);

        if (['failed', 'cancelled'].includes(job.status)) throw new Error(job.error || 'تعذّر إكمال المهمة.');
        if (['completed', 'partial'].includes(job.status)) return job;
    }

    throw new Error('تستغرق المهمة أطول من المعتاد. ستظهر النتيجة في الاستوديو حين تكتمل.');
};

// ألوان سريعة للنص (مثل المرجع): تُكمَّل بلون العلامة الأساسي ومنتقي لون حر
const SWATCHES = ['#1D4ED8', '#E5484D', '#F59E0B', '#FFFFFF', '#111111', '#E5E7EB', '#F97316'];

let keyCounter = 0;

export default function registerCarouselEditor(Alpine) {
    Alpine.data('carouselEditor', () => ({
        open: false,
        loading: false,
        loadError: '',
        data: null,

        slides: [],
        design: { template: 'coffee_modern', date: 'none', quality: 'standard_1k' },
        images: {},
        thumbs: {},
        current: 0,

        editingText: false,
        drag: null,

        savedState: '',
        history: [],
        future: [],
        restoring: false,
        historyTimer: null,
        renderTimer: null,

        busy: '', // '' | 'save' | 'copy' | 'image' | 'visual'
        message: '',
        messageTone: 'success',
        confirmClose: false,
        changedServer: false, // حُفظ أو وُلّدت صورة: يُحدَّث الاستوديو عند الإغلاق

        swatches: SWATCHES,
        templateKeys: Object.keys(TEMPLATES),

        // ---------- الحالة ----------
        get slide() { return this.slides[this.current] || null; },
        get size() { return COMPOSE_SIZES[this.data?.ratio] || COMPOSE_SIZES['4:5']; },
        get ratioCss() { return `${this.size[0]} / ${this.size[1]}`; },
        get state() { return JSON.stringify([this.slides, this.design]); },
        get dirty() { return this.savedState !== '' && this.state !== this.savedState; },
        get canUndo() { return this.history.length > 1; },
        get canRedo() { return this.future.length > 0; },
        get box() { return this.slide ? resolveBox(this.design.template, this.slide.layout) : null; },
        get parts() { return this.slide ? hasParts(this.slide) : false; },
        get fontFamily() { return `"${this.data?.brand?.font || FALLBACK_FONT}", "${FALLBACK_FONT}", sans-serif`; },
        get tier() { return this.data?.tiers?.[this.design.quality] || { label: '', credits: 1 }; },
        get imageCostLabel() {
            const credits = this.tier.credits;

            return `${Number.isInteger(credits) ? credits : credits.toFixed(1)} ${credits === 1 ? 'نقطة' : 'نقاط'}`;
        },
        get hasImage() { return this.slide ? Boolean(this.images[this.slide.origin]) : false; },
        get datesAvailable() { return Boolean(this.data?.dates); },

        templateLabel(key) { return TEMPLATES[key]?.label ?? key; },
        number(n) { return arabicDigits(n); },
        hasIssue(slide) { return slide.origin !== null && (this.data?.errors || []).includes(slide.origin); },

        init() {
            this.$watch('state', () => {
                this.schedule();

                if (this.restoring) return;

                clearTimeout(this.historyTimer);
                this.historyTimer = setTimeout(() => this.commit(), 450);
            });

            this.$watch('current', () => { this.editingText = false; this.schedule(); });
            this.$watch('editingText', () => this.schedule());
        },

        // ---------- الفتح والتحميل ----------
        async openWith({ url, index = 0 }) {
            this.open = true;
            this.loading = true;
            this.loadError = '';
            this.message = '';
            this.confirmClose = false;
            this.changedServer = false;

            try {
                const data = await request(url);
                this.load(data, index);
                await loadFont(data.brand?.font);
                this.schedule();
            } catch (e) {
                this.loadError = e.message || 'تعذّر فتح الكاروسيل.';
            } finally {
                this.loading = false;
            }
        },

        load(data, index = null) {
            this.data = data;
            this.restoring = true;
            this.slides = (data.slides || []).map((slide) => ({
                key: `k${++keyCounter}`,
                origin: slide.origin,
                role: slide.role || 'pull',
                text: slide.text || '',
                kicker: slide.kicker ?? null,
                focal: slide.focal ?? null,
                tail: slide.tail ?? null,
                visual: slide.visual || '',
                layout: { ...(slide.layout || {}) },
                image: slide.image !== false,
            }));
            this.design = { ...data.design };
            this.images = data.images || {};
            this.thumbs = data.thumbs || {};
            this.current = Math.min(Math.max(index ?? this.current, 0), Math.max(this.slides.length - 1, 0));
            this.editingText = false;
            this.savedState = this.state;
            this.history = [this.state];
            this.future = [];
            this.$nextTick(() => { this.restoring = false; });
        },

        // ---------- تراجع / إعادة ----------
        commit() {
            if (this.history[this.history.length - 1] === this.state) return;

            this.history.push(this.state);
            if (this.history.length > 60) this.history.shift();
            this.future = [];
        },

        restore(snapshot) {
            this.restoring = true;
            const [slides, design] = JSON.parse(snapshot);
            this.slides = slides;
            this.design = design;
            this.current = Math.min(this.current, this.slides.length - 1);
            this.editingText = false;
            this.$nextTick(() => { this.restoring = false; });
        },

        undo() {
            clearTimeout(this.historyTimer);
            this.commit();
            if (!this.canUndo) return;

            this.future.push(this.history.pop());
            this.restore(this.history[this.history.length - 1]);
        },

        redo() {
            if (!this.canRedo) return;

            const next = this.future.pop();
            this.history.push(next);
            this.restore(next);
        },

        startOver() {
            if (!this.dirty) return;
            this.restore(this.savedState);
            this.history.push(this.savedState);
            this.flash('عادت الشرائح لآخر نسخة محفوظة.', 'info');
        },

        // ---------- الشرائح ----------
        select(index) {
            this.current = index;
        },

        addSlide() {
            if (this.slides.length >= (this.data?.maxSlides || 10)) {
                this.flash('الكاروسيل عشر شرائح على الأكثر.', 'error');

                return;
            }

            this.slides.push({ key: `k${++keyCounter}`, origin: null, role: 'pull', text: '', kicker: null, focal: null, tail: null, visual: '', layout: {}, image: true });
            this.current = this.slides.length - 1;
            this.$nextTick(() => this.startEditing());
        },

        removeSlide(index) {
            if (this.slides.length <= 3) {
                this.flash('الكاروسيل ثلاث شرائح على الأقل.', 'error');

                return;
            }

            this.slides.splice(index, 1);
            this.current = Math.min(this.current, this.slides.length - 1);
        },

        // ---------- النص على الشريحة ----------
        startEditing() {
            if (!this.slide) return;

            if (this.slide.layout.hidden) this.slide.layout.hidden = false;
            this.editingText = true;

            this.$nextTick(() => {
                this.$root.querySelectorAll('[data-edit-field]').forEach((el) => {
                    el.innerText = this.slide[el.dataset.editField] || '';
                });
                this.$root.querySelector('[data-edit-field]')?.focus();
            });
        },

        stopEditing() {
            this.editingText = false;
        },

        onText(field, el) {
            if (!this.slide) return;

            this.slide[field] = el.innerText.replace(/\s+\n/g, '\n').trim();

            // الهوك بطبقاته: النص الكامل مجموعها (الخادم يعيد بناءه بالمثل)
            if (this.parts) {
                this.slide.text = [this.slide.kicker, this.slide.focal, this.slide.tail].filter(Boolean).join(' ').trim();
            }
        },

        textStyle(part = 'body') {
            const box = this.box;
            if (!box) return '';

            const sizes = textSizes(this.design.template, box);
            const t = templateFor(this.design.template);
            const brand = this.data?.brand || {};
            const color = box.color
                || (part === 'focal' ? ({ accent: brand.accent || '#F59E0B', primary: brand.primary || '#6F4E37' }[t.focal] ?? t.focal) : t.text);
            const weight = part === 'focal' ? 800 : (part === 'kicker' ? 600 : (box.bold ? t.weight : 400));
            const leading = { body: 1.4, kicker: 1.35, focal: 1.18, tail: 1.35 }[part];

            return [
                `font-family: ${this.fontFamily}`,
                `font-size: ${(sizes[part] * 100).toFixed(3)}cqw`,
                `line-height: ${leading}`,
                `font-weight: ${weight}`,
                `font-style: ${box.italic ? 'italic' : 'normal'}`,
                `color: ${color}`,
            ].join('; ');
        },

        get boxStyle() {
            const box = this.box;
            if (!box) return '';

            return `left: ${box.x * 100}%; top: ${box.y * 100}%; width: ${box.w * 100}%`;
        },

        setLayout(changes) {
            if (!this.slide) return;
            this.slide.layout = { ...this.slide.layout, ...changes };
        },

        toggleBold() { this.setLayout({ bold: !this.box.bold }); },
        toggleItalic() { this.setLayout({ italic: !this.box.italic }); },
        setColor(hex) { this.setLayout({ color: (hex || '').toUpperCase() }); },
        resetLayout() {
            if (!this.slide) return;
            this.slide.layout = {};
        },
        hideText() {
            this.setLayout({ hidden: true });
            this.editingText = false;
        },

        /** سحب مربع النص (نقل) أو طرفيه (عرض) أو الدائرة (حجم الخط) — كسور من الشريحة. */
        startDrag(event, mode) {
            const frame = this.$refs.stage?.getBoundingClientRect();
            if (!frame || !this.slide) return;

            event.preventDefault();
            const box = this.box;
            this.drag = { mode, frame, startX: event.clientX, startY: event.clientY, box: { ...box } };

            const move = (e) => {
                const d = this.drag;
                if (!d) return;

                const dx = (e.clientX - d.startX) / d.frame.width;
                const dy = (e.clientY - d.startY) / d.frame.height;
                const clamp = (v, min, max) => Math.min(max, Math.max(min, v));
                const round = (v) => Math.round(v * 1000) / 1000;

                if (d.mode === 'move') {
                    this.setLayout({ x: round(clamp(d.box.x + dx, 0, 1 - d.box.w)), y: round(clamp(d.box.y + dy, 0, 0.92)) });
                } else if (d.mode === 'left') {
                    const x = clamp(d.box.x + dx, 0, d.box.x + d.box.w - 0.15);
                    this.setLayout({ x: round(x), w: round(d.box.x + d.box.w - x) });
                } else if (d.mode === 'right') {
                    this.setLayout({ w: round(clamp(d.box.w + dx, 0.15, 1 - d.box.x)) });
                } else if (d.mode === 'scale') {
                    // لليسار أو للأسفل = أكبر (الدائرة في الزاوية السفلية اليسرى)
                    this.setLayout({ scale: round(clamp(d.box.scale * (1 + (dy - dx) * 1.6), 0.4, 3)) });
                }
            };

            const end = () => {
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', end);
                this.drag = null;
                this.commit();
            };

            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', end);
        },

        // ---------- الرسم ----------
        schedule() {
            clearTimeout(this.renderTimer);
            this.renderTimer = setTimeout(() => this.$nextTick(() => this.renderAll()), 90);
        },

        slideOptions(slide, index, extra = {}) {
            return {
                slide,
                index,
                total: this.slides.length,
                image: this.images[slide.origin],
                brand: this.data?.brand || {},
                design: this.design,
                dates: this.data?.dates,
                size: this.size,
                ...extra,
            };
        },

        async renderAll() {
            if (!this.open || !this.data) return;

            const preview = this.$refs.preview;

            if (preview && this.slide) {
                await renderSlide(preview, this.slideOptions(this.slide, this.current, { withText: !this.editingText }));
            }

            // مصغّرات الشرائح وأشكال الكاروسيل بحجم صغير: الرسم كله كسور من العرض
            const small = [Math.round(this.size[0] / 5), Math.round(this.size[1] / 5)];

            for (const [index, slide] of this.slides.entries()) {
                const canvas = this.$root.querySelector(`canvas[data-strip="${slide.key}"]`);
                if (canvas) await renderSlide(canvas, this.slideOptions(slide, index, { size: small }));
            }

            const sample = this.slides[0];

            if (sample) {
                for (const key of this.templateKeys) {
                    const canvas = this.$root.querySelector(`canvas[data-template="${key}"]`);
                    if (canvas) await renderSlide(canvas, this.slideOptions(sample, 0, { size: small, design: { ...this.design, template: key } }));
                }
            }
        },

        // ---------- الحفظ ----------
        payload() {
            return {
                slides: this.slides.map((slide) => ({
                    origin: slide.origin,
                    role: slide.role,
                    text: slide.text,
                    kicker: slide.kicker,
                    focal: slide.focal,
                    tail: slide.tail,
                    visual: slide.visual,
                    layout: slide.layout,
                    image: slide.image,
                })),
                design: this.design,
            };
        },

        async save() {
            if (this.busy) return;

            this.busy = 'save';
            this.editingText = false;

            try {
                const result = await request(this.data.urls.save, { method: 'PUT', body: this.payload() });
                this.load(result.carousel, this.current);
                this.changedServer = true;
                this.flash(result.message, result.needsReview ? 'info' : 'success');

                return true;
            } catch (e) {
                this.flash(e.message, 'error');

                return false;
            } finally {
                this.busy = '';
                this.schedule();
            }
        },

        async saveCopy() {
            if (this.busy) return;

            this.busy = 'copy';
            this.editingText = false;

            try {
                const result = await request(this.data.urls.copy, { method: 'POST', body: this.payload() });
                this.load(result.carousel, this.current);
                this.changedServer = true;
                this.flash(result.message, 'success');
            } catch (e) {
                this.flash(e.message, 'error');
            } finally {
                this.busy = '';
                this.schedule();
            }
        },

        // ---------- صورة الشريحة ووصفها ----------
        /** وصف صورة من نص الشريحة بالذكاء (نفس «تحسين الوصف» في الاستوديو، مجاني افتراضياً). */
        async rewriteVisual() {
            if (this.busy || !this.slide) return;

            const text = (this.parts ? [this.slide.kicker, this.slide.focal, this.slide.tail].filter(Boolean).join(' ') : this.slide.text).trim();

            if (text.length < 3) {
                this.flash('اكتب نص الشريحة أولاً.', 'error');

                return;
            }

            const key = this.slide.key;
            this.busy = 'visual';

            try {
                const started = await request(this.data.urls.enhance, {
                    method: 'POST',
                    body: { prompt: text.slice(0, 1500), aspect_ratio: this.data.ratio, product_id: this.data.productId, use_brand_identity: true },
                });
                const job = await pollJob(started.status_url, 120);
                const target = this.slides.find((slide) => slide.key === key);

                if (target && job.result?.prompt) {
                    target.visual = job.result.prompt;
                    this.flash('كُتب وصف جديد للصورة — راجعه ثم «أعد إنشاء الصورة».', 'success');
                }
            } catch (e) {
                this.flash(e.message, 'error');
            } finally {
                this.busy = '';
            }
        },

        /** صورة جديدة لهذه الشريحة بوصفها الحالي: يُحفظ النص أولاً، ثم مهمة طابور تُستطلع هنا. */
        async regenerateImage() {
            if (this.busy || !this.slide) return;

            if (this.dirty || this.slide.origin === null) {
                const saved = await this.save();
                if (!saved) return;
            }

            const index = this.current;
            this.busy = 'image';
            this.flash('تُولَّد صورة الشريحة… تبقى النافذة مفتوحة حتى تصل.', 'info');

            try {
                const started = await request(this.data.urls.regenerate, {
                    method: 'POST',
                    body: { stage: 'slide', index, quality: this.design.quality },
                });
                await pollJob(started.status_url);

                const fresh = await request(this.data.urls.data);
                this.load(fresh, index);
                this.changedServer = true;
                this.flash('وصلت صورة الشريحة الجديدة.', 'success');
            } catch (e) {
                this.flash(e.message, 'error');
            } finally {
                this.busy = '';
                this.schedule();
            }
        },

        // ---------- الإغلاق ----------
        requestClose() {
            if (this.busy === 'image' || this.busy === 'save' || this.busy === 'copy') return;

            if (this.dirty) {
                this.confirmClose = true;

                return;
            }

            this.close();
        },

        close() {
            this.open = false;
            this.confirmClose = false;
            this.editingText = false;

            // حُفظ شيء أو وصلت صورة: الاستوديو (والخطة) يعرضان الجديد
            if (this.changedServer) window.location.reload();
        },

        flash(text, tone = 'success') {
            this.message = text;
            this.messageTone = tone;
        },
    }));
}
