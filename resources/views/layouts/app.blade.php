<!DOCTYPE html>
<html lang="ar" dir="rtl" class="h-full">
<head>
    <meta charset="utf-8">
    {{-- بلا maximum-scale ولا user-scalable=no: منع التكبير يقصي ضعاف البصر --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#FAF8F6" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#16120F" media="(prefers-color-scheme: dark)">

    <title>@yield('title', 'المنصة') · {{ config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    {{-- يُطبَّق المظهر قبل أول رسم، وإلا ومض البياض في وجه مستخدم الوضع الداكن --}}
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
    @stack('head')
</head>

{{-- x-data على الجسم ضرورية: Alpine لا يفعّل أي توجيه خارج جذر بيانات --}}
<body x-data class="min-h-full bg-app text-fg antialiased">

<a href="#main-content" class="skip-link">تخطَّ إلى المحتوى</a>

{{--
    الحالة الافتراضية (غير مطوية) مكتوبة في class الثابت حتى لا تومض الشبكة
    بعرض خاطئ قبل إقلاع Alpine، تماماً كما في aside الدرج أدناه.
--}}
<div
    class="min-h-screen lg:grid lg:grid-cols-[17.5rem_1fr] lg:transition-[grid-template-columns] lg:duration-300 lg:ease-out"
    :class="{
        'lg:grid-cols-[4.5rem_1fr]': $store.nav.collapsed,
        'lg:grid-cols-[17.5rem_1fr]': ! $store.nav.collapsed,
    }"
>

    @include('partials.sidebar')

    <div class="min-w-0 flex flex-col">

        {{-- ================= الرأس ================= --}}
        <header class="sticky top-0 z-30 glass border-b border-line">
            <div class="flex items-center gap-3 px-4 sm:px-6 h-16">

                <button
                    type="button"
                    @click="$store.nav.toggle()"
                    class="btn btn-ghost btn-icon -ms-2 lg:hidden"
                    aria-controls="app-sidebar"
                    :aria-expanded="$store.nav.open ? 'true' : 'false'"
                >
                    <x-icon name="menu" class="w-5 h-5" />
                    <span class="sr-only">فتح القائمة</span>
                </button>

                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2 min-w-0">
                        <h1 class="text-base sm:text-lg font-bold text-fg truncate leading-tight">
                            @yield('title', 'المنصة')
                        </h1>
                        @yield('title_badge')
                    </div>

                    @hasSection('subtitle')
                        <p class="hidden sm:block text-xs text-fg-muted truncate mt-0.5">@yield('subtitle')</p>
                    @endif
                </div>

                <div class="flex items-center gap-2 shrink-0">
                    @yield('actions')

                    @isset($currentBrand)
                        <span
                            class="chip-brand gap-1.5 lg:hidden"
                            title="رصيد النقاط المتبقي: {{ \App\Support\Credits::format($currentBrand->credit_balance) }}"
                        >
                            <x-icon name="coins" class="w-3.5 h-3.5" />
                            <span class="font-bold tnum">{{ \App\Support\Credits::format($currentBrand->credit_balance) }}</span>
                        </span>
                    @endisset
                </div>
            </div>
        </header>

        {{-- ================= المحتوى ================= --}}
        <main id="main-content" class="flex-1 pb-safe">
            <div class="mx-auto w-full max-w-6xl px-4 sm:px-6 py-6 space-y-5">

                @include('partials.flash')

                @yield('content')
            </div>
        </main>
    </div>
</div>

@isset($currentBrand)
    @include('partials.operations')
@endisset

@stack('scripts')
</body>
</html>
