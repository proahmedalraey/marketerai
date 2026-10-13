/*
 * حركة الصفحة الرئيسية فقط — تُحمَّل من marketing/home وحدها كي لا تثقل لوحة التحكم.
 * من فعّل «تقليل الحركة» يرى الأقسام في حالتها النهائية الثابتة، والعروض لا تدور تلقائيًا.
 */
import { assembly } from './landing/assembly';
import { revealOnView } from './landing/reveal';
import { showcase } from './landing/showcase';
import { stories } from './landing/stories';
import { timeline } from './landing/timeline';

const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

document.querySelectorAll('[data-stories]').forEach((root) => stories(root, { reduce }));
document.querySelectorAll('[data-showcase]').forEach((root) => showcase(root, { reduce }));

if (!reduce) {
    document.querySelectorAll('[data-assembly]').forEach(assembly);
    document.querySelectorAll('[data-timeline]').forEach(timeline);

    // أسطر العناوين تُحسب بالخط النهائي؛ لا ننتظر Cairo أكثر من لحظة كي لا يتأخر العنوان الرئيسي
    const fonts = Promise.race([document.fonts?.ready, new Promise((resolve) => setTimeout(resolve, 800))]);
    fonts.then(() => revealOnView([...document.querySelectorAll('[data-reveal]')]));
}
