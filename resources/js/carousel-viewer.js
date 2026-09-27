/* ==========================================================================
   عارض الكاروسيل في استوديو الصور
   يعرض الشرائح كما ستُنشر: النص مركّباً فوق الصورة بتصميم الكاروسيل المحفوظ
   (نفس رسم carousel-render.js)، أو الصور وحدها. تنقّل بالأسهم ولوحة المفاتيح
   والسحب، وتنزيل الشريحة أو كل الشرائح، وانتقال لتعديلها في «نظام الكاروسيل».
   قراءة فقط: لا يغيّر شيئاً في الخادم.
   ========================================================================== */

import { COMPOSE_SIZES, loadFont, renderSlide, hasParts, arabicDigits } from './carousel-render';

export default function registerCarouselViewer(Alpine) {
    Alpine.data('carouselViewer', () => ({
        open: false,
        loading: false,
        error: '',
        data: null,
        url: '',
        index: 0,
        mode: 'composed', // 'composed' بالنص كما سيُنشر | 'photo' الصورة وحدها
        downloading: false,
        pointerX: null,
        renderToken: 0,

        get slides() { return this.data?.slides || []; },
        get total() { return this.slides.length; },
        get slide() { return this.slides[this.index] || null; },
        get size() { return COMPOSE_SIZES[this.data?.ratio] || COMPOSE_SIZES['4:5']; },
        get ratioCss() { return `${this.size[0]} / ${this.size[1]}`; },
        get ready() { return Object.keys(this.data?.images || {}).length; },
        get photoUrl() { return this.slide ? (this.data.images || {})[this.slide.origin] || null : null; },
        get counter() { return `${arabicDigits(this.index + 1)} / ${arabicDigits(this.total)}`; },

        slideText(slide) {
            if (!slide) return '';

            return hasParts(slide) ? [slide.kicker, slide.focal, slide.tail].filter(Boolean).join(' ') : slide.text;
        },

        hasPhoto(slide) {
            return Boolean(slide && (this.data?.images || {})[slide.origin]);
        },

        number(n) { return arabicDigits(n); },

        async openWith({ url, index = 0 }) {
            this.url = url;
            this.open = true;
            this.loading = true;
            this.error = '';
            this.index = 0;

            try {
                const response = await fetch(url, { headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error('fetch');

                this.data = await response.json();
                this.index = Math.min(Math.max(index, 0), Math.max(this.total - 1, 0));
                await loadFont(this.data.brand?.font);
            } catch (e) {
                this.error = 'تعذّر فتح الكاروسيل. حدّث الصفحة وحاول مرة أخرى.';
            } finally {
                this.loading = false;
            }

            this.$nextTick(() => {
                this.renderAll();
                this.$refs.close?.focus();
            });
        },

        close() {
            this.open = false;
        },

        go(index) {
            if (index < 0 || index >= this.total) return;

            this.index = index;
            this.$nextTick(() => {
                this.renderStage();
                this.$root.querySelector(`[data-thumb="${index}"]`)?.scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'smooth' });
            });
        },

        next() { this.go(this.index + 1); },
        prev() { this.go(this.index - 1); },

        setMode(mode) {
            this.mode = mode;
            this.$nextTick(() => this.renderAll());
        },

        // «اسحب ←» على الشرائح: السحب لليسار أو السهم الأيسر = الشريحة التالية
        onKey(event) {
            if (!this.open || this.loading) return;

            if (event.key === 'ArrowLeft') { event.preventDefault(); this.next(); }
            if (event.key === 'ArrowRight') { event.preventDefault(); this.prev(); }
        },

        pointerStart(event) {
            this.pointerX = event.clientX;
        },

        pointerEnd(event) {
            if (this.pointerX === null) return;

            const dx = event.clientX - this.pointerX;
            this.pointerX = null;

            if (dx < -40) this.next();
            if (dx > 40) this.prev();
        },

        options(slide, index, size) {
            return {
                slide,
                index,
                total: this.total,
                image: (this.data.images || {})[slide.origin],
                brand: this.data.brand || {},
                design: this.data.design || {},
                dates: this.data.dates,
                size,
            };
        },

        async renderStage() {
            const canvas = this.$refs.stage;
            if (!canvas || !this.slide || this.mode !== 'composed') return;

            const token = ++this.renderToken;
            const index = this.index;
            await renderSlide(canvas, this.options(this.slide, index, this.size));

            // تنقّل سريع: رسم متأخر لشريحة سابقة لا يُغطي الحالية
            if (token !== this.renderToken) await renderSlide(canvas, this.options(this.slide, this.index, this.size));
        },

        async renderAll() {
            if (!this.open || !this.data) return;

            await this.renderStage();

            if (this.mode !== 'composed') return;

            const small = [Math.round(this.size[0] / 6), Math.round(this.size[1] / 6)];

            for (const [index, slide] of this.slides.entries()) {
                const canvas = this.$root.querySelector(`canvas[data-thumb-canvas="${index}"]`);
                if (canvas) await renderSlide(canvas, this.options(slide, index, small));
            }
        },

        // ---------- التنزيل: الشريحة كما ستُنشر (بالنص) بحجمها الكامل ----------
        async blobFor(index) {
            const canvas = document.createElement('canvas');
            await renderSlide(canvas, this.options(this.slides[index], index, this.size));

            return new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
        },

        save(blob, index) {
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = `${this.data.brand?.slug || 'carousel'}-${this.data.id}-${index + 1}.png`;
            link.click();
            setTimeout(() => URL.revokeObjectURL(link.href), 1500);
        },

        async download(index = this.index) {
            if (this.downloading) return;

            this.downloading = true;

            try {
                const blob = await this.blobFor(index);
                if (blob) this.save(blob, index);
            } finally {
                this.downloading = false;
            }
        },

        async downloadAll() {
            if (this.downloading) return;

            this.downloading = true;

            try {
                for (const index of this.slides.keys()) {
                    const blob = await this.blobFor(index);
                    if (blob) this.save(blob, index);
                    await new Promise((resolve) => setTimeout(resolve, 350));
                }
            } finally {
                this.downloading = false;
            }
        },

        edit() {
            const detail = { url: this.url, index: this.index };
            this.close();
            window.dispatchEvent(new CustomEvent('carousel-editor:open', { detail }));
        },
    }));
}
