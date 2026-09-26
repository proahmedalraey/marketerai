@extends('layouts.app')
@section('title', 'استوديو الصور')
@section('subtitle', 'الشعار يُركَّب برمجياً بعد التوليد — لا نطلب من النموذج كتابة عربي داخل الصورة')

@section('content')

@php
    // فرز اختيار الدقة+الجودة الأولي: من old() عند رجوع الخادم بخطأ، وإلا الافتراضي الرخيص
    $oldQuality = old('quality', '1k_medium');
    [$initResolution, $initQuality] = str_contains($oldQuality, '_')
        ? explode('_', $oldQuality, 2)
        : ['1k', 'medium'];
    $initRatio = old('aspect_ratio', '1:1');

    // تجميع نسب الأبعاد حسب group لعرضها كأقسام (أفقي/عمودي) كما في التصميم المرجعي
    // preserveKeys=true إلزامي: افتراضياً groupBy تُعيد ترقيم كل مجموعة من 0،
    // فيضيع مفتاح النسبة نفسه ("3:2") الذي نحتاجه لاحقاً للبحث في $ratios[$key]
    $ratioGroups = collect($ratios)
        ->groupBy('group', true)
        ->map(fn ($group) => $group->keys()->all());

    // استعادة المرجع المختار عند رجوع الخادم بخطأ (رصيد غير كافٍ مثلاً)
    $oldReferenceAsset = old('reference_asset_id')
        ? \App\Models\MediaAsset::find(old('reference_asset_id'))
        : null;

    $studioConfig = [
        'resolutions' => $resolutions,
        'qualityLevels' => $qualityLevels,
        'qualityMatrix' => $qualityMatrix,
        'ratios' => $ratios,
        'models' => $studioModels,
        'modelCaps' => $modelCaps,
        'modelsRoutable' => $modelsRoutable,
        'hasOld' => session()->hasOldInput(),
        'defaultModel' => old('model', $defaultModel),
        'uploadUrl' => route('studio.uploads'),
        'enhanceUrl' => $enhanceUrl,
        'toProductUrl' => route('studio.media.to-product', ['mediaAsset' => '__ASSET__']),
        'enhanceCost' => $enhanceCost,
        'prompt' => old('prompt', ''),
        'resolution' => $initResolution,
        'quality' => $initQuality,
        'autoRatio' => $initRatio === '1:1',
        'aspectRatio' => $initRatio,
        'count' => (int) old('count', 1),
        'useBrandIdentity' => (bool) old('use_brand_identity', true),
        'productId' => old('product_id', ''),
        'referenceAssetId' => $oldReferenceAsset?->id,
        'referenceAssetUrl' => $oldReferenceAsset?->url(),
        'referenceMode' => match (true) {
            (bool) $oldReferenceAsset => 'gallery',
            (bool) old('product_id') => 'product',
            default => null,
        },
    ];
@endphp

<div
    x-data="imageStudio(@js($studioConfig))"
    @keydown.escape.window="closePopovers(); lightbox = null"
    @resize.window.debounce.120ms="popover && placePopover(popover)"
    class="space-y-5"
