@extends('layouts.public')

@section('title', 'محتوى تسويقي عربي يبيع')

@section('content')

@php
    $features = [
        ['palette', 'حدد هويتك المرئية', 'اضبط نبرتك ولهجتك وجمهورك وكلماتك الممنوعة مرة واحدة، ويرثها كل منشور بعدها.'],
        ['package', 'أضف منتجاتك أو خدماتك', 'احفظ معلومات كل منتج مرة واحدة، أو استوردها من متجرك، واستخدمها في كل منشور.'],
        ['image', 'استوديو الصور', 'ولّد صور منتجاتك من وصفك، وركّب شعارك على النتيجة، وأزل الخلفية بضغطة.'],
        ['calendar', 'خطة محتوى شهرية', 'نظّم وجدول محتواك على تقويم واضح يغطي طوال الشهر.'],
        ['layers', 'منشورات وكاروسيل', 'اطلب منشورًا أو كاروسيل أو ستوري أو سكربت ريلز، بصيغة تناسب كل منصة.'],
        ['mic', 'التعليق الصوتي', 'حوّل نصك إلى صوت عربي بلهجات متعددة وراوٍ تختاره من بين ١٨ صوتًا.'],
        ['shield-check', 'بوابة الصدق', 'كل نص يُفحص بعد الكتابة: لا رقم ولا ادعاء ولا كود خصم لم تذكره أنت.'],
        ['store', 'استيراد من سلة', 'اسحب منتجات متجرك دفعة واحدة بدل إدخالها يدويًا.'],
    ];

    $steps = [
        ['user', 'عرّف علامتك', 'سجّل حسابك وأجب عن أسئلة الهوية: من أنت، ولمن تكتب، وبأي نبرة.'],
        ['package', 'أضف منتجاتك', 'أدخل منتجاتك أو استوردها، ويُبنى لكل منتج مرجع يعتمد عليه النموذج.'],
        ['sparkles', 'اطلب المحتوى', 'اختر الهدف والمنصة، فتصلك مسودة جاهزة بقالب مناسب دون تعقيد.'],
        ['shield-check', 'راجع بوابة الصدق', 'يُنبَّه إلى أي رقم أو ادعاء لم تذكره، فتعدّله قبل النشر.'],
        ['calendar', 'خطّط للشهر', 'أضف المسودات المعتمدة إلى التقويم ورتّبها على أيام الشهر.'],
    ];

    $faq = [
        ['كم عدد المنشورات التي يمكنني إنشاؤها شهريًا؟', 'يعتمد ذلك على نقاط حسابك: لكل عملية تكلفة بالنقاط، فالمنشور أرخص من الكاروسيل، والصورة عالية الجودة أغلى من العادية. ترى رصيدك وتكلفة كل عملية قبل التنفيذ.'],
        ['هل يدعم مسوق AI جميع منصات التواصل؟', 'نكتب بصيغة كل منصة وحدودها: إنستغرام وتيك توك وتويتر (X) وفيسبوك وغيرها. النشر الفعلي على المنصات غير متاح بعد؛ تجدول داخل المنصة وتصدّر خطتك.'],
        ['هل المحتوى المُنشأ حصري لي؟', 'يُكتب من هويتك ومنتجاتك أنت، فيختلف من علامة لأخرى. لا نعدك بأن لا يتشابه نص مع آخر، لذا راجع كل مسودة وأضف لمستك.'],
        ['هل أحتاج خبرة تقنية لاستخدام مسوق AI؟', 'لا. تجيب عن أسئلة بسيطة عن علامتك، وتختار الهدف والمنصة، ثم تستلم المحتوى جاهزًا للمراجعة.'],
        ['هل يمكنني تعديل المحتوى بعد إنشائه؟', 'نعم. كل مسودة قابلة للتعديل، وفي الكاروسيل تعدّل كل شريحة وتطلب إعادة كتابتها بتوجيه منك.'],
        ['هل يخترع النموذج أرقامًا أو عروضًا؟', 'لا يجوز له ذلك. بوابة الصدق تفحص كل نص وتمنع أي رقم أو ادعاء أو كود خصم لم تُدخله في بياناتك.'],
    ];
