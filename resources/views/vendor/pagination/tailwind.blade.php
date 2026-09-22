@if ($paginator->hasPages())
    {{--
        ترقيم مبني على رموز التصميم لا على ألوان خام، ويعمل في الوضع الداكن.
        الأسهم مقلوبة يدوياً لأن «التالي» في RTL يقع إلى اليسار.
    --}}
    <div class="flex items-center justify-between gap-3 flex-wrap">

        <p class="text-xs text-fg-subtle tnum order-2 sm:order-1">
            {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} من {{ $paginator->total() }}
        </p>

        <div class="flex items-center gap-1 order-1 sm:order-2">

            {{-- السابق --}}
            @if ($paginator->onFirstPage())
                <span class="btn btn-ghost btn-sm btn-icon opacity-40 cursor-not-allowed" aria-disabled="true">
                    <x-icon name="chevron-right" class="w-4 h-4" />
                    <span class="sr-only">الصفحة السابقة</span>
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="btn btn-secondary btn-sm btn-icon">
                    <x-icon name="chevron-right" class="w-4 h-4" />
                    <span class="sr-only">الصفحة السابقة</span>
                </a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="px-2 text-sm text-fg-subtle" aria-hidden="true">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span
                                aria-current="page"
                                class="grid place-items-center min-w-9 h-9 px-2 rounded-lg bg-brand-600 text-white text-sm font-semibold tnum"
                            >{{ $page }}</span>
                        @else
                            <a
                                href="{{ $url }}"
                                aria-label="الصفحة {{ $page }}"
                                class="grid place-items-center min-w-9 h-9 px-2 rounded-lg text-sm font-medium tnum
                                       text-fg-muted hover:bg-muted hover:text-fg transition"
                            >{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            {{-- التالي --}}
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="btn btn-secondary btn-sm btn-icon">
                    <x-icon name="chevron-left" class="w-4 h-4" />
                    <span class="sr-only">الصفحة التالية</span>
                </a>
            @else
                <span class="btn btn-ghost btn-sm btn-icon opacity-40 cursor-not-allowed" aria-disabled="true">
                    <x-icon name="chevron-left" class="w-4 h-4" />
                    <span class="sr-only">الصفحة التالية</span>
                </span>
            @endif
        </div>
    </div>
@endif
