@extends('layouts.app')
@section('title', 'الرئيسية')
@section('subtitle', 'كل ما تحتاجه لإدارة علامتك في مكان واحد')

@section('actions')
    <a href="{{ route('content.generator') }}" class="btn-primary btn-sm">
        <x-icon name="sparkles" class="w-4 h-4" />
        <span class="hidden sm:inline">محتوى جديد</span>
        <span class="sm:hidden sr-only">محتوى جديد</span>
    </a>
@endsection

@section('content')

@php
    $jobTypes = [
        'content'         => ['label' => 'كتابة محتوى',       'icon' => 'pen'],
        'image'           => ['label' => 'توليد صورة',        'icon' => 'image'],
        'carousel_images' => ['label' => 'صور كاروسيل',       'icon' => 'layers'],
    ];

    $steps = [
        [
            'done'  => $brand->isReadyForGeneration(),
            'title' => 'عرّف هوية علامتك',
            'text'  => 'ماذا تبيع ولمن وبأي نبرة — منها يرث كل منشور شخصيته.',
            'href'  => route('brand.profile'),
            'cta'   => 'أكمل الهوية',
            'icon'  => 'palette',
        ],
        [
            'done'  => $stats['products'] > 0,
            'title' => 'أضف أول منتج أو خدمة',
            'text'  => 'بلا منتج يبقى المحتوى عاماً وبلا أثر بيعي.',
            'href'  => route('products.create'),
            'cta'   => 'أضف منتجاً',
            'icon'  => 'package',
        ],
        [
            'done'  => $stats['content'] > 0,
            'title' => 'اكتب أول محتوى',
            'text'  => 'اختر الهدف والمنصة وشكل المحتوى، والباقي على المحرك.',
            'href'  => route('content.generator'),
            'cta'   => 'ابدأ الكتابة',
            'icon'  => 'sparkles',
        ],
    ];

    $doneCount  = collect($steps)->where('done', true)->count();
    $setupDone  = $doneCount === count($steps);
    $nextStep   = collect($steps)->firstWhere('done', false);

    $used = max($stats['allowance'] - $stats['credits'], 0);
    $pct  = $stats['allowance'] ? min(100, round($used / $stats['allowance'] * 100)) : 0;
@endphp

{{-- ================= دليل الإعداد ================= --}}
@unless ($setupDone)
    <section class="card overflow-hidden border-brand-200 dark:border-brand-500/30">
        <div class="flex flex-wrap items-center gap-4 px-5 py-4 bg-brand-50 dark:bg-brand-500/10 border-b border-brand-200 dark:border-brand-500/20">
            <div class="min-w-0 flex-1">
                <h2 class="text-[15px] font-bold text-brand-800 dark:text-brand-200">أكمل إعداد حسابك</h2>
                <p class="text-sm text-brand-700/80 dark:text-brand-300/80 mt-0.5">
                    كل خطوة هنا ترفع جودة المخرجات بفارق تلاحظه من أول توليد.
                </p>
            </div>

            <div class="flex items-center gap-3 shrink-0">
                <div
                    class="w-28 h-1.5 rounded-full bg-brand-200 dark:bg-brand-500/25 overflow-hidden"
                    role="progressbar"
                    aria-valuenow="{{ $doneCount }}" aria-valuemin="0" aria-valuemax="{{ count($steps) }}"
                    aria-label="تقدّم الإعداد"
                >
                    <div class="h-full bg-brand-600 rounded-full transition-[width] duration-500 ease-out"
                         style="width: {{ round($doneCount / count($steps) * 100) }}%"></div>
                </div>
                <span class="text-sm font-bold text-brand-800 dark:text-brand-200 tnum">
                    {{ $doneCount }}/{{ count($steps) }}
                </span>
            </div>
        </div>

        <ol class="divide-y divide-line">
            @foreach ($steps as $i => $step)
                <li class="flex items-center gap-3.5 px-5 py-3.5 {{ $step['done'] ? 'opacity-60' : '' }}">
                    <span class="grid place-items-center w-8 h-8 shrink-0 rounded-full
                        {{ $step['done'] ? 'bg-success-soft text-success-fg' : 'bg-muted text-fg-subtle' }}">
                        @if ($step['done'])
                            <x-icon name="check" class="w-4 h-4" label="مكتملة" />
                        @else
                            <span class="text-xs font-bold tnum">{{ $i + 1 }}</span>
                        @endif
                    </span>

                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-fg {{ $step['done'] ? 'line-through decoration-1' : '' }}">
                            {{ $step['title'] }}
                        </p>
                        @unless ($step['done'])
                            <p class="text-xs text-fg-muted mt-0.5 leading-relaxed">{{ $step['text'] }}</p>
                        @endunless
                    </div>

                    @unless ($step['done'])
                        <a href="{{ $step['href'] }}"
                           class="{{ $step === $nextStep ? 'btn-primary' : 'btn-secondary' }} btn-sm shrink-0">
                            <span>{{ $step['cta'] }}</span>
                            <x-icon name="chevron-left" class="w-3.5 h-3.5" />
                        </a>
                    @endunless
                </li>
            @endforeach
        </ol>
    </section>
