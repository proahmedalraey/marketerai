<!DOCTYPE html>
<html lang="ar" dir="rtl" class="h-full" data-theme-lock="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#FFFBEB">

    <title>@yield('title', 'الدخول') | {{ config('marketing.name') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&display=swap">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

{{-- x-data على الجسم ضرورية: Alpine لا يفعّل أي توجيه خارج جذر بيانات --}}
<body x-data class="min-h-full bg-white font-cairo text-gray-700 antialiased">

<div class="relative isolate flex min-h-screen flex-col items-center justify-center overflow-hidden px-4 py-12">
    <div class="pointer-events-none absolute inset-0 -z-10 bg-gradient-to-b from-amber-50/70 via-white to-white" aria-hidden="true"></div>
    <div class="pointer-events-none absolute -left-48 top-1/3 -z-10 h-[34rem] w-[34rem] rounded-full bg-amber-200/40 blur-3xl" aria-hidden="true"></div>
    <div class="pointer-events-none absolute -right-40 -top-40 -z-10 h-96 w-96 rounded-full bg-orange-100/70 blur-3xl" aria-hidden="true"></div>

    <main class="w-full max-w-md">
        <div class="rounded-3xl bg-white px-6 py-8 shadow-xl shadow-amber-100/50 ring-1 ring-gray-100 sm:px-10 sm:py-10">
            <div class="mb-8 text-center">
                <a href="{{ route('home') }}" class="inline-flex" aria-label="{{ config('marketing.name') }} — الرئيسية">
                    <x-public.logo />
                </a>
                <h1 class="mt-4 text-lg font-medium text-gray-600">@yield('heading')</h1>
                @hasSection('switch')
                    <div class="mt-3">@yield('switch')</div>
                @endif
            </div>

            @yield('content')
        </div>

        <a href="{{ route('home') }}" class="mx-auto mt-6 flex w-fit items-center gap-2 text-gray-500 transition-colors hover:text-gray-800">
            <x-icon name="arrow-right" class="h-5 w-5" />
            العودة للصفحة الرئيسية
        </a>
    </main>
</div>

</body>
</html>
