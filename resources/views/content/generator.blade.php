@extends('layouts.app')
@section('title', 'كتابة المحتوى')
@section('subtitle', 'اختر الهدف والمنصة وشكل المحتوى، وأضف ما شئت للدفعة')

@section('content')

@php
    $costs = config('credits.costs', []);

    $writer = [
        'goals' => $goals,
        'products' => $products->mapWithKeys(fn ($p) => [(string) $p->id => $p->title])
            ->put('none', 'بدون منتج — محتوى عام عن العلامة'),
        'platforms' => collect($platforms)->map(fn ($p) => [
            'label' => $p['label'],
            'name' => $p['name'],
            'formats' => $p['formats'],
        ]),
        'formats' => $formats,
        'languages' => $languages,
        'dialects' => $dialects,
        'defaultDialect' => isset($dialects[$currentBrand->dialect ?? '']) ? $currentBrand->dialect : 'saudi',
        // المنتج الأساسي يُختار مسبقاً كما يعد إعداد «المرجع الأساسي» في صفحة المنتجات
        'defaultProduct' => (string) ($products->firstWhere('is_primary', true)?->id ?? ''),
        'balance' => (float) ($currentBrand->credit_balance ?? 0),
        'storageKey' => 'content-writer.batch.'.($currentBrand->id ?? 0),
        'maxBatch' => \App\Http\Controllers\ContentGeneratorController::MAX_BATCH,
        'clearBatch' => (bool) session('batch_sent'),
        'initial' => old('mode') === 'single' ? old('items.0') : null,
    ];

    $running = $jobs->filter(fn ($job) => ! $job->status->isFinished());
    $failed = $jobs->filter(fn ($job) => in_array($job->status->value, ['failed', 'cancelled'], true));
@endphp

