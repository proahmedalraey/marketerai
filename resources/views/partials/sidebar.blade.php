@php
    $groups = [
        [
            'label' => 'تجهيز الحساب',
            'icon'  => 'sliders',
            'items' => [
                ['route' => 'brand.profile',  'label' => 'هوية العلامة', 'icon' => 'file-text', 'active' => ['brand.profile*']],
                ['route' => 'brand.identity', 'label' => 'الهوية البصرية', 'icon' => 'image', 'active' => ['brand.identity', 'brand.logos.*', 'brand.patterns.*']],
                ['route' => 'products.index', 'label' => 'المنتجات والخدمات', 'icon' => 'package', 'active' => ['products.*']],
                ['route' => 'store.facts', 'label' => 'حقائق البيع', 'icon' => 'coins', 'active' => ['store.*']],
            ],
        ],
        [
            'label' => 'كتابة المحتوى',
            'icon'  => 'pen',
            'items' => [
                ['route' => 'content.generator', 'label' => 'كتابة المحتوى', 'icon' => 'pen', 'active' => ['content.generator']],
                ['route' => 'content.plan',      'label' => 'الخطة الشهرية', 'icon' => 'calendar', 'active' => ['content.plan', 'content.show']],
            ],
        ],
        [
            'label' => 'صناعة المحتوى',
            'icon'  => 'sparkles',
            'items' => [
                ['route' => 'studio.index', 'label' => 'استوديو الصور', 'icon' => 'image', 'active' => ['studio.*']],
            ],
        ],
    ];

    if (auth()->user()?->can('manage-platform')) {
        $groups[] = [
            'label' => 'المنصة',
            'icon'  => 'settings',
            'items' => [
                ['route' => 'settings.ai', 'label' => 'الإعدادات', 'icon' => 'settings', 'active' => ['settings.*']],
            ],
        ];
    }

    $brandName = $currentBrand->name ?? config('app.name');

    $balance   = (float) ($currentBrand->credit_balance ?? 0);
    $allowance = (float) ($currentBrand->credits_allowance ?? 0);
    $remaining = $allowance > 0 ? max(0, min(100, round($balance / $allowance * 100))) : 0;
    $low       = $allowance > 0 && $remaining <= 20;
@endphp

{{-- طبقة تعتيم الدرج: تظهر على الجوال فقط --}}
<div
    x-show="$store.nav.open"
    x-cloak
    x-transition.opacity.duration.200ms
    @click="$store.nav.hide()"
    class="fixed inset-0 z-40 bg-scrim/50 backdrop-blur-sm lg:hidden"
    aria-hidden="true"
></div>

<aside
    id="app-sidebar"
    @keydown.escape.window="$store.nav.hide()"
    {{--
        الحالة المغلقة مكتوبة في class الثابت حتى لا يومض الدرج قبل إقلاع Alpine،
        وصيغة الكائن في :class هي التي تزيلها عند الفتح.
        invisible وليس مجرد إزاحة: الدرج المغلق يجب أن يخرج من ترتيب التبويب أيضاً.
    --}}
    :class="{
        'translate-x-full invisible': ! $store.nav.open,
        'translate-x-0 visible': $store.nav.open,
    }"
    :data-collapsed="$store.nav.collapsed ? 'true' : 'false'"
    class="fixed inset-y-0 start-0 z-50 w-[17.5rem] flex flex-col
           translate-x-full invisible
           bg-card border-s border-line shadow-xl
           transition-[transform,visibility,width] duration-300 ease-out
           lg:static lg:z-auto lg:w-auto lg:translate-x-0 lg:visible lg:shadow-none lg:h-screen lg:sticky lg:top-0"
