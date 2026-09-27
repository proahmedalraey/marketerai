{{--
    كاروسيل كامل في بطاقة واحدة مكدّسة (المتغير: $item). الغلاف في الأمام وشريحتان خلفه
    مائلتان كأوراق فوق بعض، تنفرجان عند المرور. «عرض الشرائح» يفتح عارض الكاروسيل
    (_carousel-viewer-modal) بالنص مركّباً فوق الصور كما سيُنشر.
--}}
@php
    $slides = $item->slides();
    $total = count($slides);
    $images = collect($item->slideImages())->sortKeys();   // الأحدث لكل شريحة
    $cover = $images->get(0) ?? $images->first();
    $behind = $images->reject(fn ($asset) => $asset->is($cover))->take(2)->values();
    $ready = $images->count();
    $ids = $item->mediaAssets->where('kind', 'image')->pluck('id')->values()->all();
    $first = $slides[0] ?? [];
    $title = $item->body['title'] ?? ($first['focal'] ?? null) ?: ($first['text'] ?? 'كاروسيل');
    $modelLabel = $studioModelService->labelFor($cover?->meta['model'] ?? null);
@endphp

<figure
    class="relative isolate group/stack"
    x-data="{ ids: @js($ids) }"
    aria-label="كاروسيل من {{ $total }} شرائح: {{ Str::limit($title, 60) }}"
