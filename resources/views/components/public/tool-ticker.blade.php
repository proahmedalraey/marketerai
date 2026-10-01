@props(['size' => 'sm', 'suffix' => null])

@php
    $tools = config('marketing.tools');
    [$chip, $icon] = $size === 'sm' ? ['h-6 w-6 rounded-md', 'h-3.5 w-3.5'] : ['h-7 w-7 rounded-lg', 'h-4 w-4'];
@endphp

{{-- أداة وفعلها من مشهد التجميع يتبدّلان كل 2.4 ثانية — CSS فقط (app.css: .tool-ticker).
     كلها في الخلية نفسها، فعرض الشريط عرض أطول عبارة ولا يقفز ما حوله؛
     واللاحقة («مع مسوق AI») داخل كل عبارة كي لا يترك الفعل القصير فراغًا قبلها. --}}
<span {{ $attributes->class('tool-ticker') }} aria-hidden="true">
    @foreach ($tools as $i => $tool)
        <span class="inline-flex items-center gap-1.5 whitespace-nowrap" style="--i: {{ $i }}">
            <span class="grid {{ $chip }} shrink-0 place-items-center {{ $tool['color'] }}">
                <x-public.icon :name="$tool['icon']" class="{{ $icon }}" stroke="2" />
            </span>
            <span>{{ $tool['verb'] }}@if ($suffix) <span class="text-gray-500">{{ $suffix }}</span>@endif</span>
        </span>
    @endforeach
</span>
