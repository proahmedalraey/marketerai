{{--
    حفظ صورة من المعرض كصورة مرجعية أساسية لمنتج (بند «قريباً» سابقاً في قائمة الصورة).
    نافذة واحدة للصفحة كلها: القائمة لكل بطاقة كانت ستكرر المنتجات 60 مرة في الـHTML.
    الصورة تُنسخ لصور المنتج وتصير مرجعه (is_reference) فتُرفق في توليداته القادمة.
--}}
<div
    x-show="productTarget"
    x-cloak
    class="fixed inset-0 z-[70] overflow-y-auto"
    role="dialog"
    aria-modal="true"
    aria-labelledby="product-picker-title"
    @keydown.escape.window="productTarget = null"
>
    <div
        x-show="productTarget"
        x-transition.opacity.duration.150ms
        @click="productTarget = null"
        class="fixed inset-0 bg-scrim/50 backdrop-blur-sm"
        aria-hidden="true"
    ></div>

    <div class="relative min-h-full grid place-items-center p-4">
        <template x-if="productTarget">
            <div x-data="{ q: '' }" class="relative w-full max-w-md card shadow-pop motion-safe:animate-scale-in max-h-[85vh] flex flex-col">
                <header class="flex items-center gap-3 px-5 py-4 border-b border-line shrink-0">
                    <img :src="productTarget.url" alt=""
                         :class="productTarget.transparent ? 'object-contain bg-checker' : 'object-cover bg-muted'"
                         class="w-11 h-11 rounded-lg border border-line shrink-0">
                    <div class="flex-1 min-w-0">
                        <h2 id="product-picker-title" class="text-sm font-bold text-fg">صورة مرجعية أساسية لمنتج</h2>
                        <p class="text-[11px] text-fg-subtle leading-relaxed">تُضاف لصور المنتج وتُرفق تلقائياً في كل صورة تولّدها له.</p>
                    </div>
                    <button type="button" @click="productTarget = null" class="btn btn-ghost btn-sm btn-icon">
                        <x-icon name="close" class="w-4 h-4" />
                        <span class="sr-only">إغلاق</span>
                    </button>
                </header>

                @if ($products->isEmpty())
                    <div class="p-5">
                        <x-empty-state icon="package" title="لا منتجات نشطة" description="أضف منتجاً من صفحة المنتجات ثم عد لحفظ الصورة كمرجع له." />
                    </div>
                @else
                    @if ($products->count() > 6)
                        <label class="relative block px-4 pt-3 shrink-0">
                            <x-icon name="search" class="w-3.5 h-3.5 text-fg-subtle absolute top-1/2 translate-y-[20%] start-7" />
                            <input type="search" x-model="q" placeholder="ابحث في المنتجات…" class="field ps-9 py-1.5 text-sm">
                        </label>
                    @endif

                    <form method="POST" :action="toProductUrl.replace('__ASSET__', productTarget.id)" class="p-2 overflow-y-auto" data-busy-on-submit>
                        @csrf
                        @foreach ($products as $product)
                            @php
                                $max = \App\Models\Product::MAX_IMAGES[$product->type->value] ?? 6;
                                $full = $product->images_count >= $max;
                            @endphp
                            <button
                                type="submit" name="product_id" value="{{ $product->id }}"
                                x-show="! q || @js(mb_strtolower($product->title)).includes(q.toLowerCase())"
                                @disabled($full)
                                @if ($full) title="وصل للحد الأقصى ({{ $max }} صور) — احذف صورة من صفحة المنتج أولاً" @endif
                                class="flex items-center gap-3 w-full px-3 min-h-11 rounded-xl text-sm text-start transition
                                       {{ $full ? 'text-fg-subtle cursor-not-allowed opacity-60' : 'text-fg hover:bg-muted' }}"
                            >
                                <x-icon name="package" class="w-4 h-4 text-fg-subtle shrink-0" />
                                <span class="flex-1 truncate">{{ $product->title }}</span>
                                <span class="text-[11px] text-fg-subtle tnum shrink-0">{{ $product->images_count }}/{{ $max }}</span>
                            </button>
                        @endforeach
                    </form>
                @endif
            </div>
        </template>
    </div>
</div>