>
    {{-- الأوراق الخلفية: صور شرائح أخرى، أو الغلاف معتّماً إن لم تُولَّد بعد (بلون السطح كانت لا تُرى) --}}
    @foreach ([1, 0] as $layer)
        @php
            $asset = $behind->get($layer) ?? $cover;
            $fallback = ! $behind->has($layer);
        @endphp
        <div aria-hidden="true"
             class="absolute inset-x-0 top-0 aspect-square -z-10 origin-top rounded-2xl overflow-hidden bg-sunken border-[3px] border-card shadow-md ring-1 ring-black/5
                    transition duration-300 ease-out motion-reduce:transition-none
                    {{-- الأبعد أصغر وأعلى: الورقتان تطلّان فوق الغلاف كرزمة، وتنفرجان عند المرور --}}
                    {{ $layer === 1
                        ? 'scale-[.86] -translate-y-4 -rotate-[4deg] group-hover/stack:-rotate-[9deg] group-hover/stack:-translate-x-[9%] group-hover/stack:-translate-y-3 group-focus-within/stack:-rotate-[9deg] group-focus-within/stack:-translate-x-[9%]'
                        : 'scale-[.93] -translate-y-2 rotate-[2deg] group-hover/stack:rotate-[6deg] group-hover/stack:translate-x-[7%] group-hover/stack:-translate-y-1.5 group-focus-within/stack:rotate-[6deg] group-focus-within/stack:translate-x-[7%]' }}">
            @if ($asset)
                <img src="{{ $asset->thumbUrl() }}" alt="" loading="lazy" decoding="async"
                     class="w-full h-full object-cover {{ $fallback ? 'brightness-[.8] saturate-[.6]' : '' }}">
            @endif
        </div>
    @endforeach

    {{-- الورقة الأمامية: الغلاف --}}
    <div class="card-interactive overflow-hidden"
         :class="selecting && isGroupSelected(ids) && '!border-brand-500 ring-2 ring-brand-500/40'">
        <div class="relative">
            <button
                type="button"
                @click="selecting ? toggleGroup(ids) : openCarouselViewer({{ $item->id }}, 0)"
                :aria-pressed="selecting ? (isGroupSelected(ids) ? 'true' : 'false') : null"
                class="block w-full"
            >
                @if ($cover)
                    <img src="{{ $cover->thumbUrl() }}" alt="غلاف الكاروسيل: {{ Str::limit($title, 80) }}"
                         loading="lazy" decoding="async" class="aspect-square w-full object-cover bg-muted">
                @else
                    <span class="aspect-square w-full grid place-items-center bg-muted text-fg-subtle">
                        <x-icon name="layers" class="w-8 h-8" />
                    </span>
                @endif
                <span class="sr-only">عرض شرائح الكاروسيل</span>
            </button>

            {{-- وسم الكاروسيل وعدد شرائحه --}}
            <span x-show="! selecting"
                  class="pointer-events-none absolute top-2 start-2 inline-flex items-center gap-1 px-2 py-1 rounded-full
                         bg-black/55 text-white text-[10px] font-semibold backdrop-blur-sm">
                <x-icon name="layers" class="w-3 h-3" />
                كاروسيل · <span class="tnum">{{ $total }}</span>
            </span>

            {{-- مربع التحديد الجماعي: يحدد كل صور الكاروسيل --}}
            <span x-show="selecting" x-cloak aria-hidden="true"
                  :class="isGroupSelected(ids) ? 'bg-brand-600 border-brand-600 text-white' : 'bg-white/85 border-white text-transparent'"
                  class="pointer-events-none absolute top-2 start-2 grid place-items-center w-6 h-6 rounded-lg border-2 shadow-sm transition">
                <x-icon name="check" class="w-3.5 h-3.5" />
            </span>

            {{-- تكبير: ظاهر دائماً (لا يعتمد على المرور، فيعمل باللمس) --}}
            <button type="button" x-show="! selecting"
                    @click="openCarouselViewer({{ $item->id }}, 0)"
                    class="absolute top-2 end-2 grid place-items-center w-8 h-8 rounded-full bg-black/55 text-white backdrop-blur-sm
                           hover:bg-black/75 focus-visible:ring-2 focus-visible:ring-white transition"
                    title="تكبير وعرض الشرائح">
                <x-icon name="expand" class="w-3.5 h-3.5" />
                <span class="sr-only">تكبير وعرض الشرائح</span>
            </button>

            {{-- نقاط الشرائح: ممتلئة لما له صورة، مفرغة لما ينتظر --}}
            <div x-show="! selecting" aria-hidden="true"
                 class="pointer-events-none absolute inset-x-0 bottom-2 flex justify-center gap-1 transition group-hover/stack:opacity-0">
                @foreach (array_keys($slides) as $index)
                    <span class="w-1.5 h-1.5 rounded-full {{ $images->has($index) ? 'bg-white' : 'bg-white/40 ring-1 ring-white/70' }}"></span>
                @endforeach
            </div>

            {{-- إجراءات عند المرور --}}
            <div x-show="! selecting"
                 class="absolute inset-x-0 bottom-0 flex items-center gap-1.5 p-2 bg-gradient-to-t from-black/70 to-transparent
                        opacity-0 transition group-hover/stack:opacity-100 group-focus-within/stack:opacity-100">
                <button type="button" @click="openCarouselViewer({{ $item->id }}, 0)"
                        class="inline-flex items-center gap-1.5 px-2.5 min-h-9 rounded-lg bg-white/90 text-gray-900 text-xs font-semibold hover:bg-white">
                    <x-icon name="eye" class="w-3.5 h-3.5" />
                    عرض الشرائح
                </button>
                <button type="button" @click="openCarouselEditor({{ $item->id }}, 0)"
                        class="grid place-items-center w-9 h-9 rounded-lg bg-white/90 text-gray-900 hover:bg-white ms-auto"
                        title="تعديل الكاروسيل">
                    <x-icon name="pencil" class="w-3.5 h-3.5" />
                    <span class="sr-only">تعديل الكاروسيل</span>
                </button>
            </div>
        </div>

        <figcaption class="p-2.5">
            <p class="text-[11px] text-fg-muted line-clamp-2 leading-relaxed">
                <span class="font-semibold text-brand-700 dark:text-brand-400">كاروسيل:</span>
                {{ Str::limit($title, 90) }}
            </p>
            <p class="flex items-center justify-between gap-2 text-[10px] text-fg-subtle mt-1.5">
                <span class="tnum whitespace-nowrap shrink-0">
                    @if ($ready < $total)
                        {{ $ready }}/{{ $total }} صور جاهزة
                    @else
                        {{ $total }} شرائح جاهزة
                    @endif
                </span>
                @if ($modelLabel)
                    <span class="truncate min-w-0" title="النموذج المستخدم" dir="ltr">{{ $modelLabel }}</span>
                @endif
            </p>
        </figcaption>
    </div>
</figure>
