@php
    use App\Services\Import\DTO\DiscoveryResult;

    $scanStatus = $scan?->status->value;
    $scanRunning = in_array($scanStatus, ['queued', 'processing'], true);
    $scanDone = in_array($scanStatus, ['completed', 'partial'], true);

    $result = $scanDone ? DiscoveryResult::fromArray($scan->result ?? []) : null;

    $importRunning = $importing && in_array($importing->status->value, ['queued', 'processing'], true);
    $importDone = $importing && in_array($importing->status->value, ['completed', 'partial'], true);

    $steps = ['الصق رابط المتجر', 'راجع المنتجات واختر ما تريد', 'استوردها إلى قائمتك'];
    $activeStep = $scanDone ? 2 : ($scanRunning ? 1 : 0);
@endphp

<div
    x-data="storeImport({
        open: @js((bool) ($scan || $importing || request()->boolean('import_start'))),
        keys: @js($result ? collect($result->items)->map(fn ($i) => $i->key())->all() : []),
        cost: {{ (int) $importCost }},
        pollUuid: @js($scanRunning ? $scan->uuid : ($importRunning ? $importing->uuid : null)),
    })"
    x-on:open-import.window="open = true"
    @keydown.escape.window="open = false"
>
    <div x-show="open" x-cloak class="fixed inset-0 z-[60] overflow-y-auto" role="dialog" aria-modal="true" aria-labelledby="import-title">
        <div
            x-show="open" x-transition.opacity.duration.150ms
            @click="open = false"
            class="fixed inset-0 bg-scrim/50 backdrop-blur-sm" aria-hidden="true"
        ></div>

        <div class="relative min-h-full grid place-items-center p-4">
            <div class="relative w-full max-w-2xl card shadow-pop motion-safe:animate-scale-in">

                {{-- ================= الرأس ================= --}}
                <header class="flex items-start justify-between gap-4 px-5 py-4 border-b border-line">
                    <div class="min-w-0">
                        <h2 id="import-title" class="text-base font-bold text-fg">استيراد المنتجات من متجر</h2>
                        <p class="text-xs text-fg-muted mt-0.5 leading-relaxed">
                            اكتشف منتجات متجرك الإلكتروني واستوردها إلى قائمتك دفعة واحدة.
                        </p>
                    </div>

                    <a href="{{ route('products.index') }}" class="btn btn-ghost btn-sm btn-icon -m-1.5 shrink-0">
                        <x-icon name="close" class="w-5 h-5" />
                        <span class="sr-only">إغلاق</span>
                    </a>
                </header>

                {{-- ================= 1) الرابط ================= --}}
                @if (! $scan && ! $importing)
                    {{-- الخطوة الأولى: لا مهمة جارية بعد --}}
                    <form method="POST" action="{{ route('products.import.scan') }}" class="p-5">
                        @csrf

                        <p class="text-sm text-fg-muted leading-relaxed mb-4">
                            الصق رابط متجرك الإلكتروني، وسنكتشف صفحات منتجاته لتراجعها وتختار ما تريد استيراده.
                        </p>

                        <x-field
                            label="رابط المتجر" name="store_url" required
                            hint="يعمل مع شوبيفاي وووكومرس وأغلب المتاجر مثل سلة وزد. تُحفظ المنتجات كسلع يمكنك تعديلها لاحقاً."
                        >
                            <input
                                id="store_url" name="store_url" type="text" dir="ltr" required
                                class="field @error('store_url') field-invalid @enderror"
                                value="{{ old('store_url', $currentBrand->store_url ?? '') }}"
                                placeholder="https://your-store.com"
                            >
                        </x-field>

                        <ol class="grid sm:grid-cols-3 gap-2 mt-5">
                            @foreach ($steps as $i => $step)
                                <li class="flex items-center gap-2.5 rounded-xl border border-line bg-muted/50 px-3 py-2.5">
                                    <span class="grid place-items-center w-6 h-6 shrink-0 rounded-full bg-card border border-line text-[11px] font-bold text-fg-muted tnum">
                                        {{ $i + 1 }}
                                    </span>
                                    <span class="text-xs text-fg-muted leading-snug">{{ $step }}</span>
                                </li>
                            @endforeach
                        </ol>

                        <div class="flex items-center gap-2.5 mt-5 pt-4 border-t border-line">
                            <button type="submit" class="btn-primary">
                                <span class="inline-flex items-center gap-2">
                                    <x-icon name="search" class="w-4 h-4" />
                                    ابحث عن المنتجات
                                </span>
                            </button>

                            <a href="{{ route('products.index') }}" class="btn-ghost"><span>إلغاء</span></a>

                            <span class="hidden sm:block text-xs text-fg-subtle ms-auto">
                                البحث مجاني — لا تُخصم نقاط إلا عند الاستيراد.
                            </span>
                        </div>
                    </form>
                @endif

                {{-- ================= 2) جارٍ العمل ================= --}}
                @if ($scanRunning || $importRunning)
                    <div class="p-5">
                        <div class="flex items-center gap-3 mb-4">
                            <span class="grid place-items-center w-9 h-9 rounded-xl bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-300">
                                <x-icon name="refresh" class="w-[18px] h-[18px]" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-fg">
                                    {{ $scanRunning ? 'نقرأ متجرك الآن…' : 'نستورد المنتجات المحددة…' }}
                                </p>
                                <p class="text-xs text-fg-muted mt-0.5" role="status" aria-live="polite">
                                    {{ $scanRunning
                                        ? 'قد تستغرق المتاجر الكبيرة دقيقة. ابقَ في الصفحة.'
                                        : 'نحفظ كل منتج ثم نجهّز ورقته المرجعية.' }}
                                </p>
                            </div>
                        </div>

                        <div class="h-2 rounded-full bg-muted overflow-hidden" role="progressbar"
                             :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100" aria-label="تقدّم العملية">
                            <div class="h-full rounded-full bg-brand-500 transition-[width] duration-500 ease-out"
                                 :style="`width: ${progress}%`"></div>
                        </div>

                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 mt-5">
                            @for ($i = 0; $i < 3; $i++)
                                <div class="skeleton h-20 rounded-xl"></div>
                            @endfor
                        </div>
                    </div>
                @endif

                {{-- ================= فشل ================= --}}
                @if ($scan && $scan->status->value === 'failed')
                    <div class="p-5">
                        <div class="alert-danger" role="alert">
                            <x-icon name="alert-circle" class="w-5 h-5 shrink-0 mt-px" />
                            <p class="flex-1 leading-relaxed">{{ $scan->error ?: 'تعذّر قراءة هذا المتجر.' }}</p>
                        </div>

                        <div class="flex items-center gap-2.5 mt-4">
                            <a href="{{ route('products.index', ['import_start' => 1]) }}" class="btn-primary">
                                <span>جرّب رابطاً آخر</span>
                            </a>
                            <a href="{{ route('products.index') }}" class="btn-ghost"><span>إغلاق</span></a>
                        </div>
                    </div>
                @endif

                {{-- ================= 3) المراجعة والاختيار ================= --}}
                @if ($scanDone && $result)
                    <form method="POST" action="{{ route('products.import.store') }}" class="flex flex-col max-h-[70vh]">
                        @csrf
                        <input type="hidden" name="job" value="{{ $scan->uuid }}">

                        <template x-for="key in selected" :key="key">
                            <input type="hidden" name="keys[]" :value="key">
                        </template>

                        <div class="px-5 py-4 border-b border-line">
                            <p class="text-sm font-semibold text-fg">
                                تم العثور على <span class="tnum">{{ number_format($result->total) }}</span> منتج
                                <span class="text-fg-muted font-normal" dir="ltr">{{ $result->store }}</span>
                            </p>

                            <p class="text-xs text-fg-muted mt-1 leading-relaxed">
                                قرأنا <span class="tnum">{{ count($result->items) }}</span> منها حتى الآن عبر {{ $result->platform }}.
                                @if ($result->truncated)
                                    المتجر كبير، لذا عرضنا أول {{ number_format(count($result->items) + count($result->pendingUrls)) }} منتج تم اكتشافها.
                                @endif
                            </p>
                        </div>

                        {{-- شريط التحديد --}}
                        <div class="flex items-center gap-3 px-5 py-2.5 bg-muted/60 border-b border-line">
                            <label class="flex items-center gap-2.5 cursor-pointer min-h-11">
                                <input type="checkbox" class="w-4 h-4 rounded border-line-strong bg-card text-brand-600 focus:ring-brand-500"
                                       :checked="allSelected" @change="toggleAll()">
                                <span class="text-sm font-medium text-fg">تحديد الكل</span>
                            </label>

                            <span class="text-xs text-fg-muted tnum ms-auto">
                                <span x-text="selected.length"></span> محدد من {{ count($result->items) }}
                            </span>
                        </div>

                        {{-- القائمة --}}
                        <div class="flex-1 overflow-y-auto px-5 py-4 space-y-2.5">
                            @forelse ($result->items as $item)
                                @php $key = $item->key(); @endphp

                                <label
                                    class="flex gap-3 rounded-xl border p-3 cursor-pointer transition"
                                    :class="selected.includes(@js($key))
                                        ? 'border-brand-500 ring-1 ring-brand-500 bg-brand-50/50 dark:bg-brand-500/5'
                                        : 'border-line hover:border-brand-300'"
                                >
                                    <input type="checkbox" value="{{ $key }}" x-model="selected"
                                           class="mt-0.5 w-4 h-4 shrink-0 rounded border-line-strong bg-card text-brand-600 focus:ring-brand-500">

                                    @if ($image = ($item->imageUrls[0] ?? null))
                                        <img src="{{ $image }}" alt="" loading="lazy" decoding="async"
                                             class="w-14 h-14 shrink-0 rounded-lg object-cover border border-line bg-muted">
                                    @else
                                        <span class="grid place-items-center w-14 h-14 shrink-0 rounded-lg bg-muted border border-line text-fg-subtle">
                                            <x-icon name="package" class="w-5 h-5" />
                                        </span>
                                    @endif

                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm font-semibold text-fg line-clamp-1">{{ $item->title }}</span>

                                        @if ($item->price !== null)
                                            <span class="block text-sm font-bold text-brand-700 dark:text-brand-400 tnum mt-0.5">
                                                {{ rtrim(rtrim(number_format($item->price, 2), '0'), '.') }}
                                                {{ $item->currency ?: '' }}
                                            </span>
                                        @endif

                                        @if ($item->summary)
                                            <span class="block text-xs text-fg-muted line-clamp-2 mt-1 leading-relaxed">
                                                {{ Str::limit($item->summary, 160) }}
                                            </span>
                                        @endif

                                        <span class="block text-[11px] text-fg-subtle truncate mt-1.5" dir="ltr">{{ $item->url }}</span>
                                    </span>
                                </label>
                            @empty
                                <p class="text-sm text-fg-muted text-center py-6">لم نتمكن من قراءة أي منتج من هذا المتجر.</p>
                            @endforelse

                            @if ($result->pendingUrls !== [])
                                <div class="flex flex-wrap items-center justify-center gap-2 pt-2">
                                    <button type="submit" form="import-more" class="btn-secondary btn-sm">
                                        <x-icon name="refresh" class="w-4 h-4" />
                                        <span>تحميل المزيد (متبقٍ {{ count($result->pendingUrls) }})</span>
                                    </button>
                                </div>
                            @endif
                        </div>

                        {{-- إفصاح التكلفة --}}
                        <div class="px-5 pt-3">
                            <div class="alert-info text-xs">
                                <x-icon name="info" class="w-4 h-4 shrink-0 mt-px" />
                                <p class="leading-relaxed">
                                    بعد الحفظ تُجهَّز بيانات كل منتج بالذكاء الاصطناعي (المميزات، المواصفات، الجمهور، الوصف المختصر)
                                    بتكلفة {{ $importCost }} نقطة لكل منتج — الإجمالي
                                    <span class="font-bold tnum" x-text="selected.length * cost"></span> نقطة،
                                    ولا يُخصم شيء عن أي منتج تفشل معالجته.
                                </p>
                            </div>
                        </div>

                        <footer class="flex items-center gap-2.5 px-5 py-4">
                            <button type="submit" class="btn-primary" :disabled="selected.length === 0">
                                <span x-text="`استيراد المحدد (${selected.length})`">استيراد المحدد</span>
                            </button>

                            <a href="{{ route('products.index', ['import_start' => 1]) }}" class="btn-ghost">
                                <span>تحليل متجر آخر</span>
                            </a>
                        </footer>
                    </form>

                    {{-- نموذج منفصل: لا يجوز تعشيش نموذج داخل نموذج --}}
                    <form id="import-more" method="POST" action="{{ route('products.import.more', $scan) }}" class="hidden">
                        @csrf
                    </form>
                @endif

                {{-- ================= 4) تمّ ================= --}}
                @if ($importDone)
                    @php $summary = $importing->result ?? []; @endphp

                    <div class="p-5">
                        <div class="flex items-start gap-3">
                            <span class="grid place-items-center w-11 h-11 shrink-0 rounded-xl bg-success-soft text-success-fg">
                                <x-icon name="check-circle" class="w-5 h-5" />
                            </span>

                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold text-fg tnum">
                                    استُورد {{ (int) ($summary['imported'] ?? 0) }} منتجاً،
                                    وجُهزت ورقة مرجعية لـ {{ (int) ($summary['enriched'] ?? 0) }} منها.
                                </p>

                                @if (! empty($summary['failed']))
                                    <p class="text-xs text-warning-fg mt-1.5 leading-relaxed">
                                        تعذّرت معالجة {{ count($summary['failed']) }}، وأُرجعت نقاطها. تجدها في القائمة ببيانات المتجر الخام.
                                    </p>
                                @endif
                            </div>
                        </div>

                        <div class="flex items-center gap-2.5 mt-5 pt-4 border-t border-line">
                            <a href="{{ route('products.index') }}" class="btn-primary"><span>عرض القائمة</span></a>
                            <a href="{{ route('products.index', ['import_start' => 1]) }}" class="btn-ghost">
                                <span>استيراد متجر آخر</span>
                            </a>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
