{{--
    شبكة المعرض + شريط أدواته (مجلدات، تحديد جماعي، بحث، فرز، حجم الشبكة).
    البحث والفرز حقيقيان (معاملات GET على ImageStudioController::index).
    حجم الشبكة تفضيل عرض بحت (Alpine محلي). التحديد الجماعي (حذف/نقل) حالة في
    imageStudio (selecting/selected) لأن شريط إجراءاته وبطاقاته يتشاركانها.
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

        @if ($gallery->isNotEmpty())
            <button type="button" @click="selecting ? stopSelecting() : startSelecting()"
                    :aria-pressed="selecting ? 'true' : 'false'"
                    :class="selecting ? 'border-brand-400 bg-brand-50 text-brand-700 dark:bg-brand-900/40 dark:text-brand-300' : 'border-line'"
                    class="btn btn-ghost gap-1.5 rounded-full border">
                <x-icon name="check-circle" class="w-4 h-4" />
                <span x-text="selecting ? 'إنهاء التحديد' : 'تحديد'">تحديد</span>
            </button>
        @endif

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

    {{-- شريط التحديد الجماعي --}}
    @if ($gallery->isNotEmpty())
        <div
            x-show="selecting" x-cloak
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 -translate-y-1"
            x-transition:enter-end="opacity-100 translate-y-0"
            class="flex flex-wrap items-center gap-2 mb-3 p-2 ps-3 rounded-2xl border border-brand-200 dark:border-brand-900 bg-brand-50/70 dark:bg-brand-900/30"
            role="toolbar" aria-label="إجراءات الصور المحددة"
        >
            <span class="text-sm font-semibold text-fg">
                <span class="tnum" x-text="selectedCount">0</span> محددة
            </span>

            <button type="button" @click="selectAll(@js($gallery->pluck('id')->values()))" class="btn btn-ghost btn-sm"
                    x-text="selectedCount === {{ $gallery->count() }} ? 'إلغاء تحديد الكل' : 'تحديد الكل ({{ $gallery->count() }})'"></button>

            <div class="flex flex-wrap items-center gap-1.5 ms-auto">
                <form method="POST" action="{{ route('studio.media.bulk-move') }}" class="flex items-center gap-1.5" data-busy-on-submit>
                    @csrf
                    <template x-for="id in selected" :key="id">
                        <input type="hidden" name="ids[]" :value="id">
                    </template>

                    <select name="folder_id" aria-label="نقل إلى" class="field w-auto min-h-9 py-1 text-xs rounded-lg">
                        <option value="">بلا مجلد (الكل)</option>
                        @foreach ($folders as $folder)
                            <option value="{{ $folder->id }}">{{ $folder->name }}</option>
                        @endforeach
                    </select>

                    <button type="submit" class="btn-secondary btn-sm" :disabled="! selectedCount">
                        <x-icon name="folder" class="w-3.5 h-3.5" />
                        <span>نقل</span>
                    </button>
                </form>

                <x-confirm
                    :action="route('studio.media.bulk-destroy')"
                    method="POST"
                    title="حذف الصور المحددة نهائياً؟"
                    message="لا يمكن التراجع — تُحذف الصور المحددة من الاستوديو والتخزين."
                    confirm="حذف نهائياً"
                    label="حذف المحدد"
                    :icon-only="false"
                    x-bind:disabled="! selectedCount"
                    class="!text-danger-fg hover:!bg-danger-soft border border-danger/25"
                >
                    <template x-for="id in selected" :key="id">
                        <input type="hidden" name="ids[]" :value="id">
                    </template>
                </x-confirm>

                <button type="button" @click="stopSelecting()" class="btn btn-ghost btn-sm">إلغاء</button>
            </div>
        </div>
    @endif

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
        {{-- عمودان على الجوال (شريط الحجم مخفي هناك)، ومن sm يتبع اختيار المستخدم --}}
        <div class="grid grid-cols-2 sm:[grid-template-columns:repeat(var(--cols),minmax(0,1fr))] gap-x-3 sm:gap-x-4 gap-y-6 pt-4"
             style="--cols: 4" :style="`--cols: ${gridCols}`">
            @foreach ($galleryEntries as $entry)
                @if ($entry['type'] === 'carousel')
                    @include('content.studio._carousel-stack', ['item' => $entry['item']])
                @else
                    @include('content.studio._gallery-card', ['asset' => $entry['asset']])
                @endif
            @endforeach
        </div>

        @if ($galleryHasMore)
            <div class="flex justify-center mt-5">
                <a href="{{ route('studio.index', array_merge(request()->only(['q', 'sort', 'folder', 'pinned']), ['limit' => $galleryLimit + 60])) }}"
                   class="btn btn-secondary">
                    عرض المزيد
                </a>
            </div>
        @endif
    @endif
</section>
