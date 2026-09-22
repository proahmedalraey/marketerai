<!DOCTYPE html>
<html lang="ar" dir="rtl" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#FAF8F6" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#16120F" media="(prefers-color-scheme: dark)">

    <title>@yield('title', 'الدخول') · {{ config('app.name') }}</title>

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

{{-- x-data على الجسم ضرورية: Alpine لا يفعّل أي توجيه خارج جذر بيانات --}}
<body x-data class="min-h-full bg-app text-fg antialiased">

<div class="min-h-screen lg:grid lg:grid-cols-2">

    {{-- ====== لوحة العلامة: تشرح لماذا يستحق التسجيل قبل أن نطلب البيانات ====== --}}
    <aside class="relative hidden lg:flex flex-col justify-between overflow-hidden bg-brand-900 text-white p-12">
        <div
            class="absolute inset-0 opacity-[0.16]"
            style="background-image:
                radial-gradient(circle at 20% 15%, rgb(var(--brand-400)) 0, transparent 45%),
                radial-gradient(circle at 85% 80%, rgb(var(--brand-500)) 0, transparent 40%);"
            aria-hidden="true"
        ></div>

        <div class="relative">
            <div class="flex items-center gap-2.5">
                <span class="grid place-items-center w-10 h-10 rounded-xl bg-white/15 backdrop-blur">
                    <x-icon name="sparkles" class="w-5 h-5" />
                </span>
                <span class="text-lg font-bold">{{ config('app.name') }}</span>
            </div>
        </div>

        <div class="relative max-w-md">
            <h2 class="text-3xl font-bold leading-[1.45] text-white">
                محتوى تسويقي جاهز للنشر،<br>مبني على هوية متجرك ومنتجاتك.
            </h2>

            <ul class="mt-8 space-y-4">
                @foreach ([
                    ['target', 'يكتب بنبرتك ولهجتك', 'تُعرّف الهوية مرة واحدة، فيرث كل منشور بعدها نبرتك وجمهورك وكلماتك الممنوعة.'],
                    ['package', 'يعرف ما تبيع فعلاً', 'كل منتج يُخزَّن بورقة مرجعية تدخل النموذج، فيتحدث عن منتجك لا عن منتج عام.'],
                    ['image', 'صور بهويتك البصرية', 'الاستوديو يولّد الصور من وصفك، ويركّب شعارك برمجياً بعد التوليد.'],
                ] as [$icon, $title, $text])
                    <li class="flex gap-3.5">
                        <span class="grid place-items-center w-9 h-9 shrink-0 rounded-xl bg-white/12 text-white">
                            <x-icon :name="$icon" class="w-[18px] h-[18px]" />
                        </span>
                        <span>
                            <span class="block text-sm font-semibold text-white">{{ $title }}</span>
                            <span class="block text-sm text-white/70 leading-relaxed mt-0.5">{{ $text }}</span>
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>

        <p class="relative text-xs text-white/65">
            © {{ date('Y') }} {{ config('app.name') }}
        </p>
    </aside>

    {{-- ====== النموذج ====== --}}
    <main class="relative flex flex-col justify-center px-5 py-10 sm:px-8">
        <div class="absolute top-4 end-4">
            <button
                type="button"
                @click="$store.theme.toggle()"
                class="btn btn-ghost btn-icon btn-sm"
                title="تبديل مظهر الواجهة"
            >
                <x-icon name="sun" class="w-[18px] h-[18px] dark:hidden" />
                <x-icon name="moon" class="w-[18px] h-[18px] hidden dark:block" />
                <span class="sr-only">تبديل مظهر الواجهة</span>
            </button>
        </div>

        <div class="w-full max-w-sm mx-auto">
            <div class="lg:hidden flex items-center justify-center gap-2.5 mb-8">
                <span class="grid place-items-center w-10 h-10 rounded-xl bg-brand-600 text-white">
                    <x-icon name="sparkles" class="w-5 h-5" />
                </span>
                <span class="text-lg font-bold text-fg">{{ config('app.name') }}</span>
            </div>

            <div class="mb-6">
                <h1 class="text-2xl font-bold text-fg">@yield('heading', 'أهلاً بك')</h1>
                <p class="mt-1.5 text-sm text-fg-muted leading-relaxed">@yield('lede')</p>
            </div>

            @if ($errors->any())
                <div class="alert-danger mb-5" role="alert">
                    <x-icon name="alert-circle" class="w-5 h-5 shrink-0 mt-px" />
                    <p class="flex-1 leading-relaxed">{{ $errors->first() }}</p>
                </div>
            @endif

            @yield('content')
        </div>
    </main>
</div>

</body>
</html>