@endunless

{{-- ================= المؤشرات ================= --}}
<section aria-label="مؤشرات الحساب">
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3">
        <x-stat
            label="منتجات وخدمات" :value="number_format($stats['products'])"
            icon="package" tone="neutral" :href="route('products.index')"
        />
        <x-stat
            label="محتوى مُنشأ" :value="number_format($stats['content'])"
            icon="pen" tone="neutral" :href="route('content.plan')"
        />
        <x-stat
            label="صور مصمّمة" :value="number_format($stats['images'])"
            icon="image" tone="neutral" :href="route('studio.index')"
        />
        <x-stat
            label="جاهز للنشر" :value="number_format($readyCount)"
            icon="check-circle" tone="success" :href="route('content.plan', ['status' => 'ready'])"
        />
        <x-stat
            label="النقاط المتبقية" :value="\App\Support\Credits::format($stats['credits'])"
            icon="coins" tone="{{ $pct >= 80 ? 'warning' : 'brand' }}"
            hint="استُهلك {{ \App\Support\Credits::format($used) }} من {{ \App\Support\Credits::format($stats['allowance']) }}"
            class="col-span-2 lg:col-span-1"
        />
    </div>
</section>

{{-- ================= الصفّان ================= --}}
<div class="grid lg:grid-cols-3 gap-5 items-start">

    {{-- آخر ما أُنشئ --}}
    <div class="lg:col-span-2 space-y-3">
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-[15px] font-bold text-fg">آخر ما أُنشئ</h2>

            @if ($recentContent->isNotEmpty())
                <a href="{{ route('content.plan') }}"
                   class="inline-flex items-center gap-1 py-1 -my-1 text-sm font-medium text-brand-700 dark:text-brand-400 hover:underline underline-offset-4">
                    الخطة كاملة
                    <x-icon name="chevron-left" class="w-4 h-4" />
                </a>
            @endif
        </div>

        @forelse ($recentContent as $item)
            <a href="{{ route('content.show', $item) }}" class="card-interactive block p-4">
                <div class="flex flex-wrap items-center gap-2 mb-2.5">
                    <span class="{{ $item->status->chipClasses() }}">
                        <x-icon :name="$item->status->icon()" class="w-3.5 h-3.5" />
                        {{ $item->status->label() }}
                    </span>

                    <span class="chip-neutral">
                        <x-icon :name="$item->format->icon()" class="w-3.5 h-3.5" />
                        {{ $item->format->label() }}
                    </span>

                    <span class="text-xs text-fg-subtle">{{ $item->platformLabel() }}</span>

                    <time class="text-xs text-fg-subtle ms-auto" datetime="{{ $item->created_at->toIso8601String() }}">
                        {{ $item->created_at->diffForHumans() }}
                    </time>
                </div>

                <p class="text-sm text-fg-muted leading-relaxed line-clamp-2">
                    {{ Str::limit($item->caption, 180) }}
                </p>

                @if ($item->product)
                    <p class="flex items-center gap-1.5 text-xs text-fg-subtle mt-2.5">
                        <x-icon name="package" class="w-3.5 h-3.5" />
                        {{ $item->product->title }}
                    </p>
                @endif
            </a>
        @empty
            <x-empty-state
                icon="pen"
                title="ما أنشأت محتوى بعد"
                description="اختر هدفاً ومنصة وقالباً، وسيتولى المحرك صياغة المنشور بنبرة علامتك."
            >
                <a href="{{ route('content.generator') }}" class="btn-primary">
                    <x-icon name="sparkles" class="w-4 h-4" />
                    <span>ابدأ أول توليد</span>
                </a>
            </x-empty-state>
        @endforelse
    </div>

    {{-- العمود الجانبي --}}
    <aside class="space-y-4">

        <x-section title="مهام قيد التنفيذ" icon="refresh">
            @forelse ($runningJobs as $job)
                @php $meta = $jobTypes[$job->type] ?? ['label' => $job->type, 'icon' => 'bot']; @endphp

                <div class="flex items-center gap-3 py-2.5 border-b border-line last:border-0 last:pb-0 first:pt-0">
                    <span class="grid place-items-center w-8 h-8 shrink-0 rounded-lg bg-info-soft text-info-fg">
                        <x-icon :name="$meta['icon']" class="w-4 h-4" />
                    </span>

                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-fg truncate">{{ $meta['label'] }}</p>
                        @if ($job->children_total > 1)
                            <p class="text-xs text-fg-subtle tnum">{{ $job->children_done }} من {{ $job->children_total }}</p>
                        @endif
                    </div>

                    <span class="chip-info">
                        <span class="w-1.5 h-1.5 rounded-full bg-current motion-safe:animate-pulse-dot" aria-hidden="true"></span>
                        {{ $job->status->label() }}
                    </span>
                </div>
            @empty
                <p class="flex items-center gap-2 text-sm text-fg-subtle py-1">
                    <x-icon name="check-circle" class="w-4 h-4" />
                    لا توجد مهام جارية.
                </p>
            @endforelse
        </x-section>

        <x-section title="استهلاك النقاط" icon="coins">
            <div class="flex items-end justify-between gap-2 mb-3">
                <span class="text-2xl font-bold text-fg tnum leading-none">{{ \App\Support\Credits::format($stats['credits']) }}</span>
                <span class="text-xs text-fg-subtle">متبقية من {{ \App\Support\Credits::format($stats['allowance']) }}</span>
            </div>

            <div
                class="h-2 rounded-full bg-muted overflow-hidden"
                role="progressbar"
                aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100"
                aria-label="نسبة النقاط المستهلكة هذا الشهر"
            >
                <div class="h-full rounded-full transition-[width] duration-700 ease-out {{ $pct >= 80 ? 'bg-warning' : 'bg-brand-500' }}"
                     style="width: {{ $pct }}%"></div>
            </div>

            <p class="hint">استُهلك {{ \App\Support\Credits::format($used) }} نقطة ({{ $pct }}%) هذا الشهر.</p>
        </x-section>

        <x-section title="إجراءات سريعة" icon="zap">
            <div class="space-y-2">
                @foreach ([
                    ['content.generator', 'sparkles', 'اكتب محتوى جديداً'],
                    ['studio.index', 'image', 'صمّم صورة'],
                    ['products.create', 'plus', 'أضف منتجاً'],
                ] as [$route, $icon, $label])
                    <a href="{{ route($route) }}"
                       class="flex items-center gap-3 rounded-xl border border-line px-3 min-h-11 text-sm font-medium text-fg
                              transition hover:border-brand-300 hover:bg-brand-50/50 dark:hover:bg-brand-500/5">
                        <x-icon :name="$icon" class="w-[18px] h-[18px] text-fg-subtle" />
                        <span class="flex-1">{{ $label }}</span>
                        <x-icon name="chevron-left" class="w-4 h-4 text-fg-subtle" />
                    </a>
                @endforeach
            </div>
        </x-section>
    </aside>
</div>
@endsection
