@extends('layouts.public')

@use('App\Support\Contact')

@section('title', 'تواصل معنا')
@section('description', 'تواصل مع فريق مسوق AI: خدمة المبيعات، خدمة العملاء، والشكاوى.')

@section('content')

{{-- وجهة روابط «تواصل معنا» في التذييل حين لا يُضبط واتساب ولا بريد؛ كل قناة لها قسم برابط مباشر (#sales…) --}}
<section class="relative isolate overflow-hidden bg-gradient-to-b from-amber-50 to-white pb-24 pt-32">
    <div class="absolute left-1/2 top-24 -z-10 h-40 w-[36rem] -translate-x-1/2 rounded-full bg-amber-100/60 blur-3xl" aria-hidden="true"></div>

    <div class="mx-auto max-w-5xl px-4 md:px-6">
        <div class="text-center">
            <span class="inline-flex items-center gap-2 rounded-full border border-amber-200 bg-amber-100 px-4 py-1.5 text-sm font-semibold text-amber-800">
                <x-public.icon name="thread" class="h-4 w-4 text-primary" />
                نحن هنا لمساعدتك
            </span>
            <h1 class="mt-6 text-4xl font-bold text-gray-900 md:text-5xl">تواصل معنا</h1>
            <p class="mx-auto mt-4 max-w-xl text-lg text-gray-600">اختر الجهة المناسبة لسؤالك، ونرد عليك في أقرب وقت.</p>
        </div>

        <div class="mt-12 grid gap-5 md:grid-cols-3">
            @foreach (config('marketing.channels') as $key => $channel)
                <article id="{{ $key }}" class="flex scroll-mt-28 flex-col rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition-shadow target:border-primary target:shadow-lg target:shadow-amber-100/60">
                    <span class="grid h-12 w-12 place-items-center rounded-xl bg-amber-50 text-primary">
                        <x-public.icon :name="$channel['icon']" class="h-6 w-6" />
                    </span>
                    <h2 class="mt-5 text-xl font-bold text-gray-900">{{ $channel['label'] }}</h2>
                    <p class="mt-2 flex-1 text-sm leading-relaxed text-gray-600">{{ $channel['text'] }}</p>

                    @if (Contact::hasDirect())
                        <div class="mt-6 flex flex-wrap gap-2">
                            @if ($whatsapp = Contact::whatsappLink($key))
                                <a href="{{ $whatsapp }}" target="_blank" rel="noopener" class="inline-flex items-center gap-2 rounded-full bg-[#1F8F4E] px-4 py-2.5 text-sm font-bold text-white transition-colors hover:bg-[#18733E]">
                                    <x-public.icon name="whatsapp" class="h-4 w-4" />
                                    واتساب
                                </a>
                            @endif
                            @if ($email = Contact::emailLink($key))
                                <a href="{{ $email }}" class="inline-flex items-center gap-2 rounded-full border border-gray-300 bg-white px-4 py-2.5 text-sm font-bold text-gray-700 transition-colors hover:bg-gray-50">
                                    <x-public.icon name="mail" class="h-4 w-4" />
                                    البريد الإلكتروني
                                </a>
                            @endif
                        </div>
                    @else
                        <p class="mt-6 rounded-xl bg-gray-50 px-4 py-3 text-sm text-gray-500">قنوات التواصل قيد الإعداد، وستظهر هنا قريبًا.</p>
                    @endif
                </article>
            @endforeach
        </div>
    </div>
</section>

@endsection
