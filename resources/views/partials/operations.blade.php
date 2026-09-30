{{--
    «إنتاجاتي»: كل عمليات الذكاء الاصطناعي في لوحة جانبية واحدة.

    العملية تُطلق من صفحة وتنتهي والتاجر في غيرها، فزر جانبي ثابت في كل صفحة
    يفتحها، ويحمل عدد ما يعمل الآن. الجارية تُستطلع فتتحدث بطاقتها في مكانها
    بلا تحديث للصفحة، ويظهر فيها زر «عرض النتائج» حين تجهز.

    $operations يملؤه View::composer في AppServiceProvider.
--}}
@php
    // الأيقونة تُرسم في جافاسكربت، فنمرر شكلها جاهزاً لكل نوع مهمة
    $icons = collect(\App\Support\JobSummary::TYPES)
        ->pluck('icon')
        ->push('bot')
        ->unique()
        ->mapWithKeys(fn ($name) => [$name => trim(\Illuminate\Support\Facades\Blade::render(
            '<x-icon :name="$name" class="w-[18px] h-[18px]" />', ['name' => $name]
        ))]);
@endphp

<div
    x-data="operationsCenter(@js($operations), @js(['icons' => $icons, 'writeUrl' => route('content.generator')]))"
    @keydown.escape.window="close()"