<div x-data="contentWriter(@js($writer))" class="space-y-5">

    {{-- إعلان لقارئ الشاشة: ما نقص، وما أُضيف للدفعة --}}
    <p class="sr-only" role="status" aria-live="polite" x-text="announcement"></p>

    {{-- ================= النموذج ================= --}}
    <section class="card p-4 sm:p-6 space-y-3.5" aria-label="إعدادات المحتوى">

        {{-- 1. الهدف --}}
        <div class="select-row" :class="invalid('goal') && 'select-row-invalid'" :data-invalid="invalid('goal')">
            <span class="select-row-label">1. الهدف من المحتوى</span>
            <span class="select-row-value" x-text="goals[form.goal] ?? ''"></span>
            <x-icon name="chevron-down" class="select-row-chevron" />
            <select x-model="form.goal" class="select-row-native" aria-label="الهدف من المحتوى">
                <option value="" disabled hidden></option>
                @foreach ($goals as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        {{-- 2. المنتج --}}
        <div class="select-row" :class="invalid('product') && 'select-row-invalid'" :data-invalid="invalid('product')">
            <span class="select-row-label">2. المنتج</span>
            <span class="select-row-value" x-text="productLabel(form.product)"></span>
            <x-icon name="chevron-down" class="select-row-chevron" />
            <select x-model="form.product" class="select-row-native" aria-label="المنتج">
                <option value="" disabled hidden></option>
                @foreach ($products as $product)
                    <option value="{{ $product->id }}">{{ $product->title }}{{ $product->is_primary ? ' ★' : '' }}</option>
                @endforeach
                <option value="none">بدون منتج — محتوى عام عن العلامة</option>
            </select>
        </div>

        @if ($products->isEmpty())
            <p class="text-xs text-fg-muted -mt-1.5 px-1">
                لا منتجات بعد، فالمحتوى سيكون عاماً عن العلامة.
                <a href="{{ route('products.create') }}" class="font-semibold text-brand-700 dark:text-brand-400 hover:underline underline-offset-4">أضف منتجاً</a>
                لتكتب عن سلعتك بعينها.
            </p>
        @endif

        {{-- 3. المنصة --}}
        <fieldset class="pt-1" :data-invalid="invalid('platform')">
            <legend class="text-[13px] font-bold text-fg mb-2.5">3. المنصة</legend>

            <div class="grid grid-cols-4 lg:grid-cols-8 gap-2 sm:gap-2.5 rounded-xl" :class="invalid('platform') && 'ring-1 ring-danger/40 ring-offset-4 ring-offset-card'">
                @foreach ($platforms as $key => $platform)
                    <label class="platform-tile">
                        <input
                            type="radio" name="writer_platform" value="{{ $key }}" class="sr-only"
                            x-model="form.platform" @change="choosePlatform()"
                        >
                        <x-icon :name="$platform['icon']" />
                        <span class="text-center leading-tight">{{ $platform['label'] }}</span>
                    </label>
                @endforeach
            </div>
        </fieldset>

        {{-- 4. شكل المحتوى: خياراته من المنصة --}}
        <div
            x-show="form.platform" x-cloak
            class="select-row" :class="invalid('format') && 'select-row-invalid'" :data-invalid="invalid('format')"
        >
            <span class="select-row-label">4. شكل المحتوى</span>
            <span class="select-row-value" x-text="formatLabel(form.format)"></span>
            <x-icon name="chevron-down" class="select-row-chevron" />
            <select class="select-row-native" aria-label="شكل المحتوى" @change="chooseFormat($event.target.value)">
                <option value="" disabled hidden :selected="! form.format"></option>
                <template x-for="key in platformFormats" :key="key">
                    <option :value="key" x-text="formats[key].label" :selected="key === form.format"></option>
                </template>
            </select>
        </div>

        {{-- الحقل التابع: مدة الفيديو، مدة القصة، عدد التغريدات --}}
        <div
            x-show="optionMeta" x-cloak
            class="select-row" :class="invalid('option') && 'select-row-invalid'" :data-invalid="invalid('option')"
        >
            <span class="select-row-label" x-text="optionMeta?.label"></span>
            <span class="select-row-value" x-text="choiceLabel(form.format, form.option)"></span>
            <x-icon name="chevron-down" class="select-row-chevron" />
            <select class="select-row-native" :aria-label="optionMeta?.label" @change="form.option = $event.target.value">
                <option value="" disabled hidden :selected="! form.option"></option>
                <template x-for="choice in optionMeta?.choices ?? []" :key="choice.value">
                    <option :value="choice.value" x-text="choice.label" :selected="choice.value === form.option"></option>
                </template>
            </select>
        </div>

        {{-- 5. اللغة --}}
        <div class="select-row" :class="invalid('language') && 'select-row-invalid'" :data-invalid="invalid('language')">
            <span class="select-row-label">5. اللغة</span>
            <span class="select-row-value" x-text="languages[form.language] ?? ''"></span>
            <x-icon name="chevron-down" class="select-row-chevron" />
            <select x-model="form.language" class="select-row-native" aria-label="اللغة">
                @foreach ($languages as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        {{-- 6. اللهجة: مع العربية وحدها، وافتراضها لهجة العلامة --}}
        <div
            x-show="needsDialect" x-cloak
            class="select-row" :class="invalid('dialect') && 'select-row-invalid'" :data-invalid="invalid('dialect')"
        >
            <span class="select-row-label">6. اللهجة</span>
            <span class="select-row-value" x-text="dialects[form.dialect] ?? ''"></span>
            <x-icon name="chevron-down" class="select-row-chevron" />
            <select x-model="form.dialect" class="select-row-native" aria-label="اللهجة">
                <option value="" disabled hidden></option>
                @foreach ($dialects as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        {{-- سكربت التصوير: لأشكال الفيديو والستوري --}}
        <label x-show="canFilm" x-cloak class="flex items-start gap-3 rounded-xl border border-line bg-muted/60 p-4 cursor-pointer">
            <input type="checkbox" x-model="form.filming" class="mt-0.5 w-5 h-5 shrink-0 rounded border-line-strong text-fg focus:ring-fg">
            <span class="min-w-0">
                <span class="block text-sm font-bold text-fg">إضافة سكريبت التصوير والمشهد</span>
                <span class="block text-xs text-fg-muted mt-1 leading-relaxed">تفعيل هذا الخيار سيضيف توجيهات تفصيلية للتصوير مع وصف المشهد المناسب للمحتوى</span>
            </span>
        </label>

        <p x-show="showErrors && ! complete" x-cloak class="error-text" role="alert">
            <x-icon name="alert-circle" class="w-3.5 h-3.5 mt-px" />
            <span x-text="'أكمل: ' + missing.map((field) => fieldName(field)).join('، ')"></span>
        </p>

        <p x-show="notice" x-cloak class="alert-warning text-sm" role="status">
            <x-icon name="alert" class="w-4 h-4 shrink-0 mt-px" />
            <span class="flex-1" x-text="notice"></span>
        </p>

        {{-- ================= الأزرار ================= --}}
        <form
            x-ref="form" method="POST" action="{{ route('content.generate') }}"
            class="grid gap-2.5 pt-2"
            :class="batch.length ? 'sm:grid-cols-3' : 'sm:grid-cols-[1fr_2fr]'"
        >
            @csrf
            <input type="hidden" name="mode" :value="mode">

            <template x-for="(item, i) in outgoing" :key="i">
                <div class="hidden">
                    <input type="hidden" :name="`items[${i}][goal]`" :value="item.goal">
                    <input type="hidden" :name="`items[${i}][product_id]`" :value="item.product_id">
                    <input type="hidden" :name="`items[${i}][platform]`" :value="item.platform">
                    <input type="hidden" :name="`items[${i}][format]`" :value="item.format">
                    <input type="hidden" :name="`items[${i}][option]`" :value="item.option">
                    <input type="hidden" :name="`items[${i}][language]`" :value="item.language">
                    <input type="hidden" :name="`items[${i}][dialect]`" :value="item.dialect">
                    <input type="hidden" :name="`items[${i}][filming]`" :value="item.filming ? 1 : 0">
                </div>
            </template>

            <button
                type="button" @click="addToBatch()"
                class="btn-secondary btn-lg"
                :class="complete ? 'text-fg' : 'text-fg-subtle'"
            >
                <x-icon name="plus" class="w-[18px] h-[18px]" />
                <span>إضافة للدفعة</span>
            </button>

            <button
                type="submit" x-ref="singleButton" @click.prevent="generateSingle()"
                class="btn-lg"
                :class="batch.length ? 'btn-secondary' : 'btn-dark'"
            >
                <x-icon name="sparkles" class="w-[18px] h-[18px]" x-show="! batch.length" />
                <span>إنشاء المحتوى</span>
                <span x-show="singleCost && ! batch.length" x-cloak class="text-xs font-normal opacity-75 tnum" x-text="`· ${singleCost} نقطة`"></span>
            </button>

            <button
                type="submit" x-ref="batchButton" @click.prevent="generateBatch()"
                x-show="batch.length" x-cloak
                class="btn-dark btn-lg"
            >
                <x-icon name="zap" class="w-[18px] h-[18px]" />
                <span x-text="`إنشاء كامل الدفعة (${batch.length} محتوى)`">إنشاء كامل الدفعة</span>
            </button>
        </form>
    </section>

    {{-- ================= الدفعة ================= --}}
    <section x-show="batch.length" x-cloak x-ref="batch" class="rounded-2xl border border-line bg-muted/50 p-4 sm:p-5 space-y-3 scroll-mt-24" aria-labelledby="batch-title">
        <header class="flex flex-wrap items-center gap-x-4 gap-y-1.5">
            <h2 id="batch-title" class="flex items-center gap-2 text-[15px] font-bold text-fg">
                <x-icon name="clipboard" class="w-5 h-5" />
                الدفعة الجاهزة للإنشاء
            </h2>

            <span class="ms-auto text-xs text-fg-muted tnum" x-text="`${batch.length} إعداد • ${batch.length} محتوى إجمالي`"></span>
            <button type="button" @click="clearBatch()" class="text-xs font-medium text-fg-muted hover:text-danger-fg">مسح الكل</button>
        </header>

        <ol class="space-y-2.5">
            <template x-for="(item, i) in batch" :key="item.key">
                <li class="card p-4">
                    <div class="flex items-start gap-3">
                        <span class="grid place-items-center min-w-8 h-8 px-1.5 rounded-lg bg-fg text-fg-inverse text-xs font-bold tnum" x-text="`#${i + 1}`"></span>
                        <h3 class="flex-1 min-w-0 pt-1 text-[15px] font-bold text-fg" x-text="goals[item.goal]"></h3>
                        <button type="button" @click="removeFromBatch(i)" class="btn-ghost btn-sm btn-icon text-fg-subtle hover:text-danger-fg hover:bg-danger-soft" :aria-label="`حذف الإعداد ${i + 1} من الدفعة`">
                            <x-icon name="trash" class="w-4 h-4" />
                        </button>
                    </div>

                    <div class="flex flex-wrap items-center gap-1.5 mt-2.5 text-xs">
                        <span class="chip-neutral rounded-md" x-text="item.product === 'none' ? 'بدون منتج' : `المنتج: ${productLabel(item.product)}`"></span>
                        <span class="chip rounded-md bg-fg text-fg-inverse font-semibold" dir="ltr" x-text="platforms[item.platform]?.name"></span>
                        <span class="chip-neutral rounded-md" x-text="`نوع المحتوى: ${formatLabel(item.format)}`"></span>
                        <span class="chip-neutral rounded-md">العدد: 1</span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 mt-2.5">
                        <span class="text-xs text-fg-subtle" x-text="[languages[item.language], dialects[item.dialect], choiceLabel(item.format, item.option)].filter(Boolean).join(' • ')"></span>
                        <span x-show="item.filming" class="chip-neutral rounded-md ms-auto text-[11px]">
                            <x-icon name="camera" class="w-3.5 h-3.5" />
                            مع أسلوب التصوير
                        </span>
                    </div>
                </li>
            </template>
        </ol>

        <div class="card flex flex-wrap items-center justify-between gap-3 px-5 py-4">
            <span class="text-sm text-fg-muted">إجمالي النقاط المطلوبة:</span>
            <span class="text-2xl font-bold text-fg tnum" x-text="`${batchCost} نقطة`"></span>
            <p x-show="batchTooExpensive" x-cloak class="w-full text-xs text-warning-fg">
                رصيدك الحالي {{ \App\Support\Credits::format($currentBrand->credit_balance ?? 0) }} نقطة لا يكفي الدفعة كلها. احذف بعض الإعدادات أو أنشئها على مراحل.
            </p>
        </div>
    </section>

</div>

{{-- ================= الكتابة الجارية ================= --}}
@if ($running->isNotEmpty())
    <section class="card p-5" x-data="writerJobs(@js($running->pluck('uuid')->values()))" aria-labelledby="running-title">
        <div class="flex items-center justify-between gap-3 mb-3">
            <h2 id="running-title" class="flex items-center gap-2 text-sm font-bold text-fg">
                <x-icon name="sparkles" class="w-4 h-4 text-brand-600 dark:text-brand-400" />
                {{ $running->count() === 1 ? 'نكتب المحتوى' : 'نكتب '.$running->count().' محتويات' }}
            </h2>
            <span class="chip-info tnum" x-text="`${done} من ${total}`"></span>
        </div>

        <div class="h-2 rounded-full bg-muted overflow-hidden" role="progressbar" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100" aria-label="تقدّم الكتابة">
            <div class="h-full rounded-full bg-brand-500 transition-[width] duration-500 ease-out" :style="`width: ${progress}%`"></div>
        </div>

        <p class="mt-2.5 text-xs leading-relaxed" role="status" aria-live="polite">
            <span x-show="! timedOut" class="text-fg-muted">نفحص كل محتوى ونصحّحه مجاناً إن ذكر ما لم تذكره أنت. تظهر النتائج هنا حين تجهز.</span>
            <span x-show="timedOut" x-cloak class="text-warning-fg">يستغرق أطول من المعتاد. نكمل في الخلفية — حدّث الصفحة بعد دقائق. إن تعذّرت الكتابة تُرجع نقاطك تلقائياً.</span>
        </p>
    </section>
@endif

@foreach ($failed as $job)
    <div class="alert-danger" role="alert">
        <x-icon name="alert-circle" class="w-5 h-5 shrink-0 mt-px" />
        <p class="flex-1 leading-relaxed">
            {{ $job->error ?: 'تعذّرت كتابة محتوى من الدفعة.' }}
            {{ ($job->payload['variant'] ?? null) ? '('.\App\Services\Content\ContentFormats::label($job->payload['variant']).' على '.config('content.platforms.'.($job->payload['platform'] ?? '').'.label').')' : '' }}
            أُرجعت نقاطه كاملة.
        </p>
    </div>
@endforeach

{{-- ================= محتوى جاهز للنشر ================= --}}
@if ($drafts->isNotEmpty())
    <section x-data="draftPager({{ $drafts->count() }}, {{ $focusIndex }})" class="space-y-3 scroll-mt-24" aria-labelledby="drafts-title">
        <header class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h2 id="drafts-title" class="flex items-center gap-2 text-lg font-bold text-fg">
                    محتوى جاهز للنشر
                    <x-icon name="sparkles" class="w-5 h-5 text-brand-600 dark:text-brand-400" />
                </h2>
                <p class="text-xs text-fg-subtle mt-0.5 tnum">
                    المحتوى <span x-text="page + 1">1</span> من {{ $drafts->count() }}
                    — يبقى هنا حتى تضيفه للخطة الشهرية أو تحذفه.
                </p>
            </div>

            @include('content.partials.draft-pager')
        </header>

        @foreach ($drafts as $i => $item)
            <div x-show="page === {{ $i }}" @if ($i !== $focusIndex) x-cloak @endif>
                @include('content.partials.draft-card', ['item' => $item, 'suggested' => $suggestedDates[$i] ?? null, 'costs' => $costs])
            </div>
        @endforeach

        @if ($drafts->count() > 1)
            <div class="flex justify-center pt-1">
                @include('content.partials.draft-pager')
            </div>
        @endif
    </section>
@endif
@endsection
