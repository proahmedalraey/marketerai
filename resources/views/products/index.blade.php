@extends('layouts.app')
@section('title', 'المنتجات والخدمات')
@section('subtitle', 'كل عنصر يُخزَّن بورقة مرجعية جاهزة للنموذج')

@section('actions')
    {{-- الرأس يُرسم خارج نطاق x-data الخاص بالصفحة، فنتخاطب معه بحدث لا بمتغير --}}
    <button type="button" @click="$dispatch('open-import')" class="btn-secondary btn-sm">
        <x-icon name="store" class="w-4 h-4" />
        <span class="hidden md:inline">استيراد من متجر</span>
        <span class="md:hidden sr-only">استيراد من متجر</span>
    </button>

    <button type="button" @click="$dispatch('open-chooser')" class="btn-primary btn-sm">
        <x-icon name="plus" class="w-4 h-4" />
        <span class="hidden sm:inline">إضافة منتج أو خدمة</span>
        <span class="sm:hidden sr-only">إضافة منتج أو خدمة</span>
    </button>
@endsection

@section('content')

@php
    $activeType = request('type');

    $tabs = [
        ['type' => null,      'label' => 'الكل',    'count' => $counts['all']],
        ['type' => 'good',    'label' => 'السلع',   'count' => $counts['good']],
        ['type' => 'service', 'label' => 'الخدمات', 'count' => $counts['service']],
    ];

    /** البيانات التي تملأ نافذة التعديل — لا نرسم نافذة لكل بطاقة */
    $toPayload = fn ($p) => [
        'id' => $p->id,
        'type' => $p->type->value,
        'title' => $p->title,
        'features' => $p->features,
        'specifications' => $p->specifications,
        'deliverables' => $p->deliverables,
        'audience' => $p->audience,
        'notes' => $p->notes,
        'summary' => $p->summary,
        'price' => $p->price !== null ? (string) $p->price : '',
        'currency' => $p->currency ?? 'SAR',
        'brand_name' => $p->brand_name ?? '',
        'compare_at_price' => $p->compare_at_price !== null ? (string) $p->compare_at_price : '',
        'sale_ends_at' => $p->sale_ends_at?->toDateString() ?? '',
        'stock_status' => $p->stock_status ?? '',
        'rating_value' => $p->rating_value !== null ? (string) (float) $p->rating_value : '',
        'rating_count' => $p->rating_count !== null ? (string) $p->rating_count : '',
        'installment_providers' => array_column((array) $p->installments, 'provider'),
        'installment_count' => (int) (((array) $p->installments)[0]['count'] ?? 4),
        'is_primary' => (bool) $p->is_primary,
        'is_active' => (bool) $p->is_active,
    ];

    $payloads = $products->mapWithKeys(fn ($p) => [$p->id => $toPayload($p)])->all();

    if ($editing) {
        $payloads[$editing->id] = $toPayload($editing);
    }

    /* الحالة الابتدائية: إعادة فتح النافذة بعد فشل التحقق، أو فتحها من الرابط */
    $initial = null;

    if ($errors->any() && old('type')) {
        $initial = ['form' => [
            'mode' => old('_mode', 'create'),
            'id' => old('_id') ?: null,
            'type' => old('type'),
            'data' => [
                'type' => old('type'),
                'title' => old('title', ''),
                'features' => old('features', ''),
                'specifications' => old('specifications', ''),
                'deliverables' => old('deliverables', ''),
                'audience' => old('audience', $currentBrand->audience ?? ''),
                'notes' => old('notes', ''),
                'summary' => old('summary', ''),
                'price' => old('price', ''),
                'currency' => old('currency', 'SAR'),
                'brand_name' => old('brand_name', ''),
                'compare_at_price' => old('compare_at_price', ''),
                'sale_ends_at' => old('sale_ends_at', ''),
                'stock_status' => old('stock_status', ''),
                'rating_value' => old('rating_value', ''),
                'rating_count' => old('rating_count', ''),
                'installment_providers' => (array) old('installment_providers', []),
                'installment_count' => (int) old('installment_count', 4),
                'is_primary' => (bool) old('is_primary'),
                'is_active' => (bool) old('is_active'),
            ],
        ]];
    } elseif ($editing) {
        $initial = ['form' => [
            'mode' => 'edit',
            'id' => $editing->id,
            'type' => $editing->type->value,
            'data' => $toPayload($editing),
        ]];
    } elseif (request()->boolean('add')) {
        $initial = ['chooser' => true];
    }