>
    {{-- المقبض الجانبي: نصفه داخل الحافة، وعدده هو ما يعمل الآن --}}
    <button
        type="button"
        x-data="{
            y: 0.5, dragging: false, moved: false, startY: 0, startFrac: 0,
            init() {
                try { const v = parseFloat(localStorage.getItem('ops-handle-y')); if (v >= 0.08 && v <= 0.92) this.y = v; } catch (e) {}
            },
            down(e) { this.dragging = true; this.moved = false; this.startY = e.clientY; this.startFrac = this.y; e.currentTarget.setPointerCapture(e.pointerId); },
            move(e) {
                if (! this.dragging) return;
                const dy = e.clientY - this.startY;
                if (Math.abs(dy) > 4) this.moved = true;
                if (this.moved) this.y = Math.min(0.92, Math.max(0.08, this.startFrac + dy / window.innerHeight));
            },
            up() {
                this.dragging = false;
                if (this.moved) { try { localStorage.setItem('ops-handle-y', this.y); } catch (e) {} }
            },
        }"
        @pointerdown="down($event)"
        @pointermove="move($event)"
        @pointerup="up()"
        @pointercancel="up()"
        @click="moved ? (moved = false) : toggle()"
        :style="`top: ${y * 100}%`"
        class="fixed z-30 -translate-y-1/2 end-0 touch-none select-none cursor-grab active:cursor-grabbing flex flex-col items-center gap-1 py-3 px-2
               rounded-s-2xl border border-e-0 border-line bg-card shadow-lg text-fg-muted
               transition hover:text-fg hover:ps-3"
        :aria-expanded="isOpen ? 'true' : 'false'"
        aria-controls="operations-panel"
        :title="running.length ? `${running.length} عملية جارية` : 'إنتاجاتي'"
    >
        <x-icon name="sparkles" class="w-5 h-5" />

        <span
            x-show="badge"
            x-cloak
            class="grid place-items-center min-w-5 h-5 px-1 rounded-full text-[11px] font-bold tnum"
            :class="stalledAny
                ? 'bg-warning text-white motion-safe:animate-pulse-dot'
                : (running.length ? 'bg-brand-600 text-white motion-safe:animate-pulse-dot' : 'bg-muted text-fg-muted')"
            x-text="stalledAny ? '!' : badge"
        ></span>

        <span class="sr-only">إنتاجاتي — تتبع عمليات الذكاء الاصطناعي</span>
    </button>

    {{-- طبقة التعتيم --}}
    <div
        x-show="isOpen" x-cloak x-transition.opacity.duration.200ms
        @click="close()"
        class="fixed inset-0 z-40 bg-scrim/50 backdrop-blur-sm"
        aria-hidden="true"
    ></div>

    {{-- اللوحة --}}
    <aside
        id="operations-panel"
        x-show="isOpen" x-cloak
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="translate-x-full rtl:-translate-x-full"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-end="translate-x-full rtl:-translate-x-full"
        class="fixed inset-y-0 end-0 z-50 w-full max-w-[28rem] flex flex-col bg-card border-s border-line shadow-2xl"
        role="dialog" aria-modal="true" aria-labelledby="operations-title"
    >
        <header class="flex items-start gap-3 p-5 pb-4 border-b border-line shrink-0">
            <div class="min-w-0 flex-1">
                <h2 id="operations-title" class="text-lg font-bold text-fg">إنتاجاتي</h2>
                <p class="mt-0.5 text-xs text-fg-muted">تتبّع جميع عمليات الذكاء الاصطناعي</p>
            </div>

            <button type="button" x-ref="close" @click="close()" class="btn btn-ghost btn-sm btn-icon">
                <x-icon name="close" class="w-5 h-5" />
                <span class="sr-only">إغلاق اللوحة</span>
            </button>
        </header>

        {{-- التبويبات --}}
        <div class="flex items-center gap-1 p-1 m-4 mb-0 rounded-xl bg-muted shrink-0" role="tablist" aria-label="تصفية العمليات">
            <template x-for="option in tabs" :key="option.key">
                <button
                    type="button" role="tab"
                    @click="tab = option.key"
                    :aria-selected="tab === option.key ? 'true' : 'false'"
                    class="flex-1 min-h-9 rounded-lg text-xs font-semibold transition tnum"
                    :class="tab === option.key ? 'bg-card text-fg shadow-xs' : 'text-fg-subtle hover:text-fg'"
                    x-text="`${option.label} (${option.count})`"
                ></button>
            </template>
        </div>

        {{-- البطاقات --}}
        <div class="flex-1 overflow-y-auto p-4 space-y-2.5">
            <template x-for="op in shown" :key="op.uuid">
                <article class="rounded-xl border border-line bg-card p-3.5">
                    <div class="flex items-start gap-2.5">
                        <span class="grid place-items-center w-8 h-8 shrink-0 rounded-lg bg-muted text-fg-muted" x-html="icons[op.icon] ?? icons.bot"></span>

                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-bold text-fg leading-snug" x-text="op.title"></p>
                            <p class="mt-0.5 text-[11px] text-fg-subtle" x-text="`${op.kind} · ${op.time}`"></p>
                        </div>

                        <span class="chip shrink-0" :class="stateClass(op)">
                            <span x-show="op.state === 'running'" class="w-1.5 h-1.5 rounded-full bg-current motion-safe:animate-pulse-dot" aria-hidden="true"></span>
                            <span x-text="stateLabel(op)"></span>
                        </span>
                    </div>

                    {{-- تقدّم المهمة الجارية --}}
                    <div x-show="op.state === 'running'" class="h-1.5 mt-3 rounded-full bg-muted overflow-hidden" role="progressbar" :aria-valuenow="op.progress" aria-valuemin="0" aria-valuemax="100">
                        <div class="h-full rounded-full bg-brand-500 transition-[width] duration-500 ease-out" :style="`width: ${op.progress}%`"></div>
                    </div>

                    {{-- لم تبدأ ولا عامل طابور حي: بدونه تبقى «جارية» بلا نهاية ويظنها التاجر عطلاً --}}
                    <div x-show="op.state === 'running' && op.stalled" x-cloak class="alert-warning mt-3 text-xs" role="status">
                        <x-icon name="alert" class="w-4 h-4 shrink-0 mt-px" />
                        <p class="flex-1 leading-relaxed">
                            لم تبدأ هذه العملية بعد: خدمة المعالجة في الخلفية لا تبدو شغّالة.
                            إن كنت تشغّل المنصة بنفسك فافتح نافذة «الطابور» (من <span dir="ltr">start.bat</span>)
                            وستبدأ العملية تلقائياً، وإلا فتواصل مع الدعم. نقاط العملية محجوزة لها وتُسوّى بعد انتهائها.
                        </p>
                    </div>

                    <button
                        type="button" @click="expand(op.uuid)"
                        class="block w-full mt-2.5 text-[11px] text-fg-subtle hover:text-fg-muted"
                        :aria-expanded="expanded === op.uuid ? 'true' : 'false'"
                        x-text="expanded === op.uuid ? 'إخفاء التفاصيل' : 'اضغط لعرض التفاصيل'"
                    ></button>

                    <div x-show="expanded === op.uuid" x-cloak class="mt-2 rounded-lg bg-muted/60 px-3 py-2 space-y-1">
                        <p class="text-xs text-fg-muted leading-relaxed" x-text="op.meta"></p>
                        <p x-show="op.credits" class="text-xs text-fg-subtle tnum" x-text="`${op.credits} نقطة`"></p>
                        <p x-show="op.error" class="text-xs text-danger-fg leading-relaxed" x-text="op.error"></p>
                    </div>

                    <div class="flex items-center gap-2 mt-3 pt-3 border-t border-line">
                        <span
                            class="text-xs font-semibold tnum"
                            :class="op.state === 'failed' ? 'text-danger-fg' : 'text-success-fg'"
                            x-text="op.state === 'failed' ? 'فشلت — أُرجعت نقاطها' : (op.state === 'running' ? (op.stage ? `${op.stage}…` : 'جارية…') : `${op.succeeded} نجح`)"
                        ></span>

                        <template x-if="op.resultUrl">
                            <a :href="op.resultUrl" class="btn-ghost btn-sm ms-auto">
                                <span>عرض النتائج</span>
                                <x-icon name="arrow-left" class="w-3.5 h-3.5" />
                            </a>
                        </template>

                        <template x-if="op.editUrl">
                            <a :href="op.editUrl" class="btn-ghost btn-sm" :class="! op.resultUrl && 'ms-auto'">
                                <span>تعديل</span>
                                <x-icon name="arrow-left" class="w-3.5 h-3.5" />
                            </a>
                        </template>

                        <button
                            type="button" @click="dismiss(op.uuid)"
                            class="btn btn-ghost btn-sm btn-icon text-fg-subtle"
                            :class="! op.resultUrl && ! op.editUrl && 'ms-auto'"
                            :aria-label="`إخفاء ${op.title} من القائمة`"
                        >
                            <x-icon name="close" class="w-4 h-4" />
                        </button>
                    </div>
                </article>
            </template>

            <p x-show="! shown.length" x-cloak class="flex flex-col items-center gap-2 py-12 text-center text-sm text-fg-subtle">
                <x-icon name="inbox" class="w-8 h-8" />
                <span x-text="emptyMessage"></span>
                <button type="button" x-show="dismissed.length" @click="restoreAll()" class="btn-ghost btn-sm">
                    <span>إظهار ما أخفيته</span>
                </button>
            </p>
        </div>
    </aside>
</div>
