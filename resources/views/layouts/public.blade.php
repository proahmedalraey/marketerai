<!DOCTYPE html>
<html lang="ar" dir="rtl" class="h-full scroll-smooth" data-theme-lock="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#FFFBEB">
    <meta name="description" content="@yield('description', config('marketing.tagline'))">

    <title>@yield('title') | {{ config('marketing.name') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&display=swap">

    {{-- قبل الرسم: من لم يطلب تقليل الحركة تُخفى عناوينه المتحركة حتى تظهر سطرًا سطرًا (app.css: .motion-ok) --}}
    <script>
        if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            document.documentElement.classList.add('motion-ok');
        }
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-full bg-white font-cairo text-gray-700 antialiased">

@php
    $nav = [
        ['المميزات', route('home').'#features'],
        ['كيف نعمل؟', route('home').'#how'],
        ['الأسعار', route('home').'#pricing'],
        ['من نحن', route('about')],
    ];
@endphp

<a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:start-4 focus:top-4 focus:z-[60] focus:rounded-full focus:bg-gray-900 focus:px-5 focus:py-2.5 focus:text-sm focus:font-bold focus:text-white">تخطَّ إلى المحتوى</a>

{{-- ====== الترويسة: شفافة فوق البطل، بيضاء بعد أول تمرير ====== --}}
<header
    x-data="{ scrolled: false, open: false }"
    x-init="scrolled = window.scrollY > 8"
    @scroll.window="scrolled = window.scrollY > 8"
    :class="scrolled || open ? 'border-gray-100 bg-white/90 shadow-sm backdrop-blur-md' : 'border-transparent bg-transparent'"
    class="fixed inset-x-0 top-0 z-50 border-b transition-all duration-300"
>
    <div class="mx-auto flex h-20 max-w-7xl items-center justify-between gap-6 px-4 md:px-6">
        <a href="{{ route('home') }}" aria-label="{{ config('marketing.name') }} — الرئيسية">
            <x-public.logo />
        </a>

        <nav class="hidden items-center gap-8 md:flex" aria-label="التنقل الرئيسي">
            @foreach ($nav as [$label, $url])
                <a href="{{ $url }}" class="font-medium text-gray-600 transition-colors hover:text-primary-ink">{{ $label }}</a>
            @endforeach
        </nav>

        <div class="hidden items-center gap-4 md:flex">
            @auth
                <a href="{{ route('dashboard') }}" class="rounded-full bg-primary-strong px-5 py-2.5 text-sm font-bold text-white transition-colors hover:bg-primary-hover">لوحة التحكم</a>
            @else
                <a href="{{ route('login') }}" class="rounded-md px-3 py-2 text-sm font-medium text-gray-700 transition-colors hover:text-gray-900">تسجيل الدخول</a>
                <a href="{{ route('register') }}" class="rounded-full bg-primary-strong px-5 py-2.5 text-sm font-bold text-white shadow-sm transition-colors hover:bg-primary-hover">اشترك الآن</a>
            @endauth
        </div>

        <div class="flex items-center gap-1.5 md:hidden">
            {{-- أداة وفعلها من مشهد التجميع يتبدّلان، والنقر يبدأ التسجيل.
                 يخفت مع التمرير حين يقترب التذييل (وفيه شريط العبارات) فلا يتكرر الشريطان معًا:
                 يبدأ الخفوت حين يطل التذييل من أسفل الشاشة، ويكتمل بعد 260px — قبل أن يصل شريط التذييل --}}
            <a href="{{ route('register') }}" aria-label="ابدأ مع {{ config('marketing.name') }}: اكتب، صمّم، علّق، خطّط، وانشر بثقة"
               x-data="{ fade: 1 }"
               x-init="
                   const footer = document.querySelector('footer');
                   const update = () => { fade = Math.min(1, Math.max(0, 1 + (footer.getBoundingClientRect().top - innerHeight) / 260)); };
                   update();
                   addEventListener('scroll', update, { passive: true });
                   addEventListener('resize', update);"
               :style="{ opacity: fade, transform: `scale(${0.92 + fade * 0.08})`, visibility: fade > 0 ? 'visible' : 'hidden' }"
               class="inline-flex min-h-11 items-center rounded-full bg-white px-3 text-sm font-bold text-gray-900 shadow-sm ring-1 ring-gray-200">
                <x-public.tool-ticker />
            </a>

            <button type="button" class="grid h-11 w-11 place-items-center rounded-full text-gray-600"
                    @click="open = !open" :aria-expanded="open.toString()" aria-controls="mobile-nav">
                <x-public.icon name="menu" class="h-6 w-6" x-show="!open" />
                <x-public.icon name="close" class="h-6 w-6" x-show="open" x-cloak />
                <span class="sr-only">القائمة</span>
            </button>
        </div>
    </div>

    <nav id="mobile-nav" x-show="open" x-cloak x-transition.opacity @click.outside="open = false"
         class="border-t border-gray-100 bg-white px-4 pb-5 pt-2 md:hidden" aria-label="التنقل">
        @foreach ($nav as [$label, $url])
            <a href="{{ $url }}" @click="open = false" class="block rounded-lg px-3 py-3 font-medium text-gray-700 hover:bg-gray-50">{{ $label }}</a>
        @endforeach
        <div class="mt-3 grid grid-cols-2 gap-3">
            @auth
                <a href="{{ route('dashboard') }}" class="col-span-2 rounded-full bg-primary-strong py-3 text-center text-sm font-bold text-white">لوحة التحكم</a>
            @else
                <a href="{{ route('login') }}" class="rounded-full border border-gray-300 py-3 text-center text-sm font-bold text-gray-700">تسجيل الدخول</a>
                <a href="{{ route('register') }}" class="rounded-full bg-primary-strong py-3 text-center text-sm font-bold text-white">اشترك الآن</a>
            @endauth
        </div>
    </nav>
</header>

<main id="main">
    @yield('content')
</main>

{{-- ====== التذييل ====== --}}
{{-- الجوال: الشعار في الوسط، والمجموعات مطوية تُفتح باللمس، ثم أيقونات السوشيال أفقيًا ثم الحقوق.
     الحاسوب: أعمدة مفتوحة دائمًا، والحقوق يمينًا والأيقونات يسارًا. --}}
@php
    $footerGroups = [
        'links' => ['روابط سريعة', [
            ['الرئيسية', route('home')],
            ['المميزات', route('home').'#features'],
            ['الأسعار', route('home').'#pricing'],
            ['كيف نعمل', route('home').'#how'],
            ['من نحن', route('about')],
            ['الوكلاء والشركاء المعتمدون', route('home').'#partners'],
        ]],
        'contact' => ['تواصل معنا', collect(\App\Support\Contact::channels())->map(fn ($channel) => [$channel['label'], $channel['href']])->values()->all()],
        'legal' => ['قانوني', [
            ['سياسة الخصوصية', route('privacy')],
            ['سياسة الاسترجاع والإلغاء', route('refund')],
        ]],
    ];
    $socials = \App\Support\Contact::socials();
@endphp
<footer class="border-t border-amber-100 bg-gray-50 pb-8 pt-14 md:pt-16">
    <div class="mx-auto max-w-7xl px-4 md:px-6">
        <div class="grid gap-8 md:mb-12 md:grid-cols-5 md:gap-10">
            <div class="text-center md:col-span-2 md:text-start">
                <a href="{{ route('home') }}" class="mb-4 inline-flex" aria-label="{{ config('marketing.name') }}"><x-public.logo /></a>
                <p class="mx-auto max-w-sm leading-relaxed text-gray-500 md:mx-0">{{ config('marketing.tagline') }}</p>

                {{-- فكرة شريط الترويسة نفسها بعبارات فائدة: «خطّط محتواك الشهري»، «انشر بثقة في قنوات متعددة»… --}}
                <a href="{{ route('register') }}" aria-label="ابدأ مع {{ config('marketing.name') }}: {{ collect(config('marketing.tools'))->pluck('phrase')->join('، ') }}"
                   class="mt-6 inline-flex min-h-11 items-center rounded-full bg-white px-4 font-bold text-gray-900 shadow-sm ring-1 ring-gray-200 transition-shadow hover:shadow-md">
                    <x-public.tool-ticker size="md" text="phrase" />
                </a>
            </div>

            {{-- على الحاسوب يذوب هذا الغلاف (contents) فتصير كل مجموعة عمودًا في الشبكة --}}
            <div class="divide-y divide-gray-200 border-y border-gray-200 md:contents">
                @foreach ($footerGroups as $id => [$title, $links])
                    <div x-data="{ open: false }">
                        <h2 class="mb-4 hidden font-bold text-gray-900 md:block">{{ $title }}</h2>

                        <button type="button" @click="open = !open" :aria-expanded="open.toString()" aria-controls="footer-{{ $id }}"
                                class="flex w-full items-center justify-between gap-4 py-4 text-start font-bold text-gray-900 md:hidden">
                            {{ $title }}
                            <span class="relative grid h-8 w-8 shrink-0 place-items-center rounded-full shadow-md shadow-gray-900/10 ring-1 transition-colors duration-300"
                                  :class="open ? 'bg-primary text-white ring-primary' : 'bg-white text-gray-700 ring-gray-100'" aria-hidden="true">
                                <span class="absolute h-0.5 w-3 rounded-full bg-current"></span>
                                <span class="absolute h-3 w-0.5 rounded-full bg-current transition-transform duration-300" :class="open && 'rotate-90 scale-0'"></span>
                            </span>
                        </button>

                        {{-- الطي بصفوف الشبكة (0fr → 1fr) فينساب الارتفاع دون قياسه؛ الحاسوب مفتوح دائمًا --}}
                        <div id="footer-{{ $id }}" class="grid transition-[grid-template-rows] duration-300 ease-out md:!grid-rows-[1fr]"
                             :class="open ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'">
                            <div class="overflow-hidden">
                                <ul class="space-y-1 pb-4 md:space-y-3 md:pb-0">
                                    @foreach ($links as [$label, $url])
                                        <li>
                                            <a href="{{ $url }}" class="inline-block py-1.5 text-gray-500 transition-colors hover:text-primary-ink md:py-0"
                                               @if (str_starts_with($url, 'https://wa.me')) target="_blank" rel="noopener" @endif>{{ $label }}</a>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="mt-8 flex flex-col-reverse items-center justify-between gap-6 md:mt-0 md:flex-row md:border-t md:border-gray-200 md:pt-8">
            <p class="text-sm text-gray-500">جميع الحقوق محفوظة © {{ date('Y') }} {{ config('marketing.name') }}</p>

            @if ($socials)
                <ul class="flex flex-wrap items-center justify-center gap-2.5" aria-label="حساباتنا على السوشيال ميديا">
                    @foreach ($socials as $social)
                        <li>
                            @if ($social['href'])
                                <a href="{{ $social['href'] }}" target="_blank" rel="noopener"
                                   class="grid h-11 w-11 place-items-center rounded-full bg-white text-gray-600 shadow-sm ring-1 ring-gray-200 transition-colors duration-200 hover:bg-primary hover:text-white hover:ring-primary md:h-10 md:w-10">
                                    <x-public.icon :name="$social['icon']" class="h-4 w-4" />
                                    <span class="sr-only">{{ $social['label'] }}</span>
                                </a>
                            @else
                                {{-- البيئة المحلية فقط: مكان الحساب ظاهر ومُعلَّم بمتغيره حتى يُضبط رابطه --}}
                                <span title="{{ $social['label'] }} — اضبط {{ $social['env'] }} في ملف ‎.env"
                                      class="grid h-11 w-11 place-items-center rounded-full border border-dashed border-gray-300 text-gray-400 md:h-10 md:w-10">
                                    <x-public.icon :name="$social['icon']" class="h-4 w-4" />
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</footer>

@stack('scripts')

</body>
</html>