>
    {{-- ترويسة العلامة --}}
    <div class="sidebar-header flex items-center gap-3 px-4 h-16 shrink-0 border-b border-line">
        <a href="{{ route('dashboard') }}" class="sidebar-brand-link flex items-center gap-2.5 min-w-0 flex-1 rounded-xl -m-1 p-1" title="{{ $brandName }}">
            {{-- ?? وليس ?-> : على شاشة بناء الهوية لا يكون $currentBrand معرّفاً أصلاً --}}
            @if ($logoPath = ($currentBrand->logo_path ?? null))
                <img
                    src="{{ Storage::url($logoPath) }}"
                    alt=""
                    class="w-9 h-9 rounded-xl object-contain bg-white border border-line shrink-0"
                >
            @else
                <span class="grid place-items-center w-9 h-9 rounded-xl bg-brand-600 text-white shrink-0">
                    <x-icon name="sparkles" class="w-[18px] h-[18px]" />
                </span>
            @endif

            <span class="sidebar-text min-w-0">
                <span class="block text-sm font-bold text-fg truncate">{{ $brandName }}</span>
                <span class="block text-[11px] text-fg-subtle truncate">خطّط، اكتب، وانشر</span>
            </span>
        </a>

        <button
            type="button"
            @click="$store.nav.hide()"
            class="btn btn-ghost btn-sm btn-icon lg:hidden"
        >
            <x-icon name="close" class="w-5 h-5" />
            <span class="sr-only">إغلاق القائمة</span>
        </button>

        <button
            type="button"
            @click="$store.nav.toggleCollapsed()"
            class="btn btn-ghost btn-sm btn-icon hidden lg:inline-flex shrink-0"
            :aria-pressed="$store.nav.collapsed ? 'true' : 'false'"
        >
            <x-icon
                name="chevron-left"
                class="w-4 h-4 transition-transform duration-200"
                x-bind:class="{ 'rotate-180': $store.nav.collapsed }"
            />
            <span class="sr-only" x-text="$store.nav.collapsed ? 'توسيع القائمة' : 'طي القائمة'"></span>
        </button>
    </div>

    {{-- التنقل --}}
    <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-6" aria-label="التنقل الرئيسي">
        @php $isHome = request()->routeIs('dashboard'); @endphp

        <a
            href="{{ route('dashboard') }}"
            @click="$store.nav.hide()"
            class="nav-link {{ $isHome ? 'nav-link-active' : '' }}"
            title="الرئيسية"
            @if ($isHome) aria-current="page" @endif
        >
            <x-icon name="home" class="w-[18px] h-[18px]" />
            <span class="sidebar-text">الرئيسية</span>
        </a>

        @foreach ($groups as $group)
            @php
                $groupKey = $group['label'];
                $groupActive = collect($group['items'])->contains(fn ($item) => request()->routeIs($item['active']));
                $groupActiveJs = $groupActive ? 'true' : 'false';
            @endphp

            <div>
                <button
                    type="button"
                    @click="$store.navGroups.toggle('{{ $groupKey }}', {{ $groupActiveJs }})"
                    class="nav-group-toggle {{ $groupActive ? 'nav-group-toggle-active' : '' }}"
                    title="{{ $group['label'] }}"
                    :aria-expanded="($store.nav.collapsed || $store.navGroups.isOpen('{{ $groupKey }}', {{ $groupActiveJs }})) ? 'true' : 'false'"
                >
                    <span class="flex items-center gap-2.5 min-w-0">
                        <x-icon :name="$group['icon']" class="w-[18px] h-[18px] shrink-0" />
                        <span class="sidebar-text truncate">{{ $group['label'] }}</span>
                    </span>

                    <x-icon
                        name="chevron-down"
                        class="nav-group-chevron"
                        x-bind:class="{ 'rotate-180': $store.navGroups.isOpen('{{ $groupKey }}', {{ $groupActiveJs }}) }"
                    />
                </button>

                <div
                    x-show="$store.nav.collapsed || $store.navGroups.isOpen('{{ $groupKey }}', {{ $groupActiveJs }})"
                    x-cloak
                    class="space-y-1 mt-1"
                >
                    @foreach ($group['items'] as $item)
                        @php $active = request()->routeIs($item['active']); @endphp

                        <a
                            href="{{ route($item['route']) }}"
                            @click="$store.nav.hide()"
                            class="nav-link {{ $active ? 'nav-link-active' : '' }}"
                            title="{{ $item['label'] }}"
                            @if ($active) aria-current="page" @endif
                        >
                            <x-icon :name="$item['icon']" class="w-[18px] h-[18px]" />
                            <span class="sidebar-text truncate">{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endforeach
    </nav>

    {{-- عدّاد النقاط --}}
    @isset($currentBrand)
        <div class="sidebar-expanded-only px-3 pb-3 shrink-0">
            <div class="panel p-3.5 {{ $low ? 'border-warning/40 bg-warning-soft' : '' }}">
                <div class="flex items-center justify-between gap-2 mb-2.5">
                    <span class="flex items-center gap-1.5 text-xs font-medium {{ $low ? 'text-warning-fg' : 'text-fg-muted' }}">
                        <x-icon name="coins" class="w-4 h-4" />
                        رصيد النقاط
                    </span>
                    <span class="text-sm font-bold tnum {{ $low ? 'text-warning-fg' : 'text-fg' }}">
                        {{ \App\Support\Credits::format($balance) }}
                    </span>
                </div>

                <div
                    class="h-1.5 rounded-full bg-line overflow-hidden"
                    role="progressbar"
                    aria-valuenow="{{ $remaining }}"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    aria-label="نسبة النقاط المتبقية"
                >
                    <div
                        class="h-full rounded-full transition-[width] duration-500 ease-out {{ $low ? 'bg-warning' : 'bg-brand-500' }}"
                        style="width: {{ $remaining }}%"
                    ></div>
                </div>

                <p class="mt-2 text-[11px] {{ $low ? 'text-warning-fg' : 'text-fg-subtle' }}">
                    متبقٍ {{ $remaining }}% من {{ \App\Support\Credits::format($allowance) }} نقطة هذا الشهر
                </p>
            </div>
        </div>
    @endisset

    {{-- المظهر والحساب --}}
    <div class="border-t border-line p-3 pb-safe shrink-0 space-y-2">
        <div class="sidebar-expanded-only flex items-center gap-1 p-1 rounded-xl bg-muted" role="group" aria-label="مظهر الواجهة">
            @foreach ([['light', 'sun', 'فاتح'], ['system', 'globe', 'النظام'], ['dark', 'moon', 'داكن']] as [$mode, $icon, $text])
                <button
                    type="button"
                    @click="$store.theme.set('{{ $mode }}')"
                    :class="$store.theme.value === '{{ $mode }}'
                        ? 'bg-card text-fg shadow-xs'
                        : 'text-fg-subtle hover:text-fg'"
                    :aria-pressed="$store.theme.value === '{{ $mode }}' ? 'true' : 'false'"
                    class="flex-1 flex items-center justify-center gap-1.5 min-h-9 rounded-lg text-xs font-medium transition"
                >
                    <x-icon :name="$icon" class="w-4 h-4" />
                    <span class="sr-only sm:not-sr-only">{{ $text }}</span>
                </button>
            @endforeach
        </div>

        @auth
            <div class="sidebar-user-row flex items-center gap-2.5 px-2 py-1.5">
                <span class="grid place-items-center w-8 h-8 shrink-0 rounded-full bg-brand-50 text-brand-700 text-xs font-bold dark:bg-brand-500/15 dark:text-brand-300" title="{{ auth()->user()->name }}">
                    {{ mb_strtoupper(mb_substr(auth()->user()->name ?? 'م', 0, 1)) }}
                </span>

                <span class="sidebar-text min-w-0 flex-1">
                    <span class="block text-xs font-medium text-fg truncate">{{ auth()->user()->name }}</span>
                    <span class="block text-[11px] text-fg-subtle truncate" dir="ltr">{{ auth()->user()->email }}</span>
                </span>

                <form method="POST" action="{{ route('logout') }}" data-no-busy>
                    @csrf
                    <button type="submit" class="btn btn-ghost btn-sm btn-icon" title="تسجيل الخروج">
                        <x-icon name="logout" class="w-[18px] h-[18px]" />
                        <span class="sr-only">تسجيل الخروج</span>
                    </button>
                </form>
            </div>
        @endauth
    </div>
</aside>
