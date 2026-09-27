/* ==========================================================================
   محرر المحتوى ومركّب الشرائح
   النص العربي يُرسم فوق الصورة هنا لا في نموذج الصور (قرار §9-1 في خطة
   التدقيق): عربية سليمة في كل مرة، بخطوط العلامة وألوانها، وتعديل النص
   يعيد الرسم فوراً ومجاناً بدل صورة جديدة مدفوعة.
   الترتيب والحذف والإضافة هنا أيضاً، وتُحفظ مع النص بزر واحد.
   ========================================================================== */

import { COMPOSE_SIZES, loadFont, renderSlide, hasParts as slideHasParts } from './carousel-render';

// كانت هنا؛ تبقى مصدَّرة لمن يستوردها من هذا الملف
export { arabicDigits, wrapLines } from './carousel-render';

export default function registerContentEditor(Alpine) {
    Alpine.data('contentEditor', (config = {}) => ({
        caption: config.caption || '',
        max: config.captionMax || 2200,
        slides: (config.slides || []).map((slide, index) => ({
            key: `s${index}`,
            origin: index,
            role: slide.role || 'pull',
            text: slide.text || '',
            kicker: slide.kicker ?? null,
            focal: slide.focal ?? null,
            tail: slide.tail ?? null,
            // تصميم «نظام الكاروسيل»: يُرسم هنا ويبقى في الخادم عند الحفظ
            layout: slide.layout ?? null,
            image: slide.image ?? true,
        })),
        design: config.design || {},
        dates: config.dates || null,
        images: config.images || {},
        errors: config.errors || [],
        roles: config.roles || {},
        brand: config.brand || {},
        urls: config.urls || {},
        costs: config.costs || {},
        ratio: config.ratio || '4:5',
        quality: config.quality || 'standard_1k',
        snapshot: '',
        counter: 0,
        copied: false,
        notice: '',
        renderTimer: null,

        get used() { return this.caption.length; },
        get over() { return this.used > this.max; },
        get size() { return COMPOSE_SIZES[this.ratio] || COMPOSE_SIZES['4:5']; },
        get ratioCss() { return `${this.size[0]} / ${this.size[1]}`; },
        get state() { return JSON.stringify([this.caption, this.slides]); },
        // تعديل غير محفوظ: إعادة الكتابة وتوليد الصورة يعملان على النص المحفوظ، فننبّه قبلهما
        get dirty() { return this.snapshot !== '' && this.state !== this.snapshot; },
        get imageCost() { return this.costs[this.quality] ?? 1; },

        async init() {
            this.snapshot = this.state;
            this.$watch('state', () => this.schedule());
            await loadFont(this.brand.font);
            this.schedule();
        },

        hasParts(slide) {
            return slideHasParts(slide);
        },

        joined(slide) {
            return this.hasParts(slide)
                ? [slide.kicker, slide.focal, slide.tail].filter(Boolean).join(' ').trim()
                : slide.text;
        },

        roleLabel(role) { return this.roles[role]?.label ?? role; },
        roleDirective(role) { return this.roles[role]?.directive ?? ''; },
        hasError(slide) { return slide.origin !== null && this.errors.includes(slide.origin); },

        // ---------- الترتيب والإضافة والحذف: في المتصفح، تُحفظ مع النص ----------
        move(index, delta) {
            const target = index + delta;
            if (target < 0 || target >= this.slides.length) return;
            const [slide] = this.slides.splice(index, 1);
            this.slides.splice(target, 0, slide);
        },

        remove(index) {
            if (this.slides.length <= 3) {
                this.notice = 'الكاروسيل ثلاث شرائح على الأقل.';
                return;
            }
            this.slides.splice(index, 1);
        },

        add() {
            const key = `n${++this.counter}`;
            this.slides.push({ key, origin: null, role: 'pull', text: '', kicker: null, focal: null, tail: null, layout: null, image: true });
            this.$nextTick(() => this.$root.querySelector(`[data-slide-text="${key}"]`)?.focus());
        },

        // ---------- ما يحتاج النموذج: نماذج خارج نموذج الحفظ ----------
        submitExternal(ref, fields) {
            if (this.dirty) {
                this.notice = 'احفظ تعديلاتك أولاً: إعادة الكتابة والصورة الجديدة تعملان على النص المحفوظ.';
                return;
            }

            const form = this.$refs[ref];
            Object.entries(fields).forEach(([name, value]) => { form.elements[name].value = value ?? ''; });
            form.requestSubmit();
        },

        rewrite(index, direction = '', note = '') {
            const slide = this.slides[index];
            if (slide.origin === null) return;
            this.$refs.rewriteForm.action = this.urls.rewrite.replace('__INDEX__', slide.origin);
            this.submitExternal('rewriteForm', { direction, note });
        },

        regenerateImage(index) {
            const slide = this.slides[index];
            if (slide.origin === null) return;
            this.submitExternal('imageForm', { stage: 'slide', index: slide.origin, quality: this.quality });
        },

        // ---------- الرسم (carousel-render.js: نفس رسم نافذة «نظام الكاروسيل») ----------
        schedule() {
            clearTimeout(this.renderTimer);
            this.renderTimer = setTimeout(() => this.$nextTick(() => this.renderAll()), 120);
        },

        canvasFor(slide) {
            return this.$root.querySelector(`canvas[data-slide-key="${slide.key}"]`);
        },

        async renderAll() {
            for (const [index, slide] of this.slides.entries()) {
                const canvas = this.canvasFor(slide);
                if (canvas) await this.render(canvas, index);
            }
        },

        async render(canvas, index) {
            const slide = this.slides[index];
            if (!slide) return;

            await renderSlide(canvas, {
                slide,
                index,
                total: this.slides.length,
                image: this.images[slide.origin],
                brand: this.brand,
                design: this.design,
                dates: this.dates,
                size: this.size,
            });
        },

        // ---------- التنزيل والنسخ ----------
        async download(index) {
            const slide = this.slides[index];
            const canvas = this.canvasFor(slide);
            if (!canvas) return;

            await this.render(canvas, index);

            try {
                canvas.toBlob((blob) => {
                    if (!blob) return;
                    const link = document.createElement('a');
                    link.href = URL.createObjectURL(blob);
                    link.download = `${this.brand.slug || 'slide'}-${index + 1}.png`;
                    link.click();
                    setTimeout(() => URL.revokeObjectURL(link.href), 1500);
                }, 'image/png');
            } catch {
                this.notice = 'تعذّر التنزيل: الصورة محفوظة على خادم لا يسمح برسمها في المتصفح.';
            }
        },

        async downloadAll() {
            for (const index of this.slides.keys()) {
                await this.download(index);
                await new Promise((resolve) => setTimeout(resolve, 400));
            }
        },

        async copySlides() {
            const text = this.slides.map((slide, index) => `${index + 1}. ${this.joined(slide)}`).join('\n');

            try {
                await navigator.clipboard.writeText(text);
                this.copied = true;
                setTimeout(() => (this.copied = false), 2000);
            } catch {
                this.notice = 'تعذّر النسخ. حدّد النص يدوياً.';
            }
        },
    }));
}
