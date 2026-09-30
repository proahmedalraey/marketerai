{{-- شعار «مسوق AI»: بلاطة الشرارة، والاسم العربي وتحته الاسم اللاتيني --}}
<span {{ $attributes->class('inline-flex items-center gap-2.5') }}>
    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-primary text-white shadow-md shadow-primary/30">
        <x-icon name="sparkles" class="h-5 w-5" />
    </span>
    <span class="flex flex-col items-start leading-none">
        <span class="font-cairo text-[1.6rem] font-extrabold leading-[1.05] text-gray-900">مسوّق</span>
        {{-- ذيل «ق» ينزل تحت السطر؛ المسافة تمنعه من ملامسة الاسم اللاتيني --}}
        <span class="mt-[0.4rem] font-cairo text-[0.56rem] font-bold leading-none tracking-[0.22em] text-gray-500" dir="ltr">MARKETER AI</span>
    </span>
</span>
