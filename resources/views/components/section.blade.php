@props([
    'title' => null,
    'description' => null,
    'icon' => null,
    'step' => null,
    'actions' => null,
    'flush' => false,   // true = بلا حشو داخلي (لقوائم تمتد لحافة البطاقة)
])

<section {{ $attributes->merge(['class' => 'card']) }}>
    @if ($title)
        <header class="flex items-start gap-3 p-5 {{ $flush ? 'pb-4 border-b border-line' : 'pb-0' }}">
            @if ($step)
                <span
                    class="grid place-items-center w-7 h-7 shrink-0 rounded-lg bg-brand-600 text-white text-xs font-bold tnum mt-0.5"
                    aria-hidden="true"
                >{{ $step }}</span>
            @elseif ($icon)
                <span class="grid place-items-center w-9 h-9 shrink-0 rounded-xl bg-muted text-fg-muted">
                    <x-icon :name="$icon" class="w-[18px] h-[18px]" />
                </span>
            @endif

            <div class="min-w-0 flex-1">
                <h2 class="text-[15px] font-bold text-fg">{{ $title }}</h2>
                @if ($description)
                    <p class="mt-1 text-sm text-fg-muted leading-relaxed">{{ $description }}</p>
                @endif
            </div>

            @if ($actions)
                <div class="shrink-0 flex items-center gap-2">{{ $actions }}</div>
            @endif
        </header>
    @endif

    <div class="{{ $flush ? '' : ($title ? 'p-5 pt-4' : 'p-5') }}">
        {{ $slot }}
    </div>
</section>
