/*
 * حركة الصفحة الرئيسية فقط — تُحمَّل من marketing/home وحدها كي لا تثقل لوحة التحكم.
 * من فعّل «تقليل الحركة» يرى الأقسام في حالتها النهائية الثابتة، والجولة لا تدور تلقائيًا.
 */
import { assembly } from './landing/assembly';
import { showcase } from './landing/showcase';
import { timeline } from './landing/timeline';

const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

document.querySelectorAll('[data-showcase]').forEach((root) => showcase(root, { reduce }));

if (!reduce) {
    document.querySelectorAll('[data-assembly]').forEach(assembly);
    document.querySelectorAll('[data-timeline]').forEach(timeline);
}