@endphp

{{-- ================= البطل ================= --}}
<section class="relative overflow-hidden">
    <div class="pointer-events-none absolute inset-0" aria-hidden="true"
         style="background:
            radial-gradient(60rem 28rem at 85% -10%, rgb(var(--brand-200) / .55), transparent 60%),
            radial-gradient(40rem 24rem at 0% 30%, rgb(var(--brand-100) / .5), transparent 60%);"></div>

    <div class="relative mx-auto grid max-w-6xl items-center gap-14 px-5 pb-20 pt-14 lg:grid-cols-[1.05fr_1fr] lg:pt-20">

        <div>
            <span class="inline-flex items-center gap-2 rounded-full border border-brand-200 bg-brand-50 px-3.5 py-1.5 text-xs font-semibold text-brand-700 dark:border-brand-800 dark:bg-brand-900/30 dark:text-brand-300">
                <x-icon name="sparkles" class="h-3.5 w-3.5" /> محتوى يصل. يؤثر. يبيع.
            </span>

            <h1 class="mt-6 text-4xl font-bold leading-[1.35] sm:text-5xl lg:text-[3.4rem]">
                خلّي محتواك يفتح لك<br>
                <span class="text-brand-600 dark:text-brand-400">أبواب المبيعات</span>
            </h1>

            <p class="mt-6 max-w-xl text-lg leading-relaxed text-fg-muted">
                من أفكار متناثرة… إلى محتوى يرفع الأرباح في ٥ خطوات.
                منصة تبتكر لك محتوى يجذب المشاهد، ويترك أثرًا، ويدفعه لاتخاذ قرار الشراء بثقة.
            </p>

            <div class="mt-9 flex flex-wrap items-center gap-3">
                <a href="{{ route('register') }}" class="btn btn-primary btn-lg">
                    ابدأ الآن <x-icon name="arrow-left" class="h-4 w-4" />
                </a>
                <a href="{{ route('login') }}" class="btn btn-secondary btn-lg">لدي حساب بالفعل</a>
            </div>

            <p class="mt-5 flex items-center gap-2 text-sm text-fg-subtle">
                <x-icon name="shield-check" class="h-4 w-4 text-success" />
                يُفحص كل نص قبل أن تراه، فلا أرقام ولا وعود من خارج بياناتك.
            </p>
        </div>

        {{-- توقيع الصفحة: منتج واحد يتحول إلى محتوى، وبوابة الصدق تختم عليه --}}
        <div class="relative mx-auto w-full max-w-md" aria-hidden="true">
            <div class="absolute -inset-4 rounded-[2rem] bg-brand-500/10 blur-2xl"></div>

            <div class="relative space-y-3">
                <div class="card flex items-center gap-3 p-3.5">
                    <span class="grid h-12 w-12 place-items-center rounded-xl bg-brand-100 text-brand-700 dark:bg-brand-900/40 dark:text-brand-300">
                        <x-icon name="package" class="h-6 w-6" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-xs text-fg-subtle">منتج من متجرك</p>
                        <p class="truncate text-sm font-semibold">قهوة مختصة — محمصة الصباح</p>
                    </div>
                    <span class="ms-auto rounded-full bg-muted px-2.5 py-1 text-[11px] font-medium text-fg-muted">هوية + منتج</span>
                </div>

                <div class="mx-auto h-5 w-px bg-gradient-to-b from-brand-400 to-transparent"></div>

                <div class="card p-4 shadow-lg motion-safe:rotate-[-1deg]">
                    <div class="flex items-center gap-2 text-xs text-fg-subtle">
                        <x-icon name="instagram" class="h-4 w-4" /> منشور إنستغرام
                        <span class="ms-auto inline-flex items-center gap-1 rounded-full bg-success-soft px-2.5 py-1 font-semibold text-success-fg">
                            <x-icon name="shield-check" class="h-3.5 w-3.5" /> اجتاز بوابة الصدق
                        </span>
                    </div>
                    <p class="mt-3 text-[15px] leading-relaxed">
                        صباحك يبدأ من أول رشفة ☕<br>
                        حبوب محمّصة طازجة على مهل، لنكهة تبقى معك حتى آخر الفنجان.
                    </p>
                    <p class="mt-2 text-xs font-medium text-brand-600 dark:text-brand-400">#قهوة_مختصة #صباح_الخير</p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="card flex items-center gap-2.5 p-3">
                        <span class="grid h-9 w-9 place-items-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-900/30 dark:text-brand-300"><x-icon name="layers" class="h-[18px] w-[18px]" /></span>
                        <div><p class="text-xs font-semibold">كاروسيل</p><p class="text-[11px] text-fg-subtle">٧ شرائح</p></div>
                    </div>
                    <div class="card flex items-center gap-2.5 p-3">
                        <span class="grid h-9 w-9 place-items-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-900/30 dark:text-brand-300"><x-icon name="calendar" class="h-[18px] w-[18px]" /></span>
                        <div><p class="text-xs font-semibold">الخطة الشهرية</p><p class="text-[11px] text-fg-subtle">جاهزة للجدولة</p></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ================= الجولة ================= --}}
