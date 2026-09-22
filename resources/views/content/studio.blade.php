@extends('layouts.app')
@section('title', 'استوديو الصور')
@section('subtitle', 'الشعار يُركَّب برمجياً بعد التوليد — لا نطلب من النموذج كتابة عربي داخل الصورة')

@section('content')

@php
    // أبعاد معاينة صغيرة لكل نسبة: الشكل يُفهم أسرع من الرقم
    $ratioPreview = [
        '1:1'  => ['w' => 22, 'h' => 22],
        '4:5'  => ['w' => 19, 'h' => 24],
        '9:16' => ['w' => 14, 'h' => 25],
        '16:9' => ['w' => 26, 'h' => 15],
        '3:4'  => ['w' => 19, 'h' => 25],
    ];

    $qualityCosts = collect($qualities)->map(fn ($tier) => (int) ($tier['credits'] ?? 1));
@endphp

<div class="grid lg:grid-cols-[21rem_minmax(0,1fr)] gap-5 items-start">

    {{-- ================= لوحة التوليد ================= --}}
    <form
        method="POST" action="{{ route('studio.generate') }}"
        class="lg:sticky lg:top-24"
        x-data="{
            quality: @js(old('quality', 'standard_1k')),
            count: {{ (int) old('count', 1) }},
            costs: @js($qualityCosts),
            get total() { return (this.costs[this.quality] ?? 1) * Math.max(1, Math.min(4, this.count || 1)); },
        }"
    >
        @csrf

        <x-section title="صورة جديدة" icon="sparkles">
            <div class="space-y-4">

                <x-field
                    label="وصف الصورة" name="prompt" required
                    hint="صِف المشهد والإضاءة والخلفية. التفصيل هنا أهم من طول النص."
                >
                    <textarea
                        id="prompt" name="prompt" rows="4" required maxlength="1500"
                        class="field @error('prompt') field-invalid @enderror"
                        placeholder="مثال: كوب لاتيه على طاولة خشبية بإضاءة صباحية طبيعية، خلفية مقهى غير واضحة"
                    >{{ old('prompt') }}</textarea>
                </x-field>

                <x-field
                    label="منتج مرجعي" name="product_id" optional
                    hint="اختيار منتج له صورة يجعل النموذج يحافظ على شكله الحقيقي."
                >
                    <select id="product_id" name="product_id" class="field">
                        <option value="">بلا مرجع</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}" @selected(old('product_id') == $product->id)>
                                {{ $product->title }}
                            </option>
                        @endforeach
                    </select>
                </x-field>

                <fieldset>
                    <legend class="label">نسبة الأبعاد</legend>

                    <div class="grid grid-cols-5 gap-1.5">
                        @foreach ($ratios as $key => $ratio)
                            @php $box = $ratioPreview[$key] ?? ['w' => 22, 'h' => 22]; @endphp

                            <label class="choice flex-col items-center gap-1.5 px-1 py-2.5"
                                   title="{{ $ratio['label'] }}">
                                <input
                                    type="radio" name="aspect_ratio" value="{{ $key }}" class="sr-only"
                                    @checked(old('aspect_ratio', '1:1') === $key)
                                >
                                <span class="grid place-items-center h-7">
                                    <span
                                        class="block rounded-[3px] border-2 border-current text-fg-subtle"
                                        style="width: {{ $box['w'] }}px; height: {{ $box['h'] }}px"
                                        aria-hidden="true"
                                    ></span>
                                </span>
                                <span class="text-[10px] font-medium text-fg tnum" dir="ltr">{{ $key }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <x-field label="الجودة" for="quality">
                    <select id="quality" name="quality" class="field" x-model="quality">
                        @foreach ($qualities as $key => $tier)
                            <option value="{{ $key }}">{{ $tier['label'] }} — {{ $tier['credits'] }} نقطة</option>
                        @endforeach
                    </select>
                </x-field>

                <x-field label="عدد الصور" name="count">
                    <input
                        id="count" name="count" type="number" min="1" max="4"
                        inputmode="numeric" dir="ltr"
                        class="field tnum" x-model.number="count" value="{{ old('count', 1) }}"
                    >
                </x-field>

                <label class="choice items-start">
                    <input type="hidden" name="use_brand_identity" value="0">
                    <input
                        type="checkbox" name="use_brand_identity" value="1"
                        class="mt-0.5 w-4 h-4 rounded border-line-strong bg-card text-brand-600 focus:ring-brand-500"
                        @checked(old('use_brand_identity', true))
                    >
                    <span class="min-w-0">
                        <span class="block text-sm font-medium text-fg">تفعيل الهوية البصرية</span>
                        <span class="block text-xs text-fg-muted mt-0.5 leading-relaxed">
                            يحقن ألوان علامتك ونمطك البصري في البرومبت.
                        </span>
                    </span>
                </label>

                <div class="pt-4 border-t border-line">
                    <div class="flex items-center justify-between gap-3 mb-3">
                        <span class="text-sm text-fg-muted">التكلفة</span>
                        <span class="flex items-baseline gap-1.5">
                            <span class="text-lg font-bold text-fg tnum" x-text="total"></span>
                            <span class="text-xs text-fg-subtle">نقطة</span>
                        </span>
                    </div>

                    <button type="submit" class="btn-primary w-full">
                        <span class="inline-flex items-center gap-2">
                            <x-icon name="sparkles" class="w-4 h-4" />
                            توليد
                        </span>
                    </button>
                </div>
            </div>
        </x-section>
    </form>

    {{-- ================= النتائج ================= --}}
    <div class="space-y-6 min-w-0">

        @if ($jobUuid = request('job'))
            <div class="card p-4" x-data="jobTracker(@js($jobUuid), { redirectTo: @js(route('studio.index')) })">
                <div class="flex items-center justify-between gap-2 mb-3">
                    <h2 class="flex items-center gap-2 text-sm font-bold text-fg">
                        <x-icon name="refresh" class="w-4 h-4 text-brand-600 dark:text-brand-400" />
                        جارٍ التوليد
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
                    <span x-show="failed" x-cloak class="text-danger-fg" x-text="error || 'تعذّر التوليد وأُرجعت نقاطك.'"></span>
                    <span x-show="timedOut" x-cloak class="text-warning-fg">يستغرق أطول من المعتاد. نكمل في الخلفية — حدّث الصفحة بعد دقائق. إن تعذّر التوليد تُرجع نقاطك تلقائياً.</span>
                </p>

                {{-- هياكل انتظار: الفراغ أثناء الانتظار يوحي بالعطل --}}
                <div x-show="!finished" class="grid grid-cols-2 sm:grid-cols-3 gap-3 mt-4">
                    <template x-for="i in 3" :key="i">
                        <div class="skeleton aspect-square rounded-xl"></div>
                    </template>
                </div>
            </div>
        @endif

        <section>
            <h2 class="flex items-center gap-2 text-[15px] font-bold text-fg mb-3">
                <x-icon name="grid" class="w-4 h-4 text-fg-muted" />
                المعرض
                @if ($gallery->isNotEmpty())
                    <span class="text-xs font-normal text-fg-subtle tnum">({{ $gallery->count() }})</span>
                @endif
            </h2>

            @if ($gallery->isEmpty())
                <x-empty-state
                    icon="image"
                    title="ما ولّدت صوراً بعد"
                    description="اكتب وصفاً في اللوحة المجاورة واختر النسبة والجودة، وستظهر النتائج هنا."
                />
            @else
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                    @foreach ($gallery as $asset)
                        <figure class="card overflow-hidden group">
                            <div class="relative">
                                <img
                                    src="{{ $asset->url() }}"
                                    alt="{{ Str::limit($asset->prompt, 100) ?: 'صورة مولّدة' }}"
                                    loading="lazy" decoding="async"
                                    class="aspect-square w-full object-cover bg-muted"
                                >

                                {{-- الإجراءات تظهر بالمرور وبالتركيز معاً: التركيز وحده يخدم لوحة المفاتيح --}}
                                <div class="absolute inset-x-0 bottom-0 flex items-center gap-1.5 p-2
                                            bg-gradient-to-t from-black/70 to-transparent
                                            opacity-0 transition group-hover:opacity-100 group-focus-within:opacity-100">
                                    <a
                                        href="{{ $asset->url() }}" download
                                        class="inline-flex items-center gap-1.5 px-2.5 min-h-9 rounded-lg bg-white/90 text-gray-900 text-xs font-semibold hover:bg-white"
                                    >
                                        <x-icon name="download" class="w-3.5 h-3.5" />
                                        تحميل
                                    </a>

                                    <a
                                        href="{{ $asset->url() }}" target="_blank" rel="noopener"
                                        class="grid place-items-center w-9 h-9 rounded-lg bg-white/90 text-gray-900 hover:bg-white"
                                    >
                                        <x-icon name="external" class="w-3.5 h-3.5" />
                                        <span class="sr-only">فتح بالحجم الكامل</span>
                                    </a>
                                </div>
                            </div>

                            <figcaption class="p-2.5">
                                <p class="text-[11px] text-fg-muted line-clamp-2 leading-relaxed">
                                    {{ Str::limit($asset->prompt, 90) }}
                                </p>
                                <p class="text-[10px] text-fg-subtle mt-1.5 tnum" dir="ltr">
                                    {{ $asset->width }}×{{ $asset->height }}
                                </p>
                            </figcaption>
                        </figure>
                    @endforeach
                </div>
            @endif
        </section>

        @if ($planItems->isNotEmpty())
            <section>
                <div class="flex items-center justify-between gap-3 mb-3">
                    <h2 class="flex items-center gap-2 text-[15px] font-bold text-fg">
                        <x-icon name="calendar" class="w-4 h-4 text-fg-muted" />
                        من خطة المحتوى
                    </h2>

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
            </section>
        @endif
    </div>
</div>
@endsection
