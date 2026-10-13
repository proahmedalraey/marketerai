/*
 * ستوريات البطل: إطارات حقيقية ولّدتها المنصة تتقدّم تلقائيًا بأشرطة مقسّمة كإنستغرام.
 * الإطار الجديد ينكشف بقناع من اليمين فوق السابق، والسابق يبقى ظاهرًا حتى يكتمل الانكشاف.
 * النقر على النصف الأيسر يتقدّم والأيمن يرجع — اتجاه القراءة العربية.
 */
import { clock } from './clock';

const DURATION = 4500;
const REVEAL = 1100; // مدة انكشاف الإطار في app.css (.story-frame.is-active .story-img)

export function stories(root, { reduce }) {
    const frames = [...root.querySelectorAll('[data-story]')];
    const bars = [...root.querySelectorAll('[data-story-bar]')];

    let current = 0;
    let leaving = 0;

    const timer = clock({
        root,
        duration: DURATION,
        paused: reduce,
        onProgress: (progress) => {
            bars[current].style.transform = `scaleX(${progress})`;
        },
        onEnd: () => show(current + 1),
    });

    function show(index) {
        const previous = current;
        current = (index + frames.length) % frames.length;
        timer.restart();

        frames.forEach((frame, i) => {
            frame.classList.toggle('is-active', i === current);
            frame.classList.toggle('is-leaving', i === previous && i !== current);
            frame.setAttribute('aria-hidden', i === current ? 'false' : 'true');
        });

        bars.forEach((bar, i) => {
            bar.style.transform = `scaleX(${i < current ? 1 : 0})`;
        });

        clearTimeout(leaving);
        leaving = setTimeout(() => frames[previous].classList.remove('is-leaving'), REVEAL);
    }

    root.querySelector('[data-story-next]').addEventListener('click', () => show(current + 1));
    root.querySelector('[data-story-prev]').addEventListener('click', () => show(current - 1));

    bars.forEach((bar, i) => {
        bar.style.transform = `scaleX(${i < current ? 1 : 0})`;
    });
}
