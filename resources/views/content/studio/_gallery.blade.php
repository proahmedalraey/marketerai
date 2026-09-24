{{--
    شبكة المعرض + شريط أدواته (مجلدات، بحث، فرز، حجم الشبكة).
    البحث والفرز حقيقيان (معاملات GET على ImageStudioController::index).
    حجم الشبكة تفضيل عرض بحت (Alpine محلي). لوحة المجلدات شكل مطابق للصور
    المرجعية — بلا وظيفة فعلية بعد سوى "الكل" (قرار §4).
--}}
<section x-data="{ foldersOpen: false, gridCols: 4 }">
    <div class="flex flex-wrap items-center gap-2 mb-3">
        {{-- الكل / المجلدات --}}
        <div class="relative">
            <button type="button" @click="foldersOpen = ! foldersOpen"
                    :aria-expanded="foldersOpen ? 'true' : 'false'"
                    class="btn btn-ghost gap-1.5 rounded-full border border-line">
                <x-icon name="folder" class="w-4 h-4 text-fg-subtle" />
                الكل
                <x-icon name="chevron-down" class="w-3.5 h-3.5 text-fg-subtle" />
            </button>

            @include('content.studio._folders-panel')
        </div>

        <form method="GET" action="{{ route('studio.index') }}" class="flex items-center gap-2 ms-auto">
            @if ($activeFolder)
                <input type="hidden" name="folder" value="{{ $activeFolder }}">
            @elseif ($pinnedOnly)
                <input type="hidden" name="pinned" value="1">
            @endif

            <label class="relative">
                <x-icon name="search" class="w-4 h-4 text-fg-subtle absolute top-1/2 -translate-y-1/2 start-3" />
                <input
                    type="search" name="q" value="{{ $search }}"
                    placeholder="ابحث في الصور…"
                    class="field ps-9 w-44 sm:w-60 rounded-full"
                >
            </label>

            <select name="sort" onchange="this.form.submit()" class="field w-auto rounded-full">
                <option value="newest" @selected($sort !== 'oldest')>الأحدث</option>
                <option value="oldest" @selected($sort === 'oldest')>الأقدم</option>
            </select>

            {{-- حجم الشبكة: تفضيل عرض محلي، لا يُرسَل للخادم --}}
            <label class="hidden sm:flex items-center gap-2 px-2" title="حجم الشبكة">
                <x-icon name="grid" class="w-3.5 h-3.5 text-fg-subtle shrink-0" />
                <input type="range" min="2" max="6" step="1" x-model.number="gridCols" class="card-size-range w-20">
            </label>
        </form>
    </div>

    @if ($activeFolder || $pinnedOnly)
        <div class="flex items-center gap-1.5 mb-3">
            <span class="chip-brand gap-1.5">
                <x-icon :name="$pinnedOnly ? 'pin' : 'folder'" class="w-3.5 h-3.5" />
                {{ $pinnedOnly ? 'المثبتة' : $folders->firstWhere('id', $activeFolder)?->name }}
                <a href="{{ route('studio.index', array_filter(['q' => $search ?: null])) }}" class="hover:opacity-70">
                    <x-icon name="close" class="w-3 h-3" />
                    <span class="sr-only">إزالة الفلتر</span>
                </a>
            </span>
        </div>
    @endif

    @if ($gallery->isEmpty())
        <x-empty-state
            icon="image"
            :title="$search ? 'لا نتائج مطابقة' : 'ما ولّدت صوراً بعد'"
            :description="$search ? 'جرّب كلمات أخرى من وصف الصورة.' : 'صِف المشهد في الشريط أسفل الصفحة، وستظهر النتائج هنا.'"
        />
    @else
        <div class="grid gap-3" :style="`grid-template-columns: repeat(${gridCols}, minmax(0, 1fr))`">
            @foreach ($gallery as $asset)
                {{-- menuPos: overflow-hidden أعلاه يقصّ أي popover داخلي، فالقائمة تُبثّ
                     (x-teleport) لجسم الصفحة وتُموضَع بإحداثيات الزر الفعلية --}}
                <figure class="card-interactive overflow-hidden group" x-data="{ menu: false, menuPos: { top: 0, left: 0 }, showFolders: false }">
                    <div class="relative">
                        <button
                            type="button"
                            @click="openLightbox({ id: {{ $asset->id }}, url: @js($asset->url()), prompt: @js($asset->prompt), pinned: {{ $asset->is_pinned ? 'true' : 'false' }}, pinUrl: @js(route('studio.media.pin', $asset)) })"
                            class="block w-full"
                        >
                            <img
                                src="{{ $asset->url() }}"
                                alt="{{ Str::limit($asset->prompt, 100) ?: 'صورة مولّدة' }}"
                                loading="lazy" decoding="async"
                                class="aspect-square w-full object-cover bg-muted"
                            >
                        </button>

                        <div class="absolute inset-x-0 bottom-0 flex items-center gap-1.5 p-2
                                    bg-gradient-to-t from-black/70 to-transparent
                                    opacity-0 transition group-hover:opacity-100 group-focus-within:opacity-100">
                            <a
                                href="{{ $asset->url() }}" download
                                class="inline-flex items-center gap-1.5 px-2.5 min-h-9 rounded-lg bg-white/90 text-gray-900 text-xs font-semibold hover:bg-white"
                            >
                                <x-icon name="download" class="w-3.5 h-3.5" />
                                تحميل
                            </a>

                            <a
                                href="{{ $asset->url() }}" target="_blank" rel="noopener"
                                class="grid place-items-center w-9 h-9 rounded-lg bg-white/90 text-gray-900 hover:bg-white"
                            >
                                <x-icon name="external" class="w-3.5 h-3.5" />
                                <span class="sr-only">فتح بالحجم الكامل</span>
                            </a>

                            <div class="relative ms-auto">
                                <button
                                    type="button"
                                    @click="menuPos = $el.getBoundingClientRect(); menu = ! menu"
                                    :aria-expanded="menu ? 'true' : 'false'" aria-haspopup="menu"
                                    class="grid place-items-center w-9 h-9 rounded-lg bg-white/90 text-gray-900 hover:bg-white"
                                >
                                    <x-icon name="more-vertical" class="w-3.5 h-3.5" />
                                    <span class="sr-only">إجراءات أخرى</span>
                                </button>

                                @include('content.studio._context-menu')
                            </div>
                        </div>
                    </div>

                    <figcaption class="p-2.5">
                        <p class="text-[11px] text-fg-muted line-clamp-2 leading-relaxed">
                            {{ Str::limit($asset->prompt, 90) }}
                        </p>
                        <p class="text-[10px] text-fg-subtle mt-1.5 tnum" dir="ltr">
                            {{ $asset->width }}×{{ $asset->height }}
                        </p>
                    </figcaption>
                </figure>
            @endforeach
        </div>
    @endif
</section>
