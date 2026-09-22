/* ==========================================================================
   محرر المحتوى ومركّب الشرائح
   النص العربي يُرسم فوق الصورة هنا لا في نموذج الصور (قرار §9-1 في خطة
   التدقيق): عربية سليمة في كل مرة، بخطوط العلامة وألوانها، وتعديل النص
   يعيد الرسم فوراً ومجاناً بدل صورة جديدة مدفوعة.
   الترتيب والحذف والإضافة هنا أيضاً، وتُحفظ مع النص بزر واحد.
   ========================================================================== */

const COMPOSE_SIZES = { '4:5': [1080, 1350], '1:1': [1080, 1080], '9:16': [1080, 1920], '3:4': [1080, 1440], '16:9': [1920, 1080] };
const FALLBACK_FONT = 'IBM Plex Sans Arabic';

export const arabicDigits = (n) => String(n).replace(/\d/g, (d) => '٠١٢٣٤٥٦٧٨٩'[d]);

const fontRequests = {};

// خط العلامة من Google Fonts. إن لم يوجد، يرسم المتصفح بالخط الاحتياطي الذي يلي اسمه
const loadFont = (family) => fontRequests[family || FALLBACK_FONT] ??= (async () => {
    if (family && !document.querySelector(`link[data-font="${family}"]`)) {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.dataset.font = family;
        link.href = `https://fonts.googleapis.com/css2?family=${encodeURIComponent(family).replace(/%20/g, '+')}:wght@400;600;700;800&display=swap`;
        document.head.appendChild(link);
    }

    await Promise.race([
        Promise.all([600, 700, 800].map((w) => document.fonts.load(`${w} 64px "${family || FALLBACK_FONT}"`).catch(() => null))),
        new Promise((resolve) => setTimeout(resolve, 4000)),
    ]);

    await document.fonts.load(`700 64px "${FALLBACK_FONT}"`).catch(() => null);
})();

const imageRequests = {};

const loadImage = (url) => {
    if (!url) return Promise.resolve(null);

    return imageRequests[url] ??= new Promise((resolve) => {
        const img = new Image();
        // صورة من نطاق آخر بلا CORS تجعل التنزيل مستحيلاً؛ نطلبها بـ CORS صراحة
        if (new URL(url, window.location.href).origin !== window.location.origin) img.crossOrigin = 'anonymous';
        img.onload = () => resolve(img);
        img.onerror = () => resolve(null);
        img.src = url;
    });
};

const drawCover = (ctx, img, W, H) => {
    const scale = Math.max(W / img.naturalWidth, H / img.naturalHeight);
    const w = img.naturalWidth * scale;
    const h = img.naturalHeight * scale;
    ctx.drawImage(img, (W - w) / 2, (H - h) / 2, w, h);
};

const shade = (hex, amount) => {
    const value = parseInt((hex || '#6F4E37').replace('#', '').padEnd(6, '0').slice(0, 6), 16);
    const channel = (shift) => Math.max(0, Math.min(255, Math.round(((value >> shift) & 255) * (1 + amount))));

    return `rgb(${channel(16)}, ${channel(8)}, ${channel(0)})`;
};

