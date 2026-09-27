{{-- ================= 1. نمط الإلقاء ================= --}}
<section class="vo-card" aria-labelledby="vo-style-title">
    <div class="vo-head">
        <span class="vo-head-icon"><x-icon name="audio-lines" class="w-[18px] h-[18px]" /></span>
        <h2 id="vo-style-title" class="text-[15px] font-bold text-fg">نمط الإلقاء</h2>
        <span class="text-xs text-fg-subtle truncate" x-text="styleLabel"></span>

        {{-- بدل «شاهد الفيديو» عند المنافس: شرح مختصر حقيقي لا رابط لفيديو غير موجود --}}
        <div class="relative ms-auto" @keydown.escape="howOpen = false">
            <button
                type="button" @click="howOpen = ! howOpen" :aria-expanded="howOpen ? 'true' : 'false'"
                class="btn-secondary btn-sm"
            >
                <x-icon name="help" class="w-4 h-4" />
                <span>كيف يعمل؟</span>
            </button>

            <div
                x-show="howOpen" x-cloak x-transition.opacity.duration.150ms @click.outside="howOpen = false"
                class="studio-popover absolute end-0 top-full mt-2 z-30 w-[min(20rem,calc(100vw-2rem))] p-4 text-start"
                role="dialog" aria-label="طريقة الاستخدام"
            >
                <ol class="space-y-2.5 text-[13px] leading-relaxed text-fg-muted">
                    <li class="flex gap-2"><span class="step-pill shrink-0">1</span> اختر نمط الإلقاء، أو اكتب أسلوبك في «أسلوب مخصص».</li>
                    <li class="flex gap-2"><span class="step-pill shrink-0">2</span> اكتب النص أو اختره من خطتك. «تحسين الصوت» يضيف وقفات ونبرة دون تغيير كلمة من كلماتك.</li>
                    <li class="flex gap-2"><span class="step-pill shrink-0">3</span> حدّد اللهجة والمذيع، واضغط «استمع» لتسمع صوته قبل أن تدفع.</li>
                    <li class="flex gap-2"><span class="step-pill shrink-0">4</span> ولّد: تُحجز نقاط تقديرية ويُرجع ما لم يُستهلك بعد التسجيل.</li>
                </ol>
                <p class="mt-3 pt-3 border-t border-line text-[11px] text-fg-subtle">
                    الملف يبقى {{ $studio['retentionDays'] }} يوماً ثم يُحذف ما لم تحفظه بزر <x-icon name="bookmark" class="inline w-3 h-3 align-[-2px]" />.
                </p>
            </div>
        </div>
    </div>

    <div class="px-4 sm:px-6 pb-5 space-y-4">
        <div role="radiogroup" aria-labelledby="vo-style-title" class="grid grid-cols-4 sm:grid-cols-8 gap-2">
            @foreach ($studio['styles'] as $key => $item)
                <button
                    type="button" role="radio"
                    class="vo-tile" @if ($key === 'custom') data-dashed @endif
                    :aria-checked="style === '{{ $key }}' ? 'true' : 'false'"
                    @click="style = '{{ $key }}'"
                    title="{{ $item['hint'] }}"
                >
                    <span class="vo-tile-icon"><x-icon :name="$item['icon']" class="w-5 h-5" /></span>
                    <span class="text-center leading-tight whitespace-nowrap">{{ $item['label'] }}</span>
                </button>
            @endforeach
        </div>

        <div x-show="style === 'custom'" x-cloak class="space-y-2">
            <div class="flex items-center justify-between gap-3">
                <label for="vo-custom-style" class="text-sm font-bold text-fg">أسلوب الإلقاء المخصص</label>
                <button
                    type="button" @click="runTool('direction')"
                    class="btn-dark btn-sm min-h-8" :data-busy="busy.direction ? 'true' : 'false'"
                    title="يقترح توجيهات إلقاء من نصك في قسم المحتوى"
                >
                    <x-icon name="sparkles" class="w-3.5 h-3.5" />
                    <span>توليد تلقائي</span>
                </button>
            </div>

            <textarea
                id="vo-custom-style" x-model="customStyle" rows="3" maxlength="500"
                class="field" :class="showErrors && ! customStyle.trim() && 'field-invalid'"
                placeholder="اكتب توجيهات الإلقاء هنا… مثال: بصوت هادئ وواثق، بطيء في البداية ثم متحمس عند ذكر المنتج"
            ></textarea>

            <p x-show="toolError && toolErrorFor === 'direction'" x-cloak class="error-text" role="alert">
                <x-icon name="alert-circle" class="w-4 h-4 shrink-0" />
                <span x-text="toolError"></span>
            </p>
        </div>
    </div>
</section>
