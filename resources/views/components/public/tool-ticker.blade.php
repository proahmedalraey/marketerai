@props(['size' => 'sm', 'text' => 'verb'])

@php
    $tools = config('marketing.tools');
    [$chip, $icon] = $size === 'sm' ? ['h-6 w-6 rounded-md', 'h-3.5 w-3.5'] : ['h-7 w-7 rounded-lg', 'h-4 w-4'];
@endphp

{{-- أداة ونصها من مشهد التجميع يتبدّلان كل 2.4 ثانية — CSS فقط (app.css: .tool-ticker).
     text: «verb» فعل قصير للترويسة، «phrase» عبارة فائدة للتذييل.
     كلها في الخلية نفسها، فعرض الشريط عرض أطول عبارة ولا يقفز ما حوله. --}}
<span {{ $attributes->class('tool-ticker') }} aria-hidden="true">
    @foreach ($tools as $i => $tool)
        <span class="inline-flex items-center gap-1.5 whitespace-nowrap" style="--i: {{ $i }}">
            <span class="grid {{ $chip }} shrink-0 place-items-center {{ $tool['color'] }}">
                <x-public.icon :name="$tool['icon']" class="{{ $icon }}" stroke="2" />
            </span>
            <span>{{ $tool[$text] }}</span>
        </span>
    @endforeach
</span>
