/*
 * جولة المنتج: شاشات حقيقية من المنصة تتبدّل على الساعة المشتركة، ولكل شاشة شريط
 * يمتلئ خلال مدتها بالضبط، وأسماء الشاشات أزرار تنتقل إليها مباشرة.
 */
import { clock } from './clock';

const DURATION = 6000;

export function showcase(root, { reduce }) {
    const deck = root.querySelector('[data-deck]');
    const slides = [...root.querySelectorAll('[data-slide]')];
    const ticks = [...root.querySelectorAll('[data-tick]')];
    const fills = ticks.map((tick) => tick.querySelector('[data-fill]'));
    const toggle = root.querySelector('[data-toggle]');

    let current = 0;

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
    });

    function show(index) {
        current = (index + slides.length) % slides.length;
        timer.restart();

        slides.forEach((slide, i) => {
            slide.classList.toggle('opacity-0', i !== current);
            slide.setAttribute('aria-hidden', i === current ? 'false' : 'true');
        });

        ticks.forEach((tick, i) => {
            tick.setAttribute('aria-current', i === current ? 'true' : 'false');
            fills[i].style.width = i < current ? '100%' : '0%';
        });
    }

    ticks.forEach((tick, i) => tick.addEventListener('click', () => show(i)));
    toggle.addEventListener('click', () => timer.setPaused(!timer.paused));

    show(0);
    timer.setPaused(reduce);
}
