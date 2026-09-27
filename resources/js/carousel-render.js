/* ==========================================================================
   رسم شرائح الكاروسيل — مشترك بين صفحة المحتوى ونافذة «نظام الكاروسيل»
   النص العربي يُرسم هنا فوق الصورة لا في نموذج الصور (قرار §9-1 في خطة
   التدقيق): عربية سليمة في كل مرة، بخطوط العلامة وألوانها، والتعديل مجاني.

   التصميم يُحفظ مع المحتوى: body.design = { template, date } و slide.layout =
   { x, y, w, scale, color, bold, italic, hidden } (كسور من عرض الشريحة وارتفاعها)،
   و slide.image = false لشريحة بلا صورة (خلفية بلون العلامة).
   ========================================================================== */

export const COMPOSE_SIZES = { '4:5': [1080, 1350], '1:1': [1080, 1080], '9:16': [1080, 1920], '3:4': [1080, 1440], '16:9': [1920, 1080] };
export const FALLBACK_FONT = 'IBM Plex Sans Arabic';

export const arabicDigits = (n) => String(n).replace(/\d/g, (d) => '٠١٢٣٤٥٦٧٨٩'[d]);

const fontRequests = {};

// خط العلامة من Google Fonts. إن لم يوجد، يرسم المتصفح بالخط الاحتياطي الذي يلي اسمه
export const loadFont = (family) => fontRequests[family || FALLBACK_FONT] ??= (async () => {
    if (family && !document.querySelector(`link[data-font="${family}"]`)) {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.dataset.font = family;
        link.href = `https://fonts.googleapis.com/css2?family=${encodeURIComponent(family).replace(/%20/g, '+')}:wght@400;600;700;800&display=swap`;
        document.head.appendChild(link);
    }

    await Promise.race([
        Promise.all([400, 600, 700, 800].map((w) => document.fonts.load(`${w} 64px "${family || FALLBACK_FONT}"`).catch(() => null))),
        new Promise((resolve) => setTimeout(resolve, 4000)),
    ]);

    await document.fonts.load(`700 64px "${FALLBACK_FONT}"`).catch(() => null);
})();

const imageRequests = {};

