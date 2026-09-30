<!DOCTYPE html>
<html lang="ar" dir="rtl" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#FAF8F6" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#16120F" media="(prefers-color-scheme: dark)">
    <meta name="description" content="@yield('description', config('marketing.tagline'))">

    <title>@yield('title', config('marketing.name')) · {{ config('marketing.name') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <script>
        (function () {
            try {
                var t = localStorage.getItem('theme') || 'system';
                var dark = t === 'dark'
                    || (t === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.classList.toggle('dark', dark);
            } catch (e) {}
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body x-data="{ menu: false }" class="min-h-full bg-app text-fg antialiased">

<a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:start-3 focus:z-50 btn btn-primary btn-sm">تخطَّ إلى المحتوى</a>

{{-- ====== الترويسة ====== --}}
<header class="sticky top-0 z-40 border-b border-line/70 bg-app/85 backdrop-blur-md">
    <div class="mx-auto flex h-16 max-w-6xl items-center gap-4 px-5">
        <a href="{{ route('home') }}" class="flex items-center gap-2.5" aria-label="{{ config('marketing.name') }} — الرئيسية">
            <x-public.logo />
        </a>

        <nav class="hidden lg:flex items-center gap-1 ms-6" aria-label="التنقل الرئيسي">
            @foreach ([
                ['home', 'الرئيسية'],
                ['home', 'المميزات', 'features'],
                ['home', 'كيف نعمل', 'how'],
                ['home', 'الأسعار', 'pricing'],
                ['about', 'من نحن'],
            ] as $link)
                <a href="{{ route($link[0]) }}{{ isset($link[2]) ? '#'.$link[2] : '' }}"
                   class="rounded-lg px-3 py-2 text-sm font-medium text-fg-muted transition hover:bg-muted hover:text-fg">{{ $link[1] }}</a>
            @endforeach
        </nav>

        <div class="ms-auto flex items-center gap-2">
            <button type="button" @click="$store.theme.toggle()" class="btn btn-ghost btn-icon btn-sm" title="تبديل مظهر الواجهة">
                <x-icon name="sun" class="w-[18px] h-[18px] dark:hidden" />
                <x-icon name="moon" class="w-[18px] h-[18px] hidden dark:block" />
                <span class="sr-only">تبديل مظهر الواجهة</span>
            </button>

            @auth
                <a href="{{ route('dashboard') }}" class="btn btn-primary btn-sm">لوحة التحكم</a>
            @else
                <a href="{{ route('login') }}" class="btn btn-ghost btn-sm hidden sm:inline-flex">تسجيل الدخول</a>
                <a href="{{ route('register') }}" class="btn btn-primary btn-sm">ابدأ الآن</a>
            @endauth

            <button type="button" class="btn btn-ghost btn-icon btn-sm lg:hidden" @click="menu = !menu"
                    :aria-expanded="menu" aria-controls="mobile-nav">
                <x-icon name="menu" class="w-5 h-5" x-show="!menu" />
                <x-icon name="close" class="w-5 h-5" x-show="menu" x-cloak />
                <span class="sr-only">القائمة</span>
            </button>
        </div>
    </div>

    <nav id="mobile-nav" x-show="menu" x-cloak x-transition.opacity @click.outside="menu = false"
         class="lg:hidden border-t border-line bg-app px-5 py-3" aria-label="التنقل">
        @foreach ([
            ['home', 'الرئيسية'],
            ['home', 'المميزات', 'features'],
            ['home', 'كيف نعمل', 'how'],
            ['home', 'الأسعار', 'pricing'],
            ['about', 'من نحن'],
            ['login', 'تسجيل الدخول'],
        ] as $link)
            <a href="{{ route($link[0]) }}{{ isset($link[2]) ? '#'.$link[2] : '' }}" @click="menu = false"
               class="block rounded-lg px-3 py-3 text-sm font-medium text-fg-muted hover:bg-muted hover:text-fg">{{ $link[1] }}</a>
        @endforeach
    </nav>
</header>

<main id="main">
    @yield('content')
</main>

{{-- ====== التذييل ====== --}}
<footer class="border-t border-line bg-muted/60">
    <div class="mx-auto grid max-w-6xl gap-10 px-5 py-14 md:grid-cols-[1.4fr_1fr_1fr]">
        <div>
            <x-public.logo />
            <p class="mt-4 max-w-xs text-sm leading-relaxed text-fg-muted">{{ config('marketing.tagline') }}</p>
        </div>

        <div>
            <h2 class="text-sm font-bold">روابط سريعة</h2>
            <ul class="mt-4 space-y-2.5 text-sm text-fg-muted">
                <li><a class="hover:text-brand-600" href="{{ route('home') }}">الرئيسية</a></li>
                <li><a class="hover:text-brand-600" href="{{ route('home') }}#features">المميزات</a></li>
                <li><a class="hover:text-brand-600" href="{{ route('home') }}#pricing">الأسعار</a></li>
                <li><a class="hover:text-brand-600" href="{{ route('home') }}#how">كيف نعمل</a></li>
                <li><a class="hover:text-brand-600" href="{{ route('about') }}">من نحن</a></li>
            </ul>
        </div>

        <div>
            <h2 class="text-sm font-bold">حسابك</h2>
            <ul class="mt-4 space-y-2.5 text-sm text-fg-muted">
                <li><a class="hover:text-brand-600" href="{{ route('login') }}">تسجيل الدخول</a></li>
                <li><a class="hover:text-brand-600" href="{{ route('register') }}">إنشاء حساب</a></li>
            </ul>
        </div>
    </div>

    <div class="border-t border-line">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-5 py-5">
            <p class="text-xs text-fg-subtle">جميع الحقوق محفوظة © {{ date('Y') }} {{ config('marketing.name') }}</p>

            <div class="flex items-center gap-2">
                @php $c = config('marketing.contact'); @endphp
                @if ($c['instagram'])
                    <a href="{{ $c['instagram'] }}" class="btn btn-ghost btn-icon btn-sm" rel="noopener" target="_blank">
                        <x-icon name="instagram" class="w-4 h-4" /><span class="sr-only">إنستغرام</span>
                    </a>
                @endif
                @if ($c['whatsapp'])
                    <a href="https://wa.me/{{ preg_replace('/\D/', '', $c['whatsapp']) }}" class="btn btn-ghost btn-icon btn-sm" rel="noopener" target="_blank">
                        <x-icon name="thread" class="w-4 h-4" /><span class="sr-only">واتساب</span>
                    </a>
                @endif
                @if ($c['email'])
                    <a href="mailto:{{ $c['email'] }}" class="btn btn-ghost btn-icon btn-sm">
                        <x-icon name="mail" class="w-4 h-4" /><span class="sr-only">البريد</span>
                    </a>
                @endif
            </div>
        </div>
    </div>
</footer>

</body>
</html>