>

    @if ($jobUuid = request('job'))
        <div class="card p-4" x-data="jobTracker(@js($jobUuid), { redirectTo: @js(route('studio.index')) })">
            <div class="flex items-center justify-between gap-2 mb-3">
                <h2 class="flex items-center gap-2 text-sm font-bold text-fg">
                    <x-icon name="refresh" class="w-4 h-4 text-brand-600 dark:text-brand-400" x-show="!failed" />
                    <x-icon name="alert" class="w-4 h-4 text-danger" x-show="failed" x-cloak />
                    <span x-text="failed ? 'تعذّر التوليد' : 'جارٍ التوليد'">جارٍ التوليد</span>
                </h2>
                <span class="chip-info" x-text="label">في الانتظار</span>
            </div>

            <div
                class="h-2 rounded-full bg-muted overflow-hidden"
                role="progressbar"
                :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100"
                aria-label="تقدّم توليد الصور"
            >
                <div
                    class="h-full rounded-full transition-[width] duration-500 ease-out"
                    :class="failed ? 'bg-danger' : (timedOut ? 'bg-warning' : 'bg-brand-500')"
                    :style="`width: ${progress}%`"
                ></div>
            </div>

            <p class="mt-2.5 text-xs leading-relaxed" role="status" aria-live="polite">
                <span x-show="!finished" class="text-fg-muted">توليد الصور يستغرق وقتاً أطول من النص — ابقَ في الصفحة.</span>
                <span x-show="finished && !failed && !timedOut" x-cloak class="text-success-fg">اكتمل. نحدّث المعرض…</span>
                <span x-show="failed" x-cloak class="text-danger-fg">
                    <span x-text="error || 'تعذّر التوليد وأُرجعت نقاطك.'"></span>
                    <a href="{{ route('studio.index') }}" class="ms-1 font-semibold underline underline-offset-4">إغلاق</a>
                </span>
                <span x-show="timedOut" x-cloak class="text-warning-fg">يستغرق أطول من المعتاد. نكمل في الخلفية — حدّث الصفحة بعد دقائق. إن تعذّر التوليد تُرجع نقاطك تلقائياً.</span>
            </p>

            {{-- هياكل الانتظار بعدد الصور ونسبتها المطلوبة (من payload المهمة) --}}
            @php
                $trackedGrid = match (true) {
                    $trackedCount === 1 => 'grid-cols-1 max-w-[16rem]',
                    $trackedCount === 2 => 'grid-cols-2 max-w-lg',
                    default => 'grid-cols-2 sm:grid-cols-4',
                };
                [$tw, $th] = array_map('floatval', array_pad(explode(':', $trackedRatio), 2, 1)) + [1, 1];
            @endphp
            <div x-show="!finished" class="grid {{ $trackedGrid }} gap-3 mt-4">
                @for ($i = 0; $i < $trackedCount; $i++)
                    <div class="skeleton rounded-xl" style="aspect-ratio: {{ $tw ?: 1 }} / {{ $th ?: 1 }}"></div>
                @endfor
            </div>
        </div>
    @endif

    {{-- أخطاء التحقق والنقاط يعرضها partials.flash في التخطيط؛ تكرارها هنا كان يُظهر الرسالة مرتين --}}

    <div x-data="{ activeTab: 'gallery' }">
        <div class="flex items-center gap-1 p-1 rounded-xl bg-muted w-fit mb-3" role="tablist">
            <button
                type="button" role="tab" @click="activeTab = 'gallery'"
                :aria-selected="activeTab === 'gallery' ? 'true' : 'false'"
                class="flex items-center gap-2 px-3.5 min-h-9 rounded-lg text-sm transition"
                :class="activeTab === 'gallery' ? 'bg-card text-fg font-bold shadow-sm' : 'text-fg-muted font-medium hover:text-fg'"
            >
                <x-icon name="grid" class="w-4 h-4" />
                المعرض
                @if ($gallery->isNotEmpty())
                    <span class="text-xs tnum" :class="activeTab === 'gallery' ? 'text-brand-700 dark:text-brand-400' : 'text-fg-subtle'">{{ $gallery->count() }}{{ $galleryHasMore ? "+" : "" }}</span>
                @endif
            </button>

            <button
                type="button" role="tab" @click="activeTab = 'plan'"
                :aria-selected="activeTab === 'plan' ? 'true' : 'false'"
                class="flex items-center gap-2 px-3.5 min-h-9 rounded-lg text-sm transition"
                :class="activeTab === 'plan' ? 'bg-card text-fg font-bold shadow-sm' : 'text-fg-muted font-medium hover:text-fg'"
            >
                <x-icon name="calendar" class="w-4 h-4" />
                من خطة المحتوى
                @if ($planItems->isNotEmpty())
                    <span class="text-xs tnum" :class="activeTab === 'plan' ? 'text-brand-700 dark:text-brand-400' : 'text-fg-subtle'">{{ $planItems->count() }}</span>
                @endif
            </button>
        </div>

        <section x-show="activeTab === 'gallery'">
            @include('content.studio._gallery')
        </section>

        <section x-show="activeTab === 'plan'" x-cloak>
            @if ($planItems->isEmpty())
                <x-empty-state icon="calendar" title="لا عناصر جاهزة في الخطة بعد" description="أضف محتوى للخطة الشهرية وسيظهر هنا." />
            @else
                <div class="flex items-center justify-end mb-3">
                    <a href="{{ route('content.plan') }}"
                       class="inline-flex items-center gap-1 py-1 -my-1 text-sm font-medium text-brand-700 dark:text-brand-400 hover:underline underline-offset-4">
                        الخطة كاملة
                        <x-icon name="chevron-left" class="w-4 h-4" />
                    </a>
                </div>

                <div class="grid sm:grid-cols-2 gap-3">
                    @foreach ($planItems->take(6) as $item)
                        <article class="card p-3.5">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="chip-neutral">
                                    <x-icon :name="$item->format->icon()" class="w-3.5 h-3.5" />
                                    {{ $item->format->label() }}
                                </span>
                                <span class="text-[11px] text-fg-subtle">{{ $item->platformLabel() }}</span>
                            </div>

                            <p class="text-xs text-fg-muted line-clamp-2 leading-relaxed">
                                {{ Str::limit($item->caption, 110) }}
                            </p>

                            <div class="flex items-center gap-1 mt-2.5">
                                <a href="{{ route('content.show', $item) }}" class="btn-ghost btn-sm">
                                    <x-icon name="pencil" class="w-3.5 h-3.5" />
                                    <span>فتح</span>
                                </a>

                                @if ($item->format->value === 'carousel')
                                    <form method="POST" action="{{ route('studio.carousel', $item) }}" class="ms-auto">
                                        @csrf
                                        <button type="submit" class="btn-secondary btn-sm">
                                            <x-icon name="image" class="w-3.5 h-3.5" />
                                            <span>توليد الصور</span>
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    </div>

    {{-- مساحة فارغة أسفل الصفحة حتى لا يغطي الشريط العائم آخر صف من المعرض --}}
    <div class="h-24" aria-hidden="true"></div>

    @include('content.studio._toolbar')
    @include('content.studio._reference-picker-modal')
    @include('content.studio._product-picker-modal')

    {{-- رسالة عابرة (المشاركة، النسخ للحافظة) --}}
    <div
        x-show="flash" x-cloak
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 -translate-y-2"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-end="opacity-0"
        class="fixed top-20 inset-x-0 z-[80] flex justify-center px-4 pointer-events-none"
        role="status" aria-live="polite"
    >
        <p
            :class="{
                'bg-success-soft text-success-fg border-success/25': flash?.tone === 'success',
                'bg-info-soft text-info-fg border-info/25': flash?.tone === 'info',
                'bg-danger-soft text-danger-fg border-danger/25': flash?.tone === 'error',
            }"
            class="pointer-events-auto flex items-center gap-2 max-w-md px-4 py-2.5 rounded-xl border shadow-pop text-sm"
        >
            <span x-text="flash?.text"></span>
            <button type="button" @click="flash = null" class="shrink-0 -me-1 p-1 rounded-lg hover:bg-black/5">
                <x-icon name="close" class="w-3.5 h-3.5" />
                <span class="sr-only">إخفاء</span>
            </button>
        </p>
    </div>
    @include('content.studio._lightbox')
</div>
@endsection
