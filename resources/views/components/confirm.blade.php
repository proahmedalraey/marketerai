@props([
    'action',
    'method' => 'DELETE',
    'title' => 'تأكيد الحذف',
    'message' => 'لا يمكن التراجع عن هذا الإجراء.',
    'confirm' => 'حذف نهائياً',
    'label' => 'حذف',
    'icon' => 'trash',
    'iconOnly' => true,
])

@php $uid = 'cf-'.\Illuminate\Support\Str::random(8); @endphp

{{--
    يستبدل confirm() الأصلي. المتصفح الأصلي يوقف كل الصفحة، لا يمكن تنسيقه،
    ولا يذكر ماذا سيُحذف. الحوار هنا يسمّي العنصر ويُرجع التركيز لمكانه.
--}}
<div
    x-data="{ open: false }"
    x-effect="document.documentElement.classList.toggle('overflow-hidden', open)"
    class="contents"
>
    <button
        type="button"
        @click="open = true"
        aria-haspopup="dialog"
        :aria-expanded="open"
        {{ $attributes->merge([
            'class' => $iconOnly
                ? 'btn btn-ghost btn-sm btn-icon text-fg-subtle hover:text-danger-fg hover:bg-danger-soft'
                : 'btn btn-ghost btn-sm text-fg-muted hover:text-danger-fg hover:bg-danger-soft',
        ]) }}
    >
        <x-icon :name="$icon" class="w-4 h-4" />
        @if ($iconOnly)
            <span class="sr-only">{{ $label }}</span>
        @else
            <span>{{ $label }}</span>
        @endif
    </button>

    <template x-teleport="body">
        <div
            x-show="open"
            x-cloak
            @keydown.escape.window="open = false"
            class="fixed inset-0 z-[60] grid place-items-center p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="{{ $uid }}-title"
            aria-describedby="{{ $uid }}-desc"
        >
            <div
                x-show="open"
                x-transition.opacity.duration.150ms
                @click="open = false"
                class="absolute inset-0 bg-scrim/50 backdrop-blur-sm"
                aria-hidden="true"
            ></div>

            <div
                x-show="open"
                x-transition:enter="motion-safe:animate-scale-in"
                x-init="$watch('open', v => v && $nextTick(() => $refs.cancel?.focus()))"
                @keydown.tab="
                    const f = $el.querySelectorAll('button, [href], input, select, textarea');
                    if (!f.length) return;
                    const first = f[0], last = f[f.length - 1];
                    if ($event.shiftKey && document.activeElement === first) { $event.preventDefault(); last.focus(); }
                    else if (!$event.shiftKey && document.activeElement === last) { $event.preventDefault(); first.focus(); }
                "
                class="relative w-full max-w-sm card shadow-pop p-6 text-center"
            >
                <div class="mx-auto grid place-items-center w-12 h-12 rounded-full bg-danger-soft text-danger-fg mb-4">
                    <x-icon name="alert" class="w-6 h-6" />
                </div>

                <h2 id="{{ $uid }}-title" class="text-base font-bold text-fg">{{ $title }}</h2>
                <p id="{{ $uid }}-desc" class="mt-2 text-sm text-fg-muted leading-relaxed">{{ $message }}</p>

                <div class="mt-6 flex gap-2.5">
                    <button type="button" x-ref="cancel" @click="open = false" class="btn-secondary flex-1">
                        إلغاء
                    </button>

                    <form method="POST" action="{{ $action }}" class="flex-1" data-busy-on-submit>
                        @csrf
                        @method($method)
                        <button type="submit" class="btn bg-danger text-white hover:brightness-110 w-full">
                            <span>{{ $confirm }}</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </template>
</div>
