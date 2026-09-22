@props([
    'icon' => 'inbox',
    'title',
    'description' => null,
])

{{--
    الحالة الفارغة ليست رسالة خطأ: هي أول درس يتعلمه المستخدم عن الشاشة.
    لذلك: أيقونة هادئة + سبب الفراغ + فعل واحد واضح يخرجه منه.
--}}
<div {{ $attributes->merge(['class' => 'card px-6 py-12 text-center']) }}>
    <div class="mx-auto grid place-items-center w-14 h-14 rounded-2xl bg-muted text-fg-subtle mb-4">
        <x-icon :name="$icon" class="w-7 h-7" />
    </div>

    <h3 class="text-base font-semibold text-fg">{{ $title }}</h3>

    @if ($description)
        <p class="mt-1.5 text-sm text-fg-muted max-w-sm mx-auto leading-relaxed">{{ $description }}</p>
    @endif

    @if (trim($slot) !== '')
        <div class="mt-5 flex flex-wrap items-center justify-center gap-2.5">{{ $slot }}</div>
    @endif
</div>
