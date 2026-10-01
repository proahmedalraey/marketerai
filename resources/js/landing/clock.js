/*
 * ساعة واحدة لكل عرض يتقدّم بالوقت (الجولة، الستوريات): حلقة rAF تجمع الزمن المنقضي
 * وتقود الشريط والانتقال معًا — لو انفصلا (setInterval + انتقال CSS) لكذب الشريط
 * على موعد الشاشة التالية أول ما يُخفى التبويب أو يُوقف العرض.
 *
 * لا تدور إلا والعرض ظاهر ولا أحد يتفاعل معه: تتوقف مع التبويب المخفي، والمرور بالفأرة،
 * ودخول لوحة المفاتيح (نمط WAI للعروض المتحركة)، وخروج العرض من الشاشة.
 */
export function clock({
    root,
    target = root,
    duration,
    paused = false,
    onProgress,
    onEnd,
    onPauseChange = () => {},
    onRunningChange = () => {}, // لما يتبع الساعة نفسها (حركة CSS تتوقف وتستأنف معها)
}) {
    let elapsed = 0;
    let last = 0;
    let raf = 0;
    let hovering = false;
    let focused = false;
    let onScreen = false;
    let wasRunning = null;

    const running = () => !paused && !hovering && !focused && onScreen && !document.hidden;

    const frame = (now) => {
        raf = 0;
        // بعد عودة من تبويب مخفي لا نقفز شاشات: الإطار الواحد لا يُحتسب بأكثر من 100ms
        elapsed += Math.min(now - last, 100);
        last = now;

        const progress = Math.min(1, elapsed / duration);
        onProgress(progress);

        if (progress >= 1) {
            onEnd();
        }

        if (running()) {
            raf = requestAnimationFrame(frame);
        }
    };

    const sync = () => {
        if (running() !== wasRunning) {
            wasRunning = running();
            onRunningChange(wasRunning);
        }

        if (running() && !raf) {
            last = performance.now();
            raf = requestAnimationFrame(frame);
        } else if (!running() && raf) {
            cancelAnimationFrame(raf);
            raf = 0;
        }
    };

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

    // النقر بالفأرة لا يوقف الدوران؛ الوصول بلوحة المفاتيح يوقفه
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
    }, { threshold: 0.25 }).observe(target);

    return {
        // الانتقال اليدوي يبدأ مدة الشاشة من أولها
        restart() {
            elapsed = 0;
        },
        get paused() {
            return paused;
        },
        setPaused(value) {
            paused = value;
            onPauseChange(paused);
            sync();
        },
    };
}
