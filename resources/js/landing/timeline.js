/*
 * «كيف يعمل»: خط برتقالي يُرسم خلف الخطوات مع التمرير (strokeDashoffset)،
 * وكل خطوة تضيء حين يصل خط القراءة إلى دائرتها.
 * على الشاشات العريضة يتموّج الخط بين الدوائر، وعلى الجوال يبقى مستقيمًا بجانبها.
 */
const clamp = (value, min = 0, max = 1) => Math.min(max, Math.max(min, value));

// خط القراءة: حيث تستقر العين عادة أثناء التمرير
const READING_LINE = 0.62;

export function timeline(root) {
    const svg = root.querySelector('[data-timeline-svg]');
    const track = svg.querySelector('[data-track]');
    const draw = svg.querySelector('[data-draw]');
    const steps = [...root.querySelectorAll('[data-step]')];
    const dots = steps.map((step) => step.querySelector('[data-dot]'));

    root.querySelectorAll('[data-timeline-fallback]').forEach((line) => {
        line.style.display = 'none';
    });
    svg.classList.remove('hidden');
    root.classList.add('is-drawing');

    let top = 0;
    let total = 0;
    let points = [];
    let target = 0;
    let shown = 0;
    let raf = 0;

    const measure = () => {
        const box = root.getBoundingClientRect();
        svg.setAttribute('viewBox', `0 0 ${box.width} ${box.height}`);

        points = dots.map((dot) => {
            const r = dot.getBoundingClientRect();
            return [r.left - box.left + r.width / 2, r.top - box.top + r.height / 2];
        });

        // كل مقطع ينحني لجهة معاكسة للذي قبله، والانحناء أقل من المسافة بين الدائرة والبطاقة
        const bend = window.matchMedia('(min-width: 768px)').matches ? 44 : 0;
        let d = `M ${points[0][0]} ${points[0][1]}`;

        for (let i = 1; i < points.length; i++) {
            const [x0, y0] = points[i - 1];
            const [x1, y1] = points[i];
            const side = i % 2 ? bend : -bend;
            const third = (y1 - y0) / 3;
            d += ` C ${x0 + side} ${y0 + third}, ${x1 + side} ${y1 - third}, ${x1} ${y1}`;
        }

        track.setAttribute('d', d);
        draw.setAttribute('d', d);
        total = draw.getTotalLength();
        draw.style.strokeDasharray = `${total}`;

        top = box.top + window.scrollY;
    };

    const render = (progress) => {
        draw.style.strokeDashoffset = `${total * (1 - progress)}`;

        const reached = points[0][1] + progress * (points.at(-1)[1] - points[0][1]);
        steps.forEach((step, i) => {
            step.classList.toggle('is-lit', progress > 0 && reached >= points[i][1] - 1);
        });
    };

    const loop = () => {
        raf = 0;
        shown += (target - shown) * 0.16;
        if (Math.abs(target - shown) < 0.0005) {
            shown = target;
        }

        render(shown);

        if (shown !== target) {
            raf = requestAnimationFrame(loop);
        }
    };

    const onScroll = () => {
        const reading = window.scrollY + window.innerHeight * READING_LINE - top;
        target = clamp((reading - points[0][1]) / (points.at(-1)[1] - points[0][1]));

        if (!raf) {
            raf = requestAnimationFrame(loop);
        }
    };

    const refresh = () => {
        measure();
        onScroll();
    };

    measure();
    onScroll();

    window.addEventListener('scroll', onScroll, { passive: true });
    new ResizeObserver(refresh).observe(root);
    document.fonts?.ready.then(refresh);
}
