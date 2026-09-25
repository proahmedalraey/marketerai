{{--
    "رفع من الجهاز" حقيقي الآن (المرحلة الثانية، البند 2.8): يرفع فوراً عبر
    fetch إلى studio.uploads وينشئ MediaAsset مرجعياً دون أي توليد.
    "من المنتجات" و"الصور المرفوعة مسبقاً" حقيقيان أيضاً.
--}}
<div
    data-popover
    x-show="popover === 'upload'"
    x-cloak
    x-transition.opacity.duration.150ms
    @click.outside="popover = null"
    x-data="{ showProducts: false, productQuery: '' }"
    class="absolute bottom-full start-0 mb-2 z-20 w-72 max-w-[90vw] p-1.5 card shadow-pop motion-safe:animate-scale-in"
>
    <template x-if="! showProducts">
        <div>
            <label
                class="flex items-center gap-2.5 w-full px-2.5 min-h-11 rounded-lg text-sm text-fg hover:bg-muted cursor-pointer"
                :class="uploading && 'opacity-60 pointer-events-none'"
            >
                <x-icon name="upload" class="w-4 h-4 text-fg-subtle" />
                <span class="flex-1 text-start">
                    <span class="block" x-text="uploading ? 'جارٍ الرفع…' : 'رفع من الجهاز'"></span>
                    <span class="block text-[11px] text-fg-subtle">شخصية أو عنصر · حتى 10 ميغابايت</span>
                </span>
                <input
                    type="file" accept="image/png,image/jpeg,image/webp" class="sr-only"
                    @change="uploadReference($event.target.files[0]); $event.target.value = ''"
                >
            </label>

            <p x-show="uploadError" x-cloak x-text="uploadError" class="px-2.5 pb-1.5 text-[11px] text-danger-fg"></p>

            <button type="button" @click="showProducts = true"
                    class="flex items-center gap-2.5 w-full px-2.5 min-h-11 rounded-lg text-sm text-fg hover:bg-muted">
                <x-icon name="package" class="w-4 h-4 text-fg-subtle" />
                <span class="flex-1 text-start">
                    <span class="block">من المنتجات</span>
                    <span class="block text-[11px] text-fg-subtle">صور المنتج كعناصر</span>
                </span>
            </button>

            <button type="button" @click="popover = null; referencePickerOpen = true"
                    class="flex items-center gap-2.5 w-full px-2.5 min-h-11 rounded-lg text-sm text-fg hover:bg-muted">
                <x-icon name="image" class="w-4 h-4 text-fg-subtle" />
                <span class="flex-1 text-start">
                    <span class="block">الصور المرفوعة مسبقاً</span>
                    <span class="block text-[11px] text-fg-subtle">مكتبتك الخاصة</span>
                </span>
            </button>
        </div>
    </template>

    <template x-if="showProducts">
        <div>
            <button type="button" @click="showProducts = false; productQuery = ''"
                    class="flex items-center gap-1.5 w-full px-2.5 min-h-9 rounded-lg text-xs font-medium text-fg-muted hover:bg-muted">
                <x-icon name="arrow-right" class="w-3.5 h-3.5" />
                رجوع
            </button>

            @if ($products->isNotEmpty())
                <label class="relative block px-1 py-1">
                    <x-icon name="search" class="w-3.5 h-3.5 text-fg-subtle absolute top-1/2 -translate-y-1/2 start-3.5" />
                    <input
                        type="search" x-model="productQuery" placeholder="ابحث في المنتجات…"
                        class="field ps-8 py-1.5 text-sm"
                    >
                </label>
            @endif

            <div class="max-h-56 overflow-y-auto space-y-0.5 mt-1">
                @forelse ($products as $product)
                    <button type="button" @click="pickProduct({{ $product->id }})"
                            x-show="! productQuery || @js(mb_strtolower($product->title)).includes(productQuery.toLowerCase())"
                            :class="productId == {{ $product->id }} && referenceMode === 'product' ? 'bg-muted' : 'hover:bg-muted'"
                            class="flex items-center gap-2 w-full px-2.5 min-h-10 rounded-lg text-sm text-fg text-start">
                        <span class="flex-1 truncate">{{ $product->title }}</span>
                        <x-icon name="check" class="w-3.5 h-3.5 text-brand-600 dark:text-brand-400 shrink-0"
                                x-show="productId == {{ $product->id }} && referenceMode === 'product'" />
                    </button>
                @empty
                    <p class="px-2.5 py-3 text-xs text-fg-subtle">لا منتجات نشطة بعد.</p>
                @endforelse
            </div>
        </div>
    </template>
</div>
