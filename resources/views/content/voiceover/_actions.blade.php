{{-- ================= التوليد والدفعة ================= --}}
{{-- الزر أولاً: نقطة نهاية مسار الخطوات تُحاذي منتصفه، والتنبيهات تظهر تحته لا فوقه --}}
<div class="space-y-3">

    <button
        type="button" @click="generate()"
        class="btn-dark btn-lg w-full" :data-busy="generating ? 'true' : 'false'"
        :disabled="! speechReady"
    >
        <x-icon name="refresh" class="w-[18px] h-[18px]" />
        <span>توليد التعليق الصوتي</span>
        <span x-show="cost" x-cloak class="text-xs font-normal opacity-75 tnum" x-text="`· ≈ ${cost} نقطة`"></span>
    </button>

    <button type="button" @click="addToBatch()" class="btn-secondary btn-lg w-full" :disabled="! speechReady">
        <x-icon name="plus" class="w-[18px] h-[18px]" />
        <span>إضافة للدفعة</span>
        <span x-show="batch.length" x-cloak class="chip-neutral tnum" x-text="batch.length"></span>
    </button>

    <div x-show="showErrors && ! complete" x-cloak class="alert-warning" role="alert">
        <x-icon name="alert" class="w-5 h-5 shrink-0 mt-px" />
        <p class="flex-1">ينقص قبل التوليد: <span x-text="missing.join('، ')"></span>.</p>
    </div>

    <div x-show="error" x-cloak class="alert-danger" role="alert">
        <x-icon name="alert-circle" class="w-5 h-5 shrink-0 mt-px" />
        <p class="flex-1 leading-relaxed" x-text="error"></p>
        <button type="button" @click="error = ''" class="shrink-0 opacity-70 hover:opacity-100" aria-label="إغلاق">
            <x-icon name="close" class="w-4 h-4" />
        </button>
    </div>

    <div x-show="notice && ! error" x-cloak class="alert-success" role="status">
        <x-icon name="check-circle" class="w-5 h-5 shrink-0 mt-px" />
        <p class="flex-1 leading-relaxed" x-text="notice"></p>
        <button type="button" @click="notice = ''" class="shrink-0 opacity-70 hover:opacity-100" aria-label="إغلاق">
            <x-icon name="close" class="w-4 h-4" />
        </button>
    </div>

    <p x-show="cost > balance" x-cloak class="text-xs text-warning-fg px-1">
        رصيدك <span class="tnum" x-text="balance"></span> نقطة، والتقدير لهذا النص ≈ <span class="tnum" x-text="cost"></span>. اختصر النص أو اختر جودة أخف.
    </p>

    <p class="text-center text-[11px] text-fg-subtle leading-relaxed">
        تُحجز نقاط تقديرية حسب طول النص، ويُرجع ما لم يُستهلك بعد التسجيل بمدته الفعلية.
    </p>

    {{-- ---------- الدفعة ---------- --}}
    <section x-show="batch.length" x-cloak class="rounded-2xl border border-line bg-muted/50 p-4 sm:p-5 space-y-3" aria-labelledby="vo-batch-title">
        <header class="flex flex-wrap items-center gap-x-4 gap-y-1.5">
            <h2 id="vo-batch-title" class="flex items-center gap-2 text-[15px] font-bold text-fg">
                <x-icon name="clipboard" class="w-5 h-5" />
                الدفعة الجاهزة للتوليد
            </h2>
            <span class="ms-auto text-xs text-fg-muted tnum" x-text="`${batch.length} من ${maxBatch}`"></span>
            <button type="button" @click="clearBatch()" class="text-xs font-medium text-fg-muted hover:text-danger-fg">مسح الكل</button>
        </header>

        <ol class="space-y-2">
            <template x-for="(item, i) in batch" :key="item.key">
                <li class="card p-3.5 flex items-start gap-3">
                    <span class="vo-avatar w-9 h-9 text-sm" :style="`--h: ${hue(item.voice)}`" x-text="item.labels?.initial"></span>
                    <div class="flex-1 min-w-0">
                        <div class="flex flex-wrap items-center gap-1.5 text-xs">
                            <span class="font-bold text-sm text-fg" x-text="item.labels?.voice"></span>
                            <span class="chip-neutral rounded-md" x-text="item.labels?.style"></span>
                            <span class="chip-neutral rounded-md" x-text="item.labels?.language"></span>
                            <span class="chip-neutral rounded-md tnum" x-text="item.labels?.tier"></span>
                        </div>
                        <p class="mt-1.5 text-xs text-fg-muted line-clamp-2" x-text="item.text"></p>
                    </div>
                    <button type="button" @click="removeFromBatch(i)" class="btn-ghost btn-sm btn-icon text-fg-subtle hover:text-danger-fg hover:bg-danger-soft" :aria-label="`حذف التعليق ${i + 1} من الدفعة`">
                        <x-icon name="trash" class="w-4 h-4" />
                    </button>
                </li>
            </template>
        </ol>

        <div class="card flex flex-wrap items-center justify-between gap-3 px-5 py-4">
            <span class="text-sm text-fg-muted">إجمالي النقاط التقديري:</span>
            <span class="text-2xl font-bold text-fg tnum" x-text="`≈ ${batchCost} نقطة`"></span>
        </div>

        <button type="button" @click="generateBatch()" class="btn-dark btn-lg w-full" :data-busy="generating ? 'true' : 'false'" :disabled="! speechReady">
            <x-icon name="zap" class="w-[18px] h-[18px]" />
            <span x-text="`توليد الدفعة كاملة (${batch.length})`">توليد الدفعة كاملة</span>
        </button>
    </section>
</div>