<section class="bg-muted/60 py-20" x-data="{ tab: 0 }">
    <div class="mx-auto max-w-6xl px-5">
        <div class="mx-auto max-w-2xl text-center">
            <h2 class="text-3xl font-bold sm:text-4xl">شاهد مسوق AI يبدع…</h2>
            <p class="mt-3 text-fg-muted">جولة سريعة نستعرض فيها كيف يمكنك تحويل أفكارك إلى محتوى احترافي في دقائق.</p>
        </div>

        <div class="mx-auto mt-10 max-w-4xl">
            <div role="tablist" aria-label="أقسام الجولة" class="mb-4 flex flex-wrap justify-center gap-2">
                @foreach (['منشور', 'كاروسيل', 'صورة', 'خطة الشهر'] as $i => $label)
                    <button type="button" role="tab" @click="tab = {{ $i }}" :aria-selected="tab === {{ $i }}"
                            :class="tab === {{ $i }} ? 'bg-brand-600 text-white border-brand-600' : 'bg-card text-fg-muted border-line hover:border-brand-300'"
                            class="min-h-10 rounded-full border px-5 text-sm font-semibold transition">{{ $label }}</button>
                @endforeach
            </div>

            <div class="card overflow-hidden shadow-xl">
                <div class="flex items-center gap-1.5 border-b border-line bg-muted px-4 py-3" aria-hidden="true">
                    <span class="h-2.5 w-2.5 rounded-full bg-line-strong"></span>
                    <span class="h-2.5 w-2.5 rounded-full bg-line-strong"></span>
                    <span class="h-2.5 w-2.5 rounded-full bg-line-strong"></span>
                </div>

                <div class="grid min-h-[22rem] place-items-center p-6 sm:p-10">
                    {{-- منشور --}}
                    <div x-show="tab === 0" class="w-full max-w-lg space-y-3">
                        <p class="text-xs font-semibold text-fg-subtle">الهدف: زيادة المبيعات · المنصة: إنستغرام</p>
                        <div class="rounded-xl border border-line bg-app p-5 text-[15px] leading-loose">
                            جرّبت قهوتنا مرة؟ اليوم نعطيك سبب ترجع…<br>
                            حبوب مختارة بعناية ومحمّصة على دفعات صغيرة.<br>
                            <span class="font-semibold text-brand-600 dark:text-brand-400">اطلب الآن من المتجر.</span>
                        </div>
                        <p class="flex items-center gap-2 text-xs text-success-fg"><x-icon name="check-circle" class="h-4 w-4" /> لا أرقام أو عروض خارج بياناتك</p>
                    </div>

                    {{-- كاروسيل --}}
                    <div x-show="tab === 1" x-cloak class="flex w-full max-w-lg items-center justify-center gap-3">
                        @foreach (['الغلاف', 'المشكلة', 'الحل'] as $n => $t)
                            <div class="aspect-[4/5] w-1/3 rounded-xl border border-line p-3 {{ $n === 0 ? 'bg-brand-600 text-white' : 'bg-app' }}">
                                <p class="text-[11px] opacity-70">شريحة {{ $n + 1 }}</p>
                                <p class="mt-2 text-sm font-bold leading-snug">{{ $t }}</p>
                                <div class="mt-3 space-y-1.5"><span class="block h-1.5 rounded bg-current opacity-20"></span><span class="block h-1.5 w-2/3 rounded bg-current opacity-20"></span></div>
                            </div>
                        @endforeach
                    </div>

                    {{-- صورة --}}
                    <div x-show="tab === 2" x-cloak class="w-full max-w-md">
                        <div class="relative grid aspect-square place-items-center overflow-hidden rounded-2xl bg-gradient-to-br from-brand-100 via-brand-200 to-brand-400 dark:from-brand-900 dark:via-brand-800 dark:to-brand-600">
                            <x-icon name="image" class="h-16 w-16 text-brand-700/60 dark:text-white/50" />
                            <span class="absolute bottom-3 start-3 rounded-full bg-card/90 px-3 py-1 text-xs font-semibold">استوديو الصور · شعارك يُركَّب تلقائيًا</span>
                        </div>
                    </div>

                    {{-- خطة الشهر --}}
                    <div x-show="tab === 3" x-cloak class="w-full max-w-lg">
                        <div class="grid grid-cols-7 gap-1.5 text-center text-[11px] text-fg-subtle">
                            @foreach (['س', 'أ', 'ن', 'ث', 'ر', 'خ', 'ج'] as $d)<span>{{ $d }}</span>@endforeach
                            @for ($d = 1; $d <= 28; $d++)
                                <span class="grid aspect-square place-items-center rounded-lg border {{ in_array($d, [3, 6, 10, 13, 17, 20, 24, 27]) ? 'border-brand-300 bg-brand-50 font-bold text-brand-700 dark:border-brand-700 dark:bg-brand-900/30 dark:text-brand-300' : 'border-line bg-app' }}">{{ $d }}</span>
                            @endfor
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ================= الحل الشامل ================= --}}
<section class="px-5 py-16">
    <div class="mx-auto max-w-4xl rounded-3xl border-2 border-brand-500 bg-card px-6 py-12 text-center shadow-sm sm:px-12">
        <h2 class="text-2xl font-bold sm:text-3xl">الحل الشامل في منصة واحدة</h2>
        <p class="mx-auto mt-3 max-w-2xl text-fg-muted">
            استثمر في منصة واحدة تجمع الكتابة والصور والصوت والتخطيط، بدل التنقل بين أدوات منفصلة وفريق متعدد.
        </p>
        <a href="{{ route('register') }}" class="btn btn-primary btn-lg mt-7">ابدأ رحلة النجاح الآن</a>
    </div>
