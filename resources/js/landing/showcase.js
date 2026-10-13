/*
 * جولة المنتج: شاشات حقيقية من المنصة تتبدّل على الساعة المشتركة، ولكل شاشة شريط
 * يمتلئ خلال مدتها بالضبط، وأسماء الشاشات أزرار تنتقل إليها مباشرة.
 * اللقطة المعروضة تقترب ببطء طوال مدتها (app.css: .tour-slide) فلا تبدو ساكنة،
 * وتتوقف مع الشريط حين تتوقف الساعة.
 */
import { clock } from './clock';

const DURATION = 4000;
const FADE = 500; // مدة تلاشي الشاشة (duration-500 في القالب)

export function showcase(root, { reduce }) {
    const deck = root.querySelector('[data-deck]');
    const slides = [...root.querySelectorAll('[data-slide]')];
    const ticks = [...root.querySelectorAll('[data-tick]')];
    const fills = ticks.map((tick) => tick.querySelector('[data-fill]'));
    const toggle = root.querySelector('[data-toggle]');

    let current = 0;
    let leaving = 0;

    root.style.setProperty('--tour-duration', `${DURATION}ms`);

    const timer = clock({
        root,
        target: deck,
        duration: DURATION,
        paused: reduce, // تقليل الحركة: لا تشغيل تلقائي، والأزرار تبقى تعمل
        onProgress: (progress) => {
            fills[current].style.width = `${progress * 100}%`;
        },
        onEnd: () => show(current + 1),
        onPauseChange: (paused) => {
            toggle.setAttribute('aria-label', paused ? 'تشغيل العرض التلقائي' : 'إيقاف العرض التلقائي');
            toggle.querySelector('[data-when="playing"]').classList.toggle('hidden', paused);
            toggle.querySelector('[data-when="paused"]').classList.toggle('hidden', !paused);
            // قارئ الشاشة يعلن الشاشة الجديدة فقط حين يتحكم بها المستخدم
            deck.setAttribute('aria-live', paused ? 'polite' : 'off');
        },
        onRunningChange: (running) => root.classList.toggle('is-running', running),
    });

    function show(index) {
        const previous = current;
        current = (index + slides.length) % slides.length;
        timer.restart();

        slides.forEach((slide, i) => {
            slide.classList.toggle('opacity-0', i !== current);
            slide.classList.toggle('is-on', i === current);
            // الشاشة المغادرة تبقى عند تقريبها حتى يكتمل تلاشيها، ثم تعود لحجمها وهي مخفية
            slide.classList.toggle('is-leaving', i === previous && i !== current);
            slide.setAttribute('aria-hidden', i === current ? 'false' : 'true');
        });

        ticks.forEach((tick, i) => {
            tick.setAttribute('aria-current', i === current ? 'true' : 'false');
            fills[i].style.width = i < current ? '100%' : '0%';
        });

        clearTimeout(leaving);
        leaving = setTimeout(() => slides[previous].classList.remove('is-leaving'), FADE + 100);
    }

    ticks.forEach((tick, i) => tick.addEventListener('click', () => show(i)));
    toggle.addEventListener('click', () => timer.setPaused(!timer.paused));

    show(0);
    timer.setPaused(reduce);
}
