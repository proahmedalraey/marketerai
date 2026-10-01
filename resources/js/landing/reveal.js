/*
 * العناوين تظهر سطرًا سطرًا من خلف قناع حين تدخل الشاشة.
 * التقسيم بالكلمات ثم تجميعها في أسطر حسب موضعها الفعلي — لا تقسيم داخل الكلمة،
 * لأن فصل الحروف العربية في عناصر مستقلة يكسر اتصالها. العناصر المتداخلة
 * (كعبارة التظليل البرتقالية) تبقى وحدة واحدة لا تُفكك.
 * بعد اكتمال الظهور يعود العنوان إلى HTML الأصلي فيلتف طبيعيًا مع أي تغيير في العرض.
 */
const STAGGER = 90;   // يطابق calc(var(--i) * 90ms) في app.css
const DURATION = 900; // يطابق مدة انتقال .reveal-inner

export function reveal(heading) {
    const original = heading.innerHTML;
    let played = false;

    const split = () => {
        heading.innerHTML = original;

        const units = [];
        for (const node of [...heading.childNodes]) {
            if (node.nodeType === Node.TEXT_NODE) {
                const words = document.createDocumentFragment();
                for (const part of node.textContent.split(/\s+/)) {
                    if (part) {
                        const word = document.createElement('span');
                        word.textContent = part;
                        words.append(word);
                        units.push(word);
                    }
                }
                node.replaceWith(words);
            } else if (node.nodeType === Node.ELEMENT_NODE) {
                units.push(node);
            }
        }

        // كلمة تبدأ سطرًا جديدًا حين تنزل عن سابقتها بأكثر من نصف ارتفاع السطر
        const tolerance = parseFloat(getComputedStyle(heading).lineHeight) / 2 || 12;
        const lines = [];
        let line = null;
        let top = 0;

        for (const unit of units) {
            if (unit.tagName === 'BR') {
                line = null;
                continue;
            }
            const y = unit.getBoundingClientRect().top;
            if (!line || Math.abs(y - top) > tolerance) {
                line = [];
                lines.push(line);
                top = y;
            }
            line.push(unit);
        }

        heading.replaceChildren(...lines.map((nodes, i) => {
            const mask = document.createElement('span');
            mask.className = 'reveal-line';
            const inner = document.createElement('span');
            inner.className = 'reveal-inner';
            inner.style.setProperty('--i', i);
            nodes.forEach((node, k) => inner.append(...(k ? [' ', node] : [node])));
            mask.append(inner);
            return mask;
        }));

        heading.classList.add('is-split');
    };

    const play = () => {
        if (played) {
            return;
        }
        played = true;

        const count = heading.querySelectorAll('.reveal-line').length;
        requestAnimationFrame(() => heading.classList.add('is-revealed'));
        setTimeout(() => {
            heading.innerHTML = original;
        }, (count - 1) * STAGGER + DURATION + 50);
    };

    split();

    let resizing = 0;
    window.addEventListener('resize', () => {
        clearTimeout(resizing);
        resizing = setTimeout(() => {
            if (!played) {
                split();
            }
        }, 150);
    });

    return play;
}

export function revealOnView(headings) {
    const plays = new Map(headings.map((heading) => [heading, reveal(heading)]));

    const observer = new IntersectionObserver((entries) => {
        for (const entry of entries) {
            if (entry.isIntersecting) {
                plays.get(entry.target)();
                observer.unobserve(entry.target);
            }
        }
    }, { rootMargin: '0px 0px -12% 0px' });

    headings.forEach((heading) => observer.observe(heading));
}
