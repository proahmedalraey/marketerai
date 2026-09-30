/*
 * جولة المنتج: شاشات تتبدّل على ساعة واحدة، ولكل شاشة شريط يمتلئ خلال مدتها بالضبط.
 * حلقة rAF واحدة تقود الشريط والانتقال معًا؛ لو انفصلا (setInterval + انتقال CSS)
 * لكذب الشريط على موعد الشاشة التالية أول ما يُخفى التبويب أو يُوقف العرض.
 */
const DURATION = 6000;

export function showcase(root, { reduce }) {
    const deck = root.querySelector('[data-deck]');
    const slides = [...root.querySelectorAll('[data-slide]')];
    const ticks = [...root.querySelectorAll('[data-tick]')];
    const fills = ticks.map((tick) => tick.querySelector('[data-fill]'));
    const toggle = root.querySelector('[data-toggle]');

    let current = 0;
    let elapsed = 0;
    let last = 0;
    let raf = 0;
    let paused = reduce; // تقليل الحركة: لا تشغيل تلقائي، والأزرار تبقى تعمل
    let hovering = false;
    let focused = false;
    let onScreen = false;

    const show = (index) => {
        current = (index + slides.length) % slides.length;
        elapsed = 0;

        slides.forEach((slide, i) => {
            slide.classList.toggle('opacity-0', i !== current);
            slide.setAttribute('aria-hidden', i === current ? 'false' : 'true');
        });

        ticks.forEach((tick, i) => {
            tick.setAttribute('aria-current', i === current ? 'true' : 'false');
            fills[i].style.width = i < current ? '100%' : '0%';
        });
    };

    const frame = (now) => {
        // بعد عودة من تبويب مخفي لا نقفز شاشات: الوقت المحتسب لا يتجاوز إطارًا واحدًا طويلًا
        elapsed += Math.min(now - last, 100);
        last = now;

        const progress = Math.min(1, elapsed / DURATION);
        fills[current].style.width = `${progress * 100}%`;

        if (progress >= 1) {
            show(current + 1);
        }

        raf = requestAnimationFrame(frame);
    };

    // لا تدور الساعة إلا والجولة ظاهرة ولا أحد يتفاعل معها
    const sync = () => {
        const running = !paused && !hovering && !focused && onScreen && !document.hidden;

        if (running && !raf) {
            last = performance.now();
            raf = requestAnimationFrame(frame);
        } else if (!running && raf) {
            cancelAnimationFrame(raf);
            raf = 0;
        }

        // قارئ الشاشة يعلن الشاشة الجديدة فقط حين يتحكم بها المستخدم
        deck.setAttribute('aria-live', paused ? 'polite' : 'off');
    };

    const setPaused = (value) => {
        paused = value;
        toggle.setAttribute('aria-label', paused ? 'تشغيل العرض التلقائي' : 'إيقاف العرض التلقائي');
        toggle.querySelector('[data-when="playing"]').classList.toggle('hidden', paused);
        toggle.querySelector('[data-when="paused"]').classList.toggle('hidden', !paused);
        sync();
    };

    ticks.forEach((tick, i) => tick.addEventListener('click', () => {
        show(i); // الانتقال اليدوي يبدأ مدة الشاشة من أولها
        sync();
    }));

    toggle.addEventListener('click', () => setPaused(!paused));

    root.addEventListener('pointerenter', (event) => {
        if (event.pointerType === 'mouse') {
            hovering = true;
            sync();
        }
    });
    root.addEventListener('pointerleave', () => {
        hovering = false;
        sync();
    });

    // دخول لوحة المفاتيح يوقف الدوران (نمط WAI للعروض المتحركة)، والنقر بالفأرة لا يوقفه
    root.addEventListener('focusin', (event) => {
        if (event.target.matches(':focus-visible')) {
            focused = true;
            sync();
        }
    });
    root.addEventListener('focusout', (event) => {
        if (!root.contains(event.relatedTarget)) {
            focused = false;
            sync();
        }
    });

    document.addEventListener('visibilitychange', sync);

    new IntersectionObserver(([entry]) => {
        onScreen = entry.isIntersecting;
        sync();
    }, { threshold: 0.25 }).observe(deck);

    show(0);
    setPaused(paused);
}