// التفاف بالكلمات لا بالحروف: العربية المتصلة لا تُكسر وسط الكلمة
export const wrapLines = (ctx, text, maxWidth) => {
    const lines = [];
    let line = '';

    for (const word of (text || '').trim().split(/\s+/).filter(Boolean)) {
        const candidate = line ? `${line} ${word}` : word;

        if (line && ctx.measureText(candidate).width > maxWidth) {
            lines.push(line);
            line = word;
        } else {
            line = candidate;
        }
    }

    if (line) lines.push(line);

    return lines;
};

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
        })),
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
            return slide.role === 'hook' && slide.focal !== null;
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
            this.slides.push({ key, origin: null, role: 'pull', text: '', kicker: null, focal: null, tail: null });
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

        // ---------- الرسم ----------
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

            const [W, H] = this.size;
            canvas.width = W;
            canvas.height = H;

            const ctx = canvas.getContext('2d');
            const family = this.brand.font || FALLBACK_FONT;
            const font = (weight, size) => `${weight} ${Math.round(size)}px "${family}", "${FALLBACK_FONT}", sans-serif`;
            const primary = this.brand.primary || '#6F4E37';
            const accent = this.brand.accent || '#F59E0B';

            // الخلفية: صورة الشريحة، أو تدرّج بلون العلامة حتى تُولَّد
            const background = await loadImage(this.images[slide.origin]);

            if (background) {
                drawCover(ctx, background, W, H);
            } else {
                const gradient = ctx.createLinearGradient(0, 0, W, H);
                gradient.addColorStop(0, primary);
                gradient.addColorStop(1, shade(primary, -0.4));
                ctx.fillStyle = gradient;
                ctx.fillRect(0, 0, W, H);
            }

            // ظلال تجعل النص الأبيض مقروءاً على أي صورة
            const top = ctx.createLinearGradient(0, 0, 0, H * 0.66);
            top.addColorStop(0, 'rgba(0,0,0,0.66)');
            top.addColorStop(1, 'rgba(0,0,0,0)');
            ctx.fillStyle = top;
            ctx.fillRect(0, 0, W, H * 0.66);

            const bottom = ctx.createLinearGradient(0, H * 0.8, 0, H);
            bottom.addColorStop(0, 'rgba(0,0,0,0)');
            bottom.addColorStop(1, 'rgba(0,0,0,0.5)');
            ctx.fillStyle = bottom;
            ctx.fillRect(0, H * 0.8, W, H * 0.2);

            const M = Math.round(W * 0.067);
            let y = M;

            const logo = await loadImage(this.brand.logo);

            if (logo) {
                const height = Math.round(W * 0.075);
                const width = Math.min(height * logo.naturalWidth / logo.naturalHeight, W * 0.3);
                const pad = Math.round(W * 0.016);

                // شارة فاتحة خلف الشعار: شعار داكن على صورة داكنة يختفي، والصور تتغير
                ctx.fillStyle = 'rgba(255,255,255,0.92)';
                ctx.beginPath();
                if (ctx.roundRect) ctx.roundRect(W - M - width - pad, y - pad, width + pad * 2, height + pad * 2, pad * 1.5);
                else ctx.rect(W - M - width - pad, y - pad, width + pad * 2, height + pad * 2);
                ctx.fill();

                ctx.drawImage(logo, W - M - width, y, width, height);
                y += height + pad + M * 0.6;
            } else {
                y += M * 0.3;
            }

            ctx.direction = 'rtl';
            ctx.textAlign = 'right';
            ctx.textBaseline = 'top';

            const x = W - M;
            const maxWidth = W - 2 * M;
            const limit = H * 0.62;

            const draw = (lines, size, weight, color, leading) => {
                ctx.font = font(weight, size);
                ctx.fillStyle = color;
                lines.forEach((line) => { ctx.fillText(line, x, y); y += size * leading; });
            };

            if (this.hasParts(slide)) {
                // الهوك ثلاث طبقات: تمهيد صغير، عبارة محورية كبيرة بلون العلامة، تكملة
                ctx.font = font(600, W * 0.045);
                draw(wrapLines(ctx, slide.kicker, maxWidth), W * 0.045, 600, '#fff', 1.35);
                y += W * 0.01;

                let size = W * 0.12;
                let lines;
                do {
                    ctx.font = font(800, size);
                    lines = wrapLines(ctx, slide.focal, maxWidth);
                    if (lines.length <= 2) break;
                    size -= 6;
                } while (size > W * 0.07);
                draw(lines, size, 800, accent, 1.18);
                y += W * 0.01;

                ctx.font = font(700, W * 0.058);
                draw(wrapLines(ctx, slide.tail, maxWidth), W * 0.058, 700, '#fff', 1.35);

                ctx.fillStyle = accent;
                ctx.fillRect(x - W * 0.13, y + W * 0.02, W * 0.13, Math.max(6, W * 0.009));
            } else {
                let size = W * 0.068;
                let lines;
                do {
                    ctx.font = font(700, size);
                    lines = wrapLines(ctx, slide.text, maxWidth);
                    if (lines.length * size * 1.4 <= limit - y) break;
                    size -= 4;
                } while (size > W * 0.04);
                draw(lines, size, 700, '#fff', 1.4);
            }

            // مؤشر الشريحة، و«اسحب» على كل شريحة إلا الأخيرة
            const footer = H - M - W * 0.04;
            ctx.font = font(600, W * 0.035);
            ctx.fillStyle = 'rgba(255,255,255,0.92)';
            ctx.textAlign = 'right';
            ctx.fillText(`${arabicDigits(index + 1)}/${arabicDigits(this.slides.length)}`, W - M, footer);

            if (index < this.slides.length - 1) {
                ctx.textAlign = 'left';
                ctx.fillText('اسحب ←', M, footer);
            }
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
