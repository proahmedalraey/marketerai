@extends('layouts.public')

@section('title', $title)

@section('content')

{{-- مكان محجوز حتى تُعتمد السياسة: الرابط يعمل من التذييل ومن موافقة التسجيل دون وعود لم تُكتب --}}
<section class="relative isolate overflow-hidden bg-gradient-to-b from-amber-50 to-white pb-24 pt-32">
    <div class="mx-auto max-w-2xl px-4 text-center">
        <span class="mx-auto grid h-14 w-14 place-items-center rounded-2xl border border-gray-200 bg-white text-primary shadow-sm">
            <x-public.icon name="file-text" class="h-7 w-7" />
        </span>
        <h1 class="mt-6 text-3xl font-bold text-gray-900 md:text-4xl">{{ $title }}</h1>
        <p class="mt-4 text-lg leading-relaxed text-gray-600">نُعدّ نص هذه السياسة الآن، وسيُنشر هنا قبل إطلاق الاشتراكات المدفوعة.</p>
        @if (config('marketing.contact.email'))
            <p class="mt-2 text-gray-600">
                لأي سؤال عنها راسلنا على
                <a href="mailto:{{ config('marketing.contact.email') }}" class="font-bold text-primary-ink hover:underline" dir="ltr">{{ config('marketing.contact.email') }}</a>
            </p>
        @endif
        <a href="{{ route('home') }}" class="mt-8 inline-flex items-center gap-2 rounded-full border border-gray-300 bg-white px-6 py-3 font-semibold text-gray-700 transition-colors hover:bg-gray-50">
            <x-public.icon name="arrow-right" class="h-5 w-5" />
            العودة للرئيسية
        </a>
    </div>
</section>

@endsection
