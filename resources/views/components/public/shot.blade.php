@props(['src', 'alt' => ''])

{{-- لقطة حقيقية من المنصة داخل إطار شاشة فوق خلفية برتقالية، على طريقة صور المميزات --}}
<div {{ $attributes->class('absolute inset-0 overflow-hidden bg-gradient-to-br from-amber-50 via-orange-50 to-white') }}>
    <span class="absolute -right-12 -top-20 h-44 w-72 -rotate-[18deg] rounded-[3rem] bg-gradient-to-l from-primary to-orange-400" aria-hidden="true"></span>
    <span class="absolute -bottom-10 -left-10 h-28 w-48 -rotate-[18deg] rounded-[3rem] bg-orange-200/60" aria-hidden="true"></span>

    <div class="absolute inset-x-[9%] bottom-0 top-[13%] overflow-hidden rounded-t-xl border-[5px] border-b-0 border-gray-900 bg-gray-900 shadow-2xl transition-transform duration-500 group-hover:scale-[1.03]">
        <img src="{{ $src }}" alt="{{ $alt }}" loading="lazy" decoding="async" class="h-full w-full object-cover object-top">
    </div>
</div>
