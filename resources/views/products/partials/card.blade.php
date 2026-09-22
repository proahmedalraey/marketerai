@php
    $image = $product->images->first();
    $imageCount = $product->images->count();

    $countLabel = match (true) {
        $imageCount === 0 => 'بلا صور',
        $imageCount === 1 => 'صورة',
        $imageCount === 2 => 'صورتان',
        default => "{$imageCount} صور",
    };
@endphp

{{--
    بنية واحدة تخدم وضعَي العرض وكل المقاسات.
    التبديل بينهما في CSS لا في Blade، فلا نصون نسختين من البطاقة.
--}}
<article
    class="product-card"
    :class="selected.includes({{ $product->id }}) && 'ring-2 ring-brand-500 border-brand-500'"
>
    {{-- ---------- الوسائط ---------- --}}
    <div class="product-card__media">
        @if ($image)
            <img
                src="{{ $image->url() }}"
                alt="صورة {{ $product->title }}"
                loading="lazy" decoding="async"
                class="w-full h-full object-cover"
            >
        @else
            <div class="w-full h-full grid place-items-center text-fg-subtle">
                <x-icon :name="$product->type->icon()" class="w-8 h-8" />
            </div>
        @endif

        {{-- التحديد: مساحة نقر 44px حول المربع نفسه --}}
        <label class="absolute top-0 start-0 grid place-items-center w-11 h-11 cursor-pointer">
            <input
                type="checkbox" value="{{ $product->id }}" x-model.number="selected"
                class="w-4 h-4 rounded border-line-strong bg-card/90 backdrop-blur text-brand-600 focus:ring-brand-500 shadow-sm"
            >
            <span class="sr-only">تحديد {{ $product->title }}</span>
        </label>

        @unless ($product->is_active)
            <span class="absolute top-2 end-2 chip bg-scrim/70 text-white backdrop-blur-sm text-[10px] py-0.5">
                موقوف
            </span>
        @endunless

        {{-- مبدّل المرجع الأساسي يعيش فوق الصورة كما في التصميم --}}
        <form method="POST" action="{{ route('products.primary', $product) }}" class="absolute bottom-2 start-2">
            @csrf
            @if ($product->is_primary)
                <button
                    type="submit"
                    class="chip min-h-6 bg-brand-600 text-white shadow-md hover:bg-brand-700 transition text-[10px] py-0 ps-2 pe-1.5"
                    title="إلغاء المرجعية الأساسية"
                >
                    <x-icon name="target" class="w-3 h-3" />
                    <span>مرجع أساسي</span>
                    <x-icon name="close" class="w-2.5 h-2.5 opacity-70" />
                </button>
            @else
                <button
                    type="submit"
                    class="chip min-h-6 bg-scrim/70 text-white backdrop-blur-sm shadow-md hover:bg-scrim transition text-[10px] py-0"
                    title="اجعله المنتج المختار افتراضياً في «كتابة المحتوى»"
                >
                    <x-icon name="plus" class="w-3 h-3" />
                    <span>مرجع</span>
                </button>
            @endif
        </form>
    </div>

    <div class="product-card__content">

        {{-- ---------- النص ---------- --}}
        <div class="product-card__body">
            <div class="flex items-start gap-2">
                <h3 class="flex-1 min-w-0 text-sm font-semibold text-fg leading-snug">
                    <button
                        type="button"
                        @click="startEdit({{ $product->id }})"
                        class="text-start line-clamp-2 py-1 -my-1 hover:text-brand-700 dark:hover:text-brand-400"
                    >{{ $product->title }}</button>
                </h3>

                <span class="chip-neutral text-[10px] py-0.5 shrink-0">{{ $product->type->label() }}</span>
            </div>

            @if (filled($product->summary))
                <p class="product-card__desc mt-1.5 text-xs text-fg-muted leading-relaxed line-clamp-2">
                    {{ $product->summary }}
                </p>
            @else
                <p class="product-card__desc mt-1.5 flex items-center gap-1 text-[11px] text-warning-fg">
                    <x-icon name="alert" class="w-3 h-3 shrink-0" />
                    بلا وصف
                </p>
            @endif

            <div class="flex items-center gap-2.5 mt-2 text-[11px] text-fg-subtle">
                <span class="flex items-center gap-1">
                    <x-icon name="image" class="w-3 h-3" />
                    {{ $countLabel }}
                </span>

                @if ($product->price !== null)
                    <span class="tnum font-semibold text-fg">
                        {{ rtrim(rtrim(number_format((float) $product->price, 2), '0'), '.') }}
                        <span class="font-normal text-fg-subtle">{{ $product->currency }}</span>
                    </span>
                @endif
            </div>
        </div>

        {{-- ---------- الإجراءات ---------- --}}
        <div class="product-card__actions">
            <button type="button" @click="startEdit({{ $product->id }})" class="btn-secondary btn-sm flex-1">
                <x-icon name="pencil" class="w-3.5 h-3.5" />
                <span class="btn-label">تعديل</span>
            </button>

            <x-confirm
                :action="route('products.destroy', $product)"
                :icon-only="true"
                :label="'حذف '.$product->title"
                title="حذف هذا العنصر؟"
                :message="'سيُحذف «'.$product->title.'» وورقته المرجعية وصوره. المحتوى المُنشأ سابقاً يبقى كما هو.'"
            />

            {{-- الإجراءات الأقل تكراراً --}}
            <div class="relative" x-data="{ menu: false }" @keydown.escape.stop="menu = false">
                <button
                    type="button"
                    @click="menu = ! menu"
                    :aria-expanded="menu ? 'true' : 'false'"
                    aria-haspopup="menu"
                    class="btn btn-ghost btn-sm btn-icon"
                >
                    <x-icon name="list" class="w-3.5 h-3.5" />
                    <span class="sr-only">إجراءات أخرى لـ {{ $product->title }}</span>
                </button>

                <div
                    x-show="menu"
                    x-cloak
                    @click.outside="menu = false"
                    class="absolute bottom-full end-0 mb-2 z-20 w-56 p-1.5 card shadow-pop motion-safe:animate-scale-in"
                    role="menu"
                >
                    <form method="POST" action="{{ route('products.duplicate', $product) }}">
                        @csrf
                        <button type="submit" role="menuitem" class="flex items-center gap-2.5 w-full px-2.5 min-h-10 rounded-lg text-sm text-fg hover:bg-muted">
                            <x-icon name="copy" class="w-4 h-4 text-fg-subtle" />
                            <span>تكرار العنصر</span>
                        </button>
                    </form>

                    <form method="POST" action="{{ route('products.bulk') }}">
                        @csrf
                        <input type="hidden" name="ids[]" value="{{ $product->id }}">
                        <input type="hidden" name="action" value="{{ $product->is_active ? 'deactivate' : 'activate' }}">

                        <button type="submit" role="menuitem" class="flex items-center gap-2.5 w-full px-2.5 min-h-10 rounded-lg text-sm text-fg hover:bg-muted">
                            <x-icon :name="$product->is_active ? 'lock' : 'check-circle'" class="w-4 h-4 text-fg-subtle" />
                            <span>{{ $product->is_active ? 'إيقاف عن التوليد' : 'تفعيل للتوليد' }}</span>
                        </button>
                    </form>

                    <div class="divider my-1.5"></div>

                    <a
                        href="{{ route('content.generator', ['product_id' => $product->id]) }}"
                        role="menuitem"
                        class="flex items-center gap-2.5 w-full px-2.5 min-h-10 rounded-lg text-sm text-fg hover:bg-muted"
                    >
                        <x-icon name="sparkles" class="w-4 h-4 text-fg-subtle" />
                        <span>اكتب محتوى لهذا العنصر</span>
                    </a>

                    @if (filled($product->spec_sheet))
                        <button
                            type="button"
                            role="menuitem"
                            @click="menu = false; $dispatch('spec-sheet', { title: @js($product->title), sheet: @js($product->spec_sheet) })"
                            class="flex items-center gap-2.5 w-full px-2.5 min-h-10 rounded-lg text-sm text-fg hover:bg-muted"
                        >
                            <x-icon name="file-text" class="w-4 h-4 text-fg-subtle" />
                            <span>عرض الورقة المرجعية</span>
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </div>
</article>
