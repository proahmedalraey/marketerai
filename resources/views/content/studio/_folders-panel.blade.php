{{--
    لوحة المجلدات — مجلدات حقيقية الآن (المرحلة الثانية، البند 2.2).
    حذف مجلد لا يحذف صوره (folder_id يعود فارغاً، nullOnDelete). "تحديد للحذف
    الجماعي" يفتح وضع التحديد في المعرض (حذف أو نقل عدة صور دفعة واحدة).
--}}
<div
    x-show="foldersOpen"
    x-cloak
    x-transition.opacity.duration.150ms
    @click.outside="foldersOpen = false"
    x-data="{ addingFolder: false }"
    class="absolute top-full start-0 mt-2 z-20 w-72 p-3 card shadow-pop motion-safe:animate-scale-in"
>
    <a
        href="{{ route('studio.index') }}"
        class="flex items-center gap-2.5 px-2.5 min-h-10 rounded-lg text-sm font-medium
               {{ ! $pinnedOnly && ! $activeFolder ? 'bg-muted text-fg' : 'text-fg-muted hover:bg-muted' }}"
    >
        <x-icon name="grid" class="w-4 h-4" />
        الكل
    </a>

    <a
        href="{{ route('studio.index', ['pinned' => 1]) }}"
        class="flex items-center gap-2.5 px-2.5 min-h-10 rounded-lg text-sm font-medium
               {{ $pinnedOnly ? 'bg-muted text-fg' : 'text-fg-muted hover:bg-muted' }}"
    >
        <x-icon name="pin" class="w-4 h-4" />
        المثبتة
    </a>

    <div class="divider my-2"></div>

    <div class="px-2.5 flex items-center justify-between">
        <span class="text-xs font-semibold text-fg-subtle">المجلدات</span>
        <button type="button" @click="addingFolder = ! addingFolder" class="btn btn-ghost btn-sm">
            <x-icon name="plus" class="w-3.5 h-3.5" />
            مجلد جديد
        </button>
    </div>

    <form
        x-show="addingFolder" x-cloak x-transition
        method="POST" action="{{ route('studio.folders.store') }}"
        class="flex items-center gap-1.5 px-2.5 py-1.5"
    >
        @csrf
        <input
            type="text" name="name" required maxlength="80" placeholder="اسم المجلد"
            class="field py-1.5 text-sm flex-1"
        >
        <button type="submit" class="btn btn-primary btn-sm !min-h-8 !px-3">إضافة</button>
    </form>

    @if ($folders->isEmpty())
        <p class="px-2.5 py-3 text-xs text-fg-subtle leading-relaxed">
            لا مجلدات بعد — أنشئ أول مجلد لتنظيم صورك.
        </p>
    @else
        <div class="max-h-52 overflow-y-auto">
            @foreach ($folders as $folder)
                <div class="group/folder flex items-center">
                    <a
                        href="{{ route('studio.index', ['folder' => $folder->id]) }}"
                        class="flex-1 min-w-0 flex items-center gap-2.5 px-2.5 min-h-10 rounded-lg text-sm font-medium
                               {{ $activeFolder === $folder->id ? 'bg-muted text-fg' : 'text-fg-muted hover:bg-muted' }}"
                    >
                        <x-icon name="folder" class="w-4 h-4 shrink-0" />
                        <span class="truncate">{{ $folder->name }}</span>
                    </a>

                    <x-confirm
                        :action="route('studio.folders.destroy', $folder)"
                        :title="'حذف مجلد «'.$folder->name.'»؟'"
                        message="الصور بداخله تبقى محفوظة وتعود إلى «الكل» — لا شيء يُحذف من صورك."
                        confirm="حذف المجلد"
                        label="حذف المجلد"
                        class="opacity-0 group-hover/folder:opacity-100 shrink-0"
                    />
                </div>
            @endforeach
        </div>
    @endif

    <div class="divider my-2"></div>

    <button type="button" @click="foldersOpen = false; startSelecting()"
            class="flex items-center gap-2.5 w-full px-2.5 min-h-9 rounded-lg text-xs text-fg-muted hover:bg-muted hover:text-fg">
        <x-icon name="check-circle" class="w-3.5 h-3.5" />
        تحديد للحذف أو النقل الجماعي
    </button>
</div>
