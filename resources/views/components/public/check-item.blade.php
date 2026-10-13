{{-- سطر ميزة في بطاقة باقة: علامة صح خضراء والنص بجانبها، ويلتف السطر الطويل تحت النص لا تحت العلامة --}}
<li {{ $attributes->class('flex items-start gap-3') }}>
    <span class="mt-0.5 grid h-5 w-5 shrink-0 place-items-center rounded-full bg-green-100 text-green-600">
        <x-public.icon name="check" class="h-3 w-3" stroke="3" />
    </span>
    <span class="text-[15px] leading-relaxed text-gray-700">{{ $slot }}</span>
</li>