@endphp

<div
    x-data="productsIndex({
        products: @js($payloads),
        pageIds: @js($products->pluck('id')->all()),
        brandAudience: @js($currentBrand->audience ?? ''),
        singleImportUrl: @js(route('products.import.single')),
        summaryUrl: @js(route('products.summary')),
        initial: @js($initial),
    })"
    @keydown.escape.window="close(); confirmingDelete = false"
    @open-chooser.window="chooser = true"
>

    {{-- ================= شريط الأدوات ================= --}}
    <div class="card p-2.5 flex flex-col sm:flex-row sm:items-center gap-2.5">

        <form method="GET" class="flex-1 min-w-0" role="search" data-no-busy>
            @if ($activeType)
                <input type="hidden" name="type" value="{{ $activeType }}">
            @endif

            <div class="relative">
                <span class="absolute inset-y-0 start-0 grid place-items-center w-11 text-fg-subtle pointer-events-none">
                    <x-icon name="search" class="w-[18px] h-[18px]" />
                </span>

                <label for="product-search" class="sr-only">ابحث في المنتجات والخدمات</label>
                <input
                    id="product-search" name="q" type="search"
                    value="{{ request('q') }}"
                    class="field ps-11 {{ request('q') ? 'pe-11' : '' }} border-transparent bg-muted shadow-none focus:bg-card"
                    placeholder="ابحث باسم المنتج أو الخدمة أو في وصفه…"
                >

                @if (request('q'))
                    <a
                        href="{{ route('products.index', array_filter(['type' => $activeType])) }}"
                        class="absolute inset-y-0 end-0 grid place-items-center w-11 text-fg-subtle hover:text-fg rounded-xl"
                    >
                        <x-icon name="close" class="w-4 h-4" />
                        <span class="sr-only">مسح البحث</span>
                    </a>
                @endif
            </div>
        </form>

        {{-- تبويبات دائمة الظهور: الفلتر المخفي في قائمة منسدلة لا يُستخدم --}}
        <div class="flex items-center gap-1 p-1 rounded-xl bg-muted shrink-0 overflow-x-auto" role="tablist" aria-label="نوع العنصر">
            @foreach ($tabs as $tab)
                @php $isActive = $activeType === $tab['type']; @endphp

                <a
                    href="{{ route('products.index', array_filter(['type' => $tab['type'], 'q' => request('q')])) }}"
                    role="tab"
                    aria-selected="{{ $isActive ? 'true' : 'false' }}"
                    class="flex items-center gap-2 px-3.5 min-h-9 rounded-lg text-sm font-medium whitespace-nowrap transition
                        {{ $isActive ? 'bg-card text-fg shadow-xs' : 'text-fg-muted hover:text-fg' }}"
                >
                    {{ $tab['label'] }}
                    <span class="text-xs tnum {{ $isActive ? 'text-brand-700 dark:text-brand-400' : 'text-fg-subtle' }}">
                        {{ $tab['count'] }}
                    </span>
                </a>
            @endforeach
        </div>

        {{-- ---------- أدوات العرض ---------- --}}
        <div class="flex items-center gap-2 shrink-0" x-cloak>

            {{-- مقبض الحجم: يختفي في القائمة لأنه لا معنى له هناك --}}
            <div
                x-show="view === 'grid'"
                class="hidden sm:flex items-center gap-1.5 px-2 h-9 rounded-xl bg-muted"
                role="group"
                aria-label="حجم البطاقة"
            >
                <button
                    type="button"
                    @click="stepSize(-1)"
                    :disabled="size === 0"
                    class="btn btn-ghost btn-sm btn-icon w-7 h-7 min-h-0 disabled:opacity-30"
                >
                    <x-icon name="minus" class="w-3.5 h-3.5" />
                    <span class="sr-only">تصغير البطاقات</span>
                </button>

                <label class="sr-only" for="card-size">حجم البطاقة</label>
                <input
                    id="card-size" type="range" min="0" max="4" step="1"
                    :value="size"
                    @input="setSize($event.target.value)"
                    :aria-valuetext="sizeLabel"
                    class="card-size-range w-20"
                >

                <button
                    type="button"
                    @click="stepSize(1)"
                    :disabled="size === sizes.length - 1"
                    class="btn btn-ghost btn-sm btn-icon w-7 h-7 min-h-0 disabled:opacity-30"
                >
                    <x-icon name="plus" class="w-3.5 h-3.5" />
                    <span class="sr-only">تكبير البطاقات</span>
                </button>
            </div>

            {{-- مبدّل طريقة العرض --}}
            <div class="flex items-center gap-1 p-1 rounded-xl bg-muted" role="group" aria-label="طريقة العرض">
                @foreach ([['grid', 'grid', 'بطاقات'], ['list', 'list', 'قائمة']] as [$mode, $icon, $label])
                    <button
                        type="button"
                        @click="setView('{{ $mode }}')"
                        :aria-pressed="view === '{{ $mode }}' ? 'true' : 'false'"
                        :class="view === '{{ $mode }}' ? 'bg-card text-fg shadow-xs' : 'text-fg-subtle hover:text-fg'"
                        class="grid place-items-center w-9 h-7 rounded-lg transition"
                        title="{{ $label }}"
                    >
                        <x-icon name="{{ $icon }}" class="w-4 h-4" />
                        <span class="sr-only">{{ $label }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    @if (request('q'))
        <p class="flex items-center gap-2 text-sm text-fg-muted mt-4">
            <x-icon name="filter" class="w-4 h-4" />
            <span class="tnum">{{ $products->total() }} نتيجة لـ «{{ request('q') }}»</span>
        </p>
    @endif

    {{-- ================= الشبكة ================= --}}
    {{-- عدد الأعمدة تحسبه CSS من --card-min، فلا نبدّل فئات أعمدة عند كل تغيير حجم --}}
    <div class="product-grid mt-4" :data-view="view" :data-size="sizeKey" :style="gridStyle">
        @foreach ($products as $product)
            @include('products.partials.card', ['product' => $product])
        @endforeach

        {{-- بطاقة الإضافة تعيش داخل الشبكة لا فوقها: مكان الفعل حيث تنتهي العين --}}
        <button type="button" @click="chooser = true" class="product-add group">
            <span class="grid place-items-center w-10 h-10 shrink-0 rounded-xl bg-muted text-fg-muted transition group-hover:bg-brand-600 group-hover:text-white">
                <x-icon name="plus" class="w-5 h-5" />
            </span>
            <span class="min-w-0">
                <span class="block text-sm font-semibold text-fg">إضافة منتج أو خدمة</span>
                <span class="block text-xs text-fg-subtle leading-relaxed mt-0.5">أضف سلعة أو خدمة جديدة إلى هذا البراند</span>
            </span>
        </button>
    </div>

    @if ($products->isEmpty() && (request('q') || $activeType))
        <div class="mt-4">
            <x-empty-state
                icon="search"
                title="لا نتائج مطابقة"
                description="جرّب كلمة أقصر، أو أزل الفلتر لعرض كل العناصر."
            >
                <a href="{{ route('products.index') }}" class="btn-secondary">
                    <x-icon name="refresh" class="w-4 h-4" />
                    <span>عرض الكل</span>
                </a>
            </x-empty-state>
        </div>
    @endif

    @if ($products->hasPages())
        <nav class="mt-5" aria-label="صفحات المنتجات">{{ $products->links() }}</nav>
    @endif

    @include('products.partials.import-modal')
    @include('products.partials.bulk-bar')
    @include('products.partials.type-chooser')
    @include('products.partials.form-modal')
</div>
@endsection