</section>

{{-- ================= المميزات ================= --}}
<section id="features" class="scroll-mt-20 bg-muted/60 py-20">
    <div class="mx-auto max-w-6xl px-5">
        <div class="mx-auto max-w-2xl text-center">
            <span class="inline-flex rounded-full border border-brand-200 bg-brand-50 px-3.5 py-1.5 text-xs font-semibold text-brand-700 dark:border-brand-800 dark:bg-brand-900/30 dark:text-brand-300">ماذا تحصل عليه؟</span>
            <h2 class="mt-4 text-3xl font-bold sm:text-4xl">كل ما تحتاجه للنجاح <span class="text-brand-600 dark:text-brand-400">تحت سقف واحد</span></h2>
            <p class="mt-3 text-fg-muted">مجموعة متكاملة من الأدوات المدعومة بالذكاء الاصطناعي لتسهيل رحلة صناعة المحتوى الخاصة بك.</p>
        </div>

        <div class="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($features as [$icon, $title, $text])
                <article class="card-interactive overflow-hidden">
                    <div class="relative grid h-32 place-items-center overflow-hidden bg-gradient-to-br from-brand-100 to-brand-300 dark:from-brand-900 dark:to-brand-700" aria-hidden="true">
                        <span class="absolute -end-6 -top-6 h-24 w-24 rounded-full bg-white/30"></span>
                        <span class="grid h-14 w-14 place-items-center rounded-2xl bg-card/90 text-brand-600 shadow-md">
                            <x-icon :name="$icon" class="h-7 w-7" />
                        </span>
                    </div>
                    <div class="p-5">
                        <h3 class="font-bold">{{ $title }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-fg-muted">{{ $text }}</p>
                    </div>
                </article>
            @endforeach
        </div>
    </div>
</section>

{{-- ================= كيف نعمل ================= --}}
<section id="how" class="scroll-mt-20 py-20">
    <div class="mx-auto max-w-3xl px-5">
        <div class="text-center">
            <h2 class="text-3xl font-bold sm:text-4xl">كيف يعمل مسوق AI؟</h2>
            <p class="mt-3 text-fg-muted">خمس خطوات بسيطة تفصلك عن النجاح.</p>
        </div>

        <ol class="relative mt-12 space-y-6 before:absolute before:inset-y-2 before:start-6 before:w-px before:bg-line">
            @foreach ($steps as $i => [$icon, $title, $text])
                <li class="relative flex gap-5">
                    <span class="relative z-10 grid h-12 w-12 shrink-0 place-items-center rounded-full border-2 border-brand-500 bg-card text-brand-600">
                        <x-icon :name="$icon" class="h-5 w-5" />
                        <span class="absolute -top-1.5 -end-1.5 grid h-5 w-5 place-items-center rounded-full bg-fg text-[10px] font-bold text-fg-inverse tnum">{{ $i + 1 }}</span>
                    </span>
                    <div class="card flex-1 p-5">
                        <h3 class="font-bold">{{ $title }}</h3>
                        <p class="mt-1.5 text-sm leading-relaxed text-fg-muted">{{ $text }}</p>
                    </div>
                </li>
            @endforeach
        </ol>
    </div>
</section>

{{-- ================= الأسعار ================= --}}
<section id="pricing" class="scroll-mt-20 bg-muted/60 py-20">
    <div class="mx-auto max-w-4xl px-5">
        <div class="text-center">
            <h2 class="text-3xl font-bold sm:text-4xl">خطط أسعار تناسب طموحك</h2>
            <p class="mt-3 text-fg-muted">اختر الخطة التي تناسب احتياجاتك وابدأ الآن.</p>
        </div>

        <div class="mt-12 grid gap-6 md:grid-cols-2">
            @foreach (config('marketing.plans') as $plan)
                <article class="card relative flex flex-col p-7 {{ $plan['featured'] ? 'border-2 border-brand-500 shadow-lg' : '' }}">
                    @if ($plan['featured'])
                        <span class="absolute -top-3 start-7 rounded-full bg-brand-600 px-3 py-1 text-xs font-semibold text-white">الأنسب للمحترفين</span>
                    @endif
                    <h3 class="text-lg font-bold">{{ $plan['label'] }}</h3>
                    <p class="mt-1 text-sm text-fg-subtle">{{ $plan['hint'] }}</p>

                    <p class="mt-6 flex items-baseline gap-2">
                        @if ($plan['price'])
                            <span class="text-4xl font-bold tnum">{{ $plan['price'] }}</span>
                            <span class="text-sm text-fg-muted">/ {{ $plan['unit'] }}</span>
                        @else
                            <span class="text-2xl font-bold">يُعلن قريبًا</span>
                        @endif
                    </p>

                    <ul class="mt-6 flex-1 space-y-3 text-sm">
                        @foreach ([
                            'حصة شهرية من النقاط ('.number_format(config('credits.monthly_allowance')).' نقطة)',
                            'هوية علامة وإدارة منتجاتك',
                            'منشورات وكاروسيل وسكربت ريلز',
                            'استوديو الصور وإزالة الخلفية',
                            'التعليق الصوتي بلهجات عربية',
                            'بوابة الصدق على كل نص',
                            'الخطة الشهرية والتصدير',
                        ] as $item)
                            <li class="flex items-start gap-2.5">
                                <span class="mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full bg-success-soft text-success-fg"><x-icon name="check" class="h-3 w-3" /></span>
                                <span class="text-fg-muted">{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>

                    <a href="{{ route('register') }}" class="btn {{ $plan['featured'] ? 'btn-primary' : 'btn-secondary' }} mt-8 w-full">ابدأ الآن</a>
                </article>
            @endforeach
        </div>
    </div>
</section>

{{-- ================= لمن المنصة ================= --}}
<section class="py-20">
    <div class="mx-auto max-w-5xl px-5">
        <h2 class="text-center text-2xl font-bold sm:text-3xl">لمن هذه المنصة؟</h2>
        <div class="mt-10 grid grid-cols-2 gap-4 lg:grid-cols-4">
            @foreach ([
                ['shopping-bag', 'أصحاب المتاجر الإلكترونية'],
                ['megaphone', 'المسوقون على السوشيال ميديا'],
                ['briefcase', 'رواد الأعمال والفريلانسرز'],
                ['video', 'صنّاع المحتوى'],
            ] as [$icon, $label])
                <div class="card flex flex-col items-center gap-3 px-4 py-7 text-center">
                    <span class="grid h-12 w-12 place-items-center rounded-full bg-brand-50 text-brand-600 dark:bg-brand-900/30 dark:text-brand-300"><x-icon :name="$icon" class="h-6 w-6" /></span>
                    <span class="text-sm font-semibold">{{ $label }}</span>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ================= الأسئلة الشائعة ================= --}}
