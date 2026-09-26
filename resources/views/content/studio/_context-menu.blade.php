{{--
    قائمة إجراءات الصورة — كل بنودها حقيقية:
      - نشر إلى السوشيال: قائمة المشاركة في الجهاز (Web Share) أو نسخ الصورة للحافظة.
        لا نشر آلي على حسابات المنصات: لا تكامل مع واجهاتها بعد، ولا ندّعيه.
      - إعادة التوليد، نقل إلى مجلد، إزالة الخلفية (مهمة طابور بنقاط)، حفظها مرجعاً لمنتج، حذف.
    التفاصيل في docs/image-studio-redesign-plan.md.

    تُبثّ (x-teleport) لجسم الصفحة بموضع ثابت: الأب (figure) عليه overflow-hidden
    لقصّ زوايا الصورة، وأي popover absolute بداخله يُقصّ معه. menuPos تُحسب من
    getBoundingClientRect() للزر عند كل نقرة (في _gallery.blade.php).
--}}
<template x-teleport="body">
    <div
        x-show="menu"
        x-cloak
        @click.outside="menu = false"
        :style="`top: ${menuPos.bottom + 8}px; left: ${Math.max(8, menuPos.right - 256)}px`"
        class="fixed z-40 w-64 p-1.5 card shadow-pop motion-safe:animate-scale-in"
        role="menu"
    >
        <button
            type="button" role="menuitem"
            @click="menu = false; shareAsset(@js($shareable))"
            class="flex items-center gap-2.5 w-full px-2.5 min-h-10 rounded-lg text-sm text-fg hover:bg-muted"
        >
            <x-icon name="send" class="w-4 h-4 text-fg-subtle" />
            <span class="flex-1 text-start leading-tight">
                نشر إلى السوشيال ميديا
                <span class="block text-[10px] text-fg-subtle">عبر تطبيقات جهازك</span>
            </span>
        </button>

        <form method="POST" action="{{ route('studio.regenerate', $asset) }}" data-busy-on-submit>
            @csrf
            <button type="submit" role="menuitem" class="flex items-center gap-2.5 w-full px-2.5 min-h-10 rounded-lg text-sm text-fg hover:bg-muted">
                <x-icon name="refresh" class="w-4 h-4 text-fg-subtle" />
                <span class="flex-1 text-start">إعادة التوليد</span>
            </button>
        </form>

        {{-- نقل إلى مجلد --}}
        <template x-if="! showFolders">
            <button
                type="button" @click="showFolders = true"
                role="menuitem"
                class="flex items-center gap-2.5 w-full px-2.5 min-h-10 rounded-lg text-sm text-fg hover:bg-muted"
            >
                <x-icon name="folder" class="w-4 h-4 text-fg-subtle" />
                <span class="flex-1 text-start">نقل إلى مجلد</span>
                <x-icon name="chevron-left" class="w-3.5 h-3.5 text-fg-subtle" />
            </button>
        </template>

        <template x-if="showFolders">
            <div>
                <button type="button" @click="showFolders = false"
                        class="flex items-center gap-1.5 w-full px-2.5 min-h-9 rounded-lg text-xs font-medium text-fg-muted hover:bg-muted">
                    <x-icon name="arrow-right" class="w-3.5 h-3.5" />
                    رجوع
                </button>

                @if ($asset->folder_id)
                    <form method="POST" action="{{ route('studio.media.move', $asset) }}" data-busy-on-submit>
                        @csrf
                        <button type="submit" role="menuitem" class="flex items-center gap-2.5 w-full px-2.5 min-h-9 rounded-lg text-sm text-fg-muted hover:bg-muted">
                            <x-icon name="grid" class="w-3.5 h-3.5" />
                            <span class="flex-1 text-start">بلا مجلد (الكل)</span>
                        </button>
                    </form>
                @endif

                <div class="max-h-40 overflow-y-auto">
                    @forelse ($folders as $folder)
                        <form method="POST" action="{{ route('studio.media.move', $asset) }}" data-busy-on-submit>
                            @csrf
                            <input type="hidden" name="folder_id" value="{{ $folder->id }}">
                            <button
                                type="submit" role="menuitem"
                                class="flex items-center gap-2.5 w-full px-2.5 min-h-9 rounded-lg text-sm text-start
                                       {{ $asset->folder_id === $folder->id ? 'bg-muted text-fg' : 'text-fg hover:bg-muted' }}"
                            >
                                <x-icon name="folder" class="w-3.5 h-3.5 text-fg-subtle shrink-0" />
                                <span class="flex-1 truncate">{{ $folder->name }}</span>
                                @if ($asset->folder_id === $folder->id)
                                    <x-icon name="check" class="w-3.5 h-3.5 text-brand-600 dark:text-brand-400 shrink-0" />
                                @endif
                            </button>
                        </form>
                    @empty
                        <p class="px-2.5 py-3 text-xs text-fg-subtle">لا مجلدات بعد.</p>
                    @endforelse
                </div>
            </div>
        </template>

        {{-- إزالة الخلفية: صورة شفافة جديدة بجانب الأصل --}}
        @if ($transparent)
            <p class="flex items-center gap-2.5 px-2.5 min-h-10 text-sm text-fg-subtle">
                <x-icon name="layers" class="w-4 h-4" />
                <span class="flex-1">الصورة بلا خلفية أصلاً</span>
            </p>
        @elseif ($removeBackgroundAvailable)
            <form method="POST" action="{{ route('studio.media.remove-background', $asset) }}" data-busy-on-submit>
                @csrf
                <button type="submit" role="menuitem" class="flex items-center gap-2.5 w-full px-2.5 min-h-10 rounded-lg text-sm text-fg hover:bg-muted">
                    <x-icon name="layers" class="w-4 h-4 text-fg-subtle" />
                    <span class="flex-1 text-start">إزالة الخلفية</span>
                    <span class="chip-neutral text-[10px] tnum">{{ fmod($removeBackgroundCost, 1.0) === 0.0 ? (int) $removeBackgroundCost : $removeBackgroundCost }} نقطة</span>
                </button>
            </form>
        @else
            <button type="button" disabled role="menuitem"
                    title="تتطلب مزود الصور OpenRouter من إعدادات المنصة"
                    class="flex items-center gap-2.5 w-full px-2.5 min-h-10 rounded-lg text-sm text-fg-subtle cursor-not-allowed opacity-60">
                <x-icon name="layers" class="w-4 h-4" />
                <span class="flex-1 text-start">إزالة الخلفية</span>
                <span class="text-[10px]">تتطلب OpenRouter</span>
            </button>
        @endif

        {{-- حفظها مرجعاً بصرياً أساسياً لمنتج: قائمة المنتجات في نافذة واحدة للصفحة كلها --}}
        <button
            type="button" role="menuitem"
            @click="menu = false; openProductPicker({ id: {{ $asset->id }}, url: @js($asset->thumbUrl()), transparent: {{ $transparent ? 'true' : 'false' }} })"
            class="flex items-center gap-2.5 w-full px-2.5 min-h-10 rounded-lg text-sm text-fg hover:bg-muted"
        >
            <x-icon name="target" class="w-4 h-4 text-fg-subtle" />
            <span class="flex-1 text-start">حفظ الصورة كصورة مرجعية أساسية في المنتج</span>
        </button>

        <div class="divider my-1.5"></div>

        <x-confirm
            :action="route('studio.media.destroy', $asset)"
            title="حذف هذه الصورة؟"
            message="لا يمكن التراجع — تُحذف الصورة نهائياً من الاستوديو والتخزين."
            confirm="حذف نهائياً"
            label="حذف"
            icon="trash"
            :icon-only="false"
            class="!flex !items-center !gap-2.5 !w-full !justify-start !px-2.5 !min-h-10 !rounded-lg !text-sm !text-danger-fg hover:!bg-danger-soft"
        />
    </div>
</template>
