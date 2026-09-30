/*
 * «كل أدوات التسويق في منصة واحدة»: خمس أدوات في صف كبير أسفل الشاشة ترتفع بموجة،
 * تتجمع في الوسط وتنقلب الخلفية من داكن إلى فاتح، ثم تستقر كل أداة في مكانها داخل الجملة.
 *
 * كل شيء مشتق من تقدّم التمرير وحده (0 → 1)، فالرجوع للأعلى يعكس المشهد كاملًا.
 * كل أداة تسكن أصلًا داخل فراغها في الجملة، والتحويل ينقلها منه وإليه — لا نسخ ولا عناصر عائمة.
 */
const clamp = (value, min = 0, max = 1) => Math.min(max, Math.max(min, value));
const range = (value, start, end) => clamp((value - start) / (end - start));
const mix = (from, to, t) => from + (to - from) * t;
const ease = (t) => (t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2);

export function assembly(section) {
    const stage = section.querySelector('[data-assembly-stage]');
    const header = section.querySelector('[data-assembly-header]');
    const chips = [...section.querySelectorAll('[data-assembly-chip]')];
    const holes = chips.map((chip) => chip.parentElement);
    const labels = chips.map((chip) => chip.querySelector('[data-assembly-label]'));
    const segments = [...section.querySelectorAll('[data-assembly-seg]')];
    const outro = section.querySelector('[data-assembly-outro]');

    section.classList.add('is-animated');

    let width = 0;
    let height = 0;
    let tile = 0;   // ضلع الأداة في الصف الكبير
    let hole = 0;   // ضلع فراغها داخل الجملة
    let top = 0;
    let runway = 1;
    let homes = [];
    let row = [];
    let compact = [];

    let target = 0;
    let shown = 0;
    let raf = 0;
    let forceEnd = false;

    const measure = () => {
        const box = stage.getBoundingClientRect();
        width = box.width;
        height = box.height;
        hole = holes[0].getBoundingClientRect().width;

        const pad = width < 640 ? 16 : 32;
        const gap = width < 640 ? 8 : 16;
        tile = Math.min(200, (width - 2 * pad - 4 * gap) / 5);
        section.style.setProperty('--tile', `${tile}px`);

        homes = holes.map((el) => {
            const r = el.getBoundingClientRect();
            return { x: r.left - box.left + r.width / 2, y: r.top - box.top + r.height / 2 };
        });

        // الأداة الأولى في أقصى اليمين كما يبدأ السطر العربي، فتطير كل واحدة إلى فراغها المقابل
        const rowWidth = 5 * tile + 4 * gap;
        row = chips.map((_, i) => ({
            x: (width + rowWidth) / 2 - tile / 2 - i * (tile + gap),
            y: height - pad - tile / 2,
        }));

        const compactGap = hole * 0.35;
        const compactWidth = 5 * hole + 4 * compactGap;
        compact = chips.map((_, i) => ({
            x: (width + compactWidth) / 2 - hole / 2 - i * (hole + compactGap),
            y: height / 2,
        }));

        top = section.getBoundingClientRect().top + window.scrollY;
        runway = Math.max(1, section.offsetHeight - height);
    };

    const render = (p) => {
        // ١) العنوان الافتتاحي يرتفع ويختفي في النصف الأول من صعود الصف
        const intro = range(p, 0, 0.12);
        header.style.opacity = `${1 - intro}`;
        header.style.transform = `translateY(${-50 * intro}px)`;

        // ٢) الخلفية تنقلب في منتصف التجمّع
        section.classList.toggle('is-dark', p < 0.375);

        const rise = range(p, 0, 0.25);
        const gather = ease(range(p, 0.25, 0.5));
        const fly = range(p, 0.5, 0.72);
        const lift = -0.3 * height;

        chips.forEach((chip, i) => {
            // كل أداة تلحق بالتي قبلها: موجة من اليمين إلى اليسار
            let x = row[i].x;
            let y = row[i].y + lift * ease(range(rise, i * 0.1, i * 0.1 + 0.5));
            let size = tile;

            if (p > 0.25) {
                x = mix(row[i].x, compact[i].x, gather);
                y = mix(row[i].y + lift, compact[i].y, gather);
                size = mix(tile, hole, gather);
            }

            // ٣) مسار على شكل L: نزولًا إلى سطرها أولًا ثم أفقيًا إلى فراغها
            if (p > 0.5) {
                x = mix(compact[i].x, homes[i].x, ease(range(fly, 0.5, 1)));
                y = mix(compact[i].y, homes[i].y, ease(range(fly, 0, 0.5)));
                size = hole;
            }

            chip.style.transform = `translate(${x - homes[i].x}px, ${y - homes[i].y}px) scale(${size / tile})`;
            labels[i].style.opacity = `${clamp((size - 2 * hole) / (tile - 2 * hole))}`;
        });

        // ٤) مقاطع الجملة تظهر بترتيب القراءة، ثم السعر والزر
        segments.forEach((segment, i) => {
            segment.style.opacity = `${range(p, 0.72 + i * 0.028, 0.75 + i * 0.028)}`;
        });

        const end = range(p, 0.9, 0.97);
        outro.style.opacity = `${end}`;
        outro.style.transform = `translateY(${(1 - end) * 16}px)`;
        outro.style.visibility = end > 0 ? 'visible' : 'hidden';
    };

    const loop = () => {
        raf = 0;
        const goal = forceEnd ? 1 : target;

        // تنعيم خفيف يلحق بالتمرير بدل أن يقفز معه
        shown += (goal - shown) * 0.14;
        if (Math.abs(goal - shown) < 0.0005) {
            shown = goal;
        }

        render(shown);

        if (shown !== goal) {
            raf = requestAnimationFrame(loop);
        }
    };

    const kick = () => {
        if (!raf) {
            raf = requestAnimationFrame(loop);
        }
    };

    const onScroll = () => {
        target = clamp((window.scrollY - top) / runway);
        kick();
    };

    const refresh = () => {
        measure();
        onScroll();
        render(shown);
    };

    // من يصل بلوحة المفاتيح إلى زر الاشتراك يرى المشهد مكتملًا، لا زرًا شفافًا
    stage.addEventListener('focusin', (event) => {
        if (event.target.matches(':focus-visible')) {
            forceEnd = true;
            kick();
        }
    });
    stage.addEventListener('focusout', (event) => {
        if (!stage.contains(event.relatedTarget)) {
            forceEnd = false;
            kick();
        }
    });

    measure();
    target = clamp((window.scrollY - top) / runway);
    shown = target;
    render(shown);

    window.addEventListener('scroll', onScroll, { passive: true });
    new ResizeObserver(refresh).observe(stage);

    // خط Cairo يغيّر مواضع الفراغات داخل الجملة حين يكتمل تحميله
    document.fonts?.ready.then(refresh);
}