export const loadImage = (url) => {
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

export const shade = (hex, amount) => {
    const value = parseInt((hex || '#6F4E37').replace('#', '').padEnd(6, '0').slice(0, 6), 16);
    const channel = (shift) => Math.max(0, Math.min(255, Math.round(((value >> shift) & 255) * (1 + amount))));

    return `rgb(${channel(16)}, ${channel(8)}, ${channel(0)})`;
};

const rgba = (hex, alpha) => {
    const value = parseInt((hex || '#000000').replace('#', '').padEnd(6, '0').slice(0, 6), 16);

    return `rgba(${(value >> 16) & 255}, ${(value >> 8) & 255}, ${value & 255}, ${alpha})`;
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

const vertical = (ctx, W, H, from, to, stops) => {
    const gradient = ctx.createLinearGradient(0, H * from, 0, H * to);
    stops.forEach(([at, color]) => gradient.addColorStop(at, color));
    ctx.fillStyle = gradient;
    ctx.fillRect(0, H * from, W, H * (to - from));
};

/*
 * أشكال الكاروسيل الستة. box: موضع مربع النص الافتراضي (كسور)، size: حجم النص
 * نسبةً لعرض الشريحة، panel: لوحة خلف النص (لونها ودرجة شفافيتها).
 */
export const TEMPLATES = {
    coffee_modern: {
        label: 'كوفي عصري',
        box: { x: 0.067, y: 0.17, w: 0.866 },
        size: 0.068, weight: 700, text: '#FFFFFF', focal: 'accent', chrome: 'rgba(255,255,255,0.92)',
        scrim: (ctx, W, H) => {
            vertical(ctx, W, H, 0, 0.66, [[0, 'rgba(0,0,0,0.66)'], [1, 'rgba(0,0,0,0)']]);
            vertical(ctx, W, H, 0.8, 1, [[0, 'rgba(0,0,0,0)'], [1, 'rgba(0,0,0,0.5)']]);
        },
    },
    editorial: {
        label: 'تحريري',
        box: { x: 0.11, y: 0.62, w: 0.78 },
        size: 0.056, weight: 700, text: '#1C1815', focal: 'primary', chrome: 'rgba(255,255,255,0.95)',
        panel: { color: '#FFFFFF', alpha: 0.92, pad: 0.045, radius: 0.03 },
        scrim: (ctx, W, H) => {
            vertical(ctx, W, H, 0, 0.3, [[0, 'rgba(0,0,0,0.35)'], [1, 'rgba(0,0,0,0)']]);
            vertical(ctx, W, H, 0.75, 1, [[0, 'rgba(0,0,0,0)'], [1, 'rgba(0,0,0,0.35)']]);
        },
    },
    bold_dark: {
        label: 'جريء داكن',
        box: { x: 0.067, y: 0.34, w: 0.866 },
        size: 0.082, weight: 800, text: '#FFFFFF', focal: 'accent', chrome: 'rgba(255,255,255,0.9)',
        scrim: (ctx, W, H) => {
            ctx.fillStyle = 'rgba(8,6,5,0.58)';
            ctx.fillRect(0, 0, W, H);
            vertical(ctx, W, H, 0.7, 1, [[0, 'rgba(0,0,0,0)'], [1, 'rgba(0,0,0,0.55)']]);
        },
    },
    calm: {
        label: 'هادئ',
        box: { x: 0.09, y: 0.16, w: 0.82 },
        size: 0.058, weight: 600, text: '#2B2522', focal: 'primary', chrome: 'rgba(43,37,34,0.8)',
        scrim: (ctx, W, H) => {
            vertical(ctx, W, H, 0, 0.62, [[0, 'rgba(255,252,247,0.9)'], [0.55, 'rgba(255,252,247,0.55)'], [1, 'rgba(255,252,247,0)']]);
        },
    },
    heritage: {
        label: 'تراثي',
        box: { x: 0.09, y: 0.56, w: 0.82 },
        size: 0.064, weight: 700, text: '#F6E9CF', focal: '#D6A94C', chrome: 'rgba(246,233,207,0.9)', ornament: '#D6A94C',
        scrim: (ctx, W, H) => {
            ctx.fillStyle = 'rgba(62,36,14,0.34)';
            ctx.fillRect(0, 0, W, H);
            vertical(ctx, W, H, 0.4, 1, [[0, 'rgba(40,22,8,0)'], [1, 'rgba(40,22,8,0.85)']]);
        },
    },
    vibrant: {
        label: 'حيوي',
        box: { x: 0.08, y: 0.6, w: 0.84 },
        size: 0.064, weight: 800, text: '#FFFFFF', focal: '#FFFFFF', chrome: 'rgba(255,255,255,0.95)',
        panel: { color: 'primary', alpha: 0.93, pad: 0.04, radius: 0.02 },
        scrim: (ctx, W, H) => {
            vertical(ctx, W, H, 0, 0.25, [[0, 'rgba(0,0,0,0.3)'], [1, 'rgba(0,0,0,0)']]);
        },
    },
};

export const templateFor = (key) => TEMPLATES[key] || TEMPLATES.coffee_modern;

const colorOf = (value, brand) => {
    if (value === 'accent') return brand.accent || '#F59E0B';
    if (value === 'primary') return brand.primary || '#6F4E37';

    return value;
};

/** مربع النص بعد دمج تعديلات التاجر (موضع، حجم، لون) مع افتراضي الشكل. */
export const resolveBox = (template, layout = {}) => {
    const t = templateFor(template);
    const l = layout || {};

    return {
        x: typeof l.x === 'number' ? l.x : t.box.x,
        y: typeof l.y === 'number' ? l.y : t.box.y,
        w: typeof l.w === 'number' ? l.w : t.box.w,
        scale: typeof l.scale === 'number' ? l.scale : 1,
        color: l.color || null,
        bold: l.bold !== false,
        italic: l.italic === true,
        hidden: l.hidden === true,
    };
};

/** شريحة هوك بطبقاتها الثلاث (تمهيد · عبارة محورية · تكملة). */
export const hasParts = (slide) => slide.role === 'hook' && slide.focal !== null && slide.focal !== undefined && slide.focal !== '';

/**
 * أحجام نصوص الشريحة كسوراً من عرضها — تستعملها نافذة التعديل لتطابق طبقة
 * التحرير (HTML بوحدات cqw) ما يرسمه الكانفس.
 */
export const textSizes = (template, box) => {
    const t = templateFor(template);

    return {
        body: t.size * box.scale,
        kicker: 0.045 * box.scale,
        focal: 0.11 * box.scale,
        tail: 0.056 * box.scale,
    };
};

/**
 * يرسم شريحة كاملة على canvas.
 *
 * @param {HTMLCanvasElement} canvas
 * @param {object} options { slide, index, total, image, brand, design, dates, size, withText }
 */
export async function renderSlide(canvas, {
    slide, index, total, image = null, brand = {}, design = {}, dates = null, size = COMPOSE_SIZES['4:5'], withText = true,
}) {
    if (!slide || !canvas) return;

    const [W, H] = size;
    canvas.width = W;
    canvas.height = H;

    const ctx = canvas.getContext('2d');
    const t = templateFor(design.template);
    const family = brand.font || FALLBACK_FONT;
    const primary = brand.primary || '#6F4E37';
    const accent = brand.accent || '#F59E0B';
    const font = (weight, px, italic = false) => `${italic ? 'italic ' : ''}${weight} ${Math.round(px)}px "${family}", "${FALLBACK_FONT}", sans-serif`;

    // الخلفية: صورة الشريحة، أو تدرّج بلون العلامة (شريحة بلا صورة أو قبل توليدها)
    const background = slide.image === false ? null : await loadImage(image);

    if (background) {
        drawCover(ctx, background, W, H);
    } else {
        const gradient = ctx.createLinearGradient(0, 0, W, H);
        gradient.addColorStop(0, primary);
        gradient.addColorStop(1, shade(primary, -0.4));
        ctx.fillStyle = gradient;
        ctx.fillRect(0, 0, W, H);
    }

    // ظلال تجعل النص مقروءاً على أي صورة — لكل شكل ظلاله
    t.scrim(ctx, W, H);

    const M = Math.round(W * 0.067);

    // الشعار أعلى اليمين على شارة فاتحة: شعار داكن على صورة داكنة يختفي
    const logo = await loadImage(brand.logo);

    if (logo) {
        const height = Math.round(W * 0.075);
        const width = Math.min(height * logo.naturalWidth / logo.naturalHeight, W * 0.3);
        const pad = Math.round(W * 0.016);

        ctx.fillStyle = 'rgba(255,255,255,0.92)';
        ctx.beginPath();
        if (ctx.roundRect) ctx.roundRect(W - M - width - pad, M - pad, width + pad * 2, height + pad * 2, pad * 1.5);
        else ctx.rect(W - M - width - pad, M - pad, width + pad * 2, height + pad * 2);
        ctx.fill();
        ctx.drawImage(logo, W - M - width, M, width, height);
    }

    // التاريخ على الشريحة (أعلى اليسار): الشهر، أو اليوم والشهر
    const dateText = design.date && design.date !== 'none' && dates ? dates[design.date] : null;

    if (dateText) {
        ctx.direction = 'rtl';
        ctx.font = font(600, W * 0.032);
        const label = arabicDigits(dateText);
        const width = ctx.measureText(label).width;
        const pad = W * 0.018;

        ctx.fillStyle = 'rgba(0,0,0,0.38)';
        ctx.beginPath();
        if (ctx.roundRect) ctx.roundRect(M - pad, M - pad * 0.6, width + pad * 2, W * 0.032 + pad * 1.4, W * 0.03);
        else ctx.rect(M - pad, M - pad * 0.6, width + pad * 2, W * 0.032 + pad * 1.4);
        ctx.fill();

        ctx.fillStyle = '#FFFFFF';
        ctx.textAlign = 'left';
        ctx.textBaseline = 'top';
        ctx.fillText(label, M, M + pad * 0.1);
    }

    const box = resolveBox(design.template, slide.layout);

    if (!box.hidden) {
        drawText(ctx, { slide, box, t, W, H, font, primary, accent, brand, withText });
    }

    // مؤشر الشريحة، و«اسحب» على كل شريحة إلا الأخيرة
    const footer = H - M - W * 0.04;
    ctx.direction = 'rtl';
    ctx.textBaseline = 'top';
    ctx.font = font(600, W * 0.035);
    ctx.fillStyle = t.chrome;
    ctx.textAlign = 'right';
    ctx.fillText(`${arabicDigits(index + 1)}/${arabicDigits(total)}`, W - M, footer);

    if (index < total - 1) {
        ctx.textAlign = 'left';
        ctx.fillText('اسحب ←', M, footer);
    }
}

/** نص الشريحة داخل مربعه: قياس أولاً (لرسم لوحة الشكل خلفه) ثم الرسم. */
function drawText(ctx, { slide, box, t, W, H, font, primary, accent, brand, withText }) {
    ctx.direction = 'rtl';
    ctx.textAlign = 'right';
    ctx.textBaseline = 'top';

    const right = (box.x + box.w) * W;
    const maxWidth = box.w * W;
    const top = box.y * H;
    const base = box.color || t.text;
    const focalColor = box.color || colorOf(t.focal, brand);
    const weight = box.bold ? t.weight : 400;
    const sizes = textSizes(null, box);
    const blocks = [];

    if (hasParts(slide)) {
        // الهوك ثلاث طبقات: تمهيد صغير، عبارة محورية كبيرة بلون العلامة، تكملة
        ctx.font = font(600, W * sizes.kicker, box.italic);
        blocks.push({ lines: wrapLines(ctx, slide.kicker, maxWidth), px: W * sizes.kicker, weight: 600, color: base, leading: 1.35, gap: W * 0.01 });

        let px = W * sizes.focal;
        let lines;
        do {
            ctx.font = font(800, px, box.italic);
            lines = wrapLines(ctx, slide.focal, maxWidth);
            if (lines.length <= 2) break;
            px -= 6;
        } while (px > W * 0.06 * box.scale);
        blocks.push({ lines, px, weight: 800, color: focalColor, leading: 1.18, gap: W * 0.01 });

        ctx.font = font(weight, W * sizes.tail, box.italic);
        blocks.push({ lines: wrapLines(ctx, slide.tail, maxWidth), px: W * sizes.tail, weight, color: base, leading: 1.35, gap: 0 });
    } else {
        // النص يصغر حتى يتسع لما بقي من الشريحة فوق التذييل
        const limit = H * 0.86 - top;
        let px = W * t.size * box.scale;
        let lines;
        do {
            ctx.font = font(weight, px, box.italic);
            lines = wrapLines(ctx, slide.text, maxWidth);
            if (lines.length * px * 1.4 <= limit) break;
            px -= 3;
        } while (px > W * 0.03);
        blocks.push({ lines, px, weight, color: base, leading: 1.4, gap: 0 });
    }

    const height = blocks.reduce((sum, b) => sum + b.lines.length * b.px * b.leading + b.gap, 0);

    if (t.panel) {
        const pad = W * t.panel.pad;
        ctx.fillStyle = rgba(colorOf(t.panel.color, brand), t.panel.alpha);
        ctx.beginPath();
        const rect = [box.x * W - pad, top - pad, maxWidth + pad * 2, height + pad * 2];
        if (ctx.roundRect) ctx.roundRect(...rect, W * t.panel.radius);
        else ctx.rect(...rect);
        ctx.fill();
    }

    if (t.ornament && withText) {
        ctx.fillStyle = t.ornament;
        ctx.fillRect(right - W * 0.16, top - W * 0.035, W * 0.16, Math.max(4, W * 0.006));
    }

    if (!withText) return;

    let y = top;

    for (const block of blocks) {
        ctx.font = font(block.weight, block.px, box.italic);
        ctx.fillStyle = block.color;
        block.lines.forEach((line) => { ctx.fillText(line, right, y); y += block.px * block.leading; });
        y += block.gap;
    }

    // خط مميز تحت الهوك بلون العلامة
    if (hasParts(slide)) {
        ctx.fillStyle = focalColor;
        ctx.fillRect(right - W * 0.13, y + W * 0.02, W * 0.13, Math.max(6, W * 0.009));
    }
}
