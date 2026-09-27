{{-- بطاقة صورة مفردة في شبكة «الاستوديو» (المتغير: $asset). الكاروسيل له _carousel-stack. --}}
@php
    $transparent = $asset->isTransparent();
    $shareable = ['id' => $asset->id, 'url' => $asset->url()];
@endphp
{{-- menuPos: overflow-hidden أعلاه يقصّ أي popover داخلي، فالقائمة تُبثّ
     (x-teleport) لجسم الصفحة وتُموضَع بإحداثيات الزر الفعلية --}}
<figure
    class="card-interactive overflow-hidden group"
    :class="selecting && isSelected({{ $asset->id }}) && '!border-brand-500 ring-2 ring-brand-500/40'"
    x-data="{ menu: false, menuPos: { top: 0, left: 0 }, showFolders: false }"
>
    <div class="relative">
        <button
            type="button"
            @click="selecting
                ? toggleSelected({{ $asset->id }})
                : openLightbox({ id: {{ $asset->id }}, url: @js($asset->url()), prompt: @js($asset->prompt), pinned: {{ $asset->is_pinned ? 'true' : 'false' }}, pinUrl: @js(route('studio.media.pin', $asset)), model: @js($studioModelService->labelFor($asset->meta['model'] ?? null)), size: @js($asset->width.'×'.$asset->height), transparent: {{ $transparent ? 'true' : 'false' }} })"
            :aria-pressed="selecting ? (isSelected({{ $asset->id }}) ? 'true' : 'false') : null"
            class="block w-full"
        >
            <img
                src="{{ $asset->thumbUrl() }}"
                alt="{{ Str::limit($asset->prompt, 100) ?: 'صورة مولّدة' }}"
                loading="lazy" decoding="async"
                class="aspect-square w-full {{ $transparent ? 'object-contain bg-checker' : 'object-cover bg-muted' }}"
            >
        </button>

        {{-- مربع التحديد --}}
        <span
            x-show="selecting" x-cloak aria-hidden="true"
            :class="isSelected({{ $asset->id }}) ? 'bg-brand-600 border-brand-600 text-white' : 'bg-white/85 border-white text-transparent'"
            class="pointer-events-none absolute top-2 start-2 grid place-items-center w-6 h-6 rounded-lg border-2 shadow-sm transition"
        >
            <x-icon name="check" class="w-3.5 h-3.5" />
        </span>

        @if ($transparent)
            <span class="pointer-events-none absolute top-2 end-2 chip bg-white/90 text-gray-900 text-[10px] shadow-sm">بلا خلفية</span>
        @endif

        <div x-show="! selecting"
             class="absolute inset-x-0 bottom-0 flex items-center gap-1.5 p-2
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
                    @click="menuPos = $el.getBoundingClientRect(); menu = ! menu; menu && prepareShare(@js($shareable))"
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
        {{-- صورة شريحة كاروسيل: نص الشريحة بالعربية، لا برومبت الصورة الإنجليزي --}}
        @if ($asset->slide_index !== null && $asset->contentItem)
            <p class="text-[11px] text-fg-muted line-clamp-2 leading-relaxed">
                <span class="font-semibold text-brand-700 dark:text-brand-400">الشريحة {{ $asset->slide_index + 1 }}:</span>
                {{ Str::limit($asset->contentItem->slides()[$asset->slide_index]['text'] ?? '', 90) }}
            </p>
        @else
            <p class="text-[11px] text-fg-muted line-clamp-2 leading-relaxed">
                {{ Str::limit($asset->prompt, 90) }}
            </p>
        @endif
        <p class="flex items-center justify-between gap-2 text-[10px] text-fg-subtle mt-1.5">
            <span class="tnum" dir="ltr">{{ $asset->width }}×{{ $asset->height }}</span>
            @if ($modelLabel = $studioModelService->labelFor($asset->meta['model'] ?? null))
                <span class="truncate" title="النموذج المستخدم" dir="ltr">{{ $modelLabel }}</span>
            @endif
        </p>
    </figcaption>
</figure>