<section class="bg-muted/60 py-20">
    <div class="mx-auto max-w-3xl px-5">
        <div class="text-center">
            <h2 class="text-3xl font-bold">الأسئلة الشائعة</h2>
            <p class="mt-3 text-fg-muted">إجابات على أكثر الأسئلة شيوعًا حول مسوق AI.</p>
        </div>

        <div class="mt-10 space-y-3">
            @foreach ($faq as [$q, $a])
                <details class="group card px-5 py-4 open:shadow-md">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-4 text-[15px] font-semibold marker:hidden [&::-webkit-details-marker]:hidden">
                        {{ $q }}
                        <x-icon name="chevron-down" class="h-4 w-4 shrink-0 text-fg-subtle transition group-open:rotate-180" />
                    </summary>
                    <p class="mt-3 text-sm leading-loose text-fg-muted">{{ $a }}</p>
                </details>
            @endforeach
        </div>
    </div>
</section>

{{-- ================= الدعوة الأخيرة ================= --}}
<section class="px-5 py-20">
    <div class="mx-auto max-w-3xl rounded-3xl border-2 border-brand-500 bg-card px-6 py-14 text-center sm:px-12">
        <span class="inline-flex items-center gap-2 rounded-full bg-brand-50 px-3.5 py-1.5 text-xs font-semibold text-brand-700 dark:bg-brand-900/30 dark:text-brand-300">
            <x-icon name="sparkles" class="h-3.5 w-3.5" /> ابدأ رحلتك اليوم
        </span>
        <h2 class="mt-5 text-3xl font-bold leading-snug sm:text-4xl">جاهز لتحويل أفكارك إلى <span class="text-brand-600 dark:text-brand-400">نتائج ملموسة؟</span></h2>
        <p class="mx-auto mt-4 max-w-xl text-fg-muted">انضم إلى رواد الأعمال والمسوقين الذين يكتبون محتواهم بهويتهم، ويوفّرون وقتهم، باستخدام مسوق AI.</p>
        <div class="mt-8 flex flex-wrap justify-center gap-3">
            <a href="{{ route('register') }}" class="btn btn-primary btn-lg">أنشئ حسابك الآن <x-icon name="arrow-left" class="h-4 w-4" /></a>
            <a href="{{ route('about') }}" class="btn btn-secondary btn-lg">تعرّف علينا</a>
        </div>
    </div>
</section>

@endsection
