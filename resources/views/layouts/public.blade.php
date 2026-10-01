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

        <button type="button" class="grid h-11 w-11 place-items-center rounded-full text-gray-600 md:hidden"
                @click="open = !open" :aria-expanded="open.toString()" aria-controls="mobile-nav">
            <x-public.icon name="menu" class="h-6 w-6" x-show="!open" />
            <x-public.icon name="close" class="h-6 w-6" x-show="open" x-cloak />
            <span class="sr-only">القائمة</span>
        </button>
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
@php $contact = config('marketing.contact'); @endphp
<footer class="border-t border-amber-100 bg-gray-50 pb-8 pt-16">
    <div class="mx-auto max-w-7xl px-4 md:px-6">
        <div class="mb-12 grid gap-10 md:grid-cols-4">
            <div class="md:col-span-2">
                <a href="{{ route('home') }}" class="mb-4 inline-flex" aria-label="{{ config('marketing.name') }}"><x-public.logo /></a>
                <p class="max-w-sm leading-relaxed text-gray-500">{{ config('marketing.tagline') }}</p>
            </div>

            <div>
                <h2 class="mb-4 font-bold text-gray-900">روابط سريعة</h2>
                <ul class="space-y-3">
                    @foreach ([
                        ['الرئيسية', route('home')],
                        ['المميزات', route('home').'#features'],
                        ['الأسعار', route('home').'#pricing'],
                        ['كيف نعمل', route('home').'#how'],
                        ['من نحن', route('about')],
                        ['الوكلاء والشركاء المعتمدون', route('home').'#partners'],
                    ] as [$label, $url])
                        <li><a href="{{ $url }}" class="text-gray-500 transition-colors hover:text-primary-ink">{{ $label }}</a></li>
                    @endforeach
                </ul>
            </div>

            <div>
                <h2 class="mb-4 font-bold text-gray-900">قانوني</h2>
                <ul class="space-y-3">
                    <li><a href="{{ route('privacy') }}" class="text-gray-500 transition-colors hover:text-primary-ink">سياسة الخصوصية</a></li>
                    <li><a href="{{ route('refund') }}" class="text-gray-500 transition-colors hover:text-primary-ink">سياسة الاسترجاع والإلغاء</a></li>
                </ul>
            </div>
        </div>

        <div class="flex flex-col items-center justify-between gap-4 border-t border-gray-200 pt-8 md:flex-row">
            <p class="text-sm text-gray-500">جميع الحقوق محفوظة © {{ date('Y') }} {{ config('marketing.name') }}</p>

            <div class="flex items-center gap-2">
                @if ($contact['email'])
                    <a href="mailto:{{ $contact['email'] }}" class="grid h-8 w-8 place-items-center rounded-full bg-gray-100 text-gray-600 transition-colors duration-200 hover:bg-blue-100 hover:text-blue-700">
                        <x-public.icon name="mail" class="h-4 w-4" /><span class="sr-only">البريد الإلكتروني</span>
                    </a>
                @endif
                @if ($contact['whatsapp'])
                    <a href="https://wa.me/{{ preg_replace('/\D/', '', $contact['whatsapp']) }}" target="_blank" rel="noopener" class="grid h-8 w-8 place-items-center rounded-full bg-gray-100 text-gray-600 transition-colors duration-200 hover:bg-green-100 hover:text-green-700">
                        <x-public.icon name="thread" class="h-4 w-4" /><span class="sr-only">واتساب</span>
                    </a>
                @endif
                @if ($contact['instagram'])
                    <a href="{{ $contact['instagram'] }}" target="_blank" rel="noopener" class="grid h-8 w-8 place-items-center rounded-full bg-gray-100 text-gray-600 transition-colors duration-200 hover:bg-pink-100 hover:text-pink-700">
                        <x-public.icon name="instagram" class="h-4 w-4" /><span class="sr-only">إنستغرام</span>
                    </a>
                @endif
            </div>
        </div>
    </div>
</footer>

@stack('scripts')

</body>
</html>
