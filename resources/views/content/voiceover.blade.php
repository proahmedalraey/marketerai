@extends('layouts.app')
@section('title', 'التعليق الصوتي')
@section('title_badge')
    <span class="nav-badge ms-0" title="ميزة تجريبية: قد تتغير الأصوات والأسعار">Beta</span>
@endsection
@section('subtitle', 'حوّل نصوصك إلى تعليق صوتي بصوت مذيع ولهجة متجرك')

@section('content')
<div x-data="voiceoverStudio(@js($studio))" class="space-y-4">

    {{-- إعلان لقارئ الشاشة: ما نقص، وما بدأ، وما جهز --}}
    <p class="sr-only" role="status" aria-live="polite" x-text="announcement"></p>

    {{-- مشغّل واحد للسجل كله: تبديل الملف لا يُنشئ عنصراً جديداً --}}
    <audio
        x-ref="audio" preload="none" class="hidden"
        @play="player.playing = true" @pause="player.playing = false" @ended="player.playing = false"
        @timeupdate="onTime()" @loadedmetadata="onTime()"
    ></audio>

    @unless ($studio['speechReady'])
        <div class="alert-warning" role="alert">
            <x-icon name="alert" class="w-5 h-5 shrink-0 mt-px" />
            <p class="flex-1 leading-relaxed">
                التعليق الصوتي غير مفعّل بعد: يحتاج مفتاح Gemini في إعدادات الذكاء الاصطناعي.
                @can('manage-platform')
                    <a href="{{ route('settings.ai') }}" class="font-semibold underline underline-offset-4">افتح الإعدادات</a>
                @else
                    تواصل مع مدير المنصة لتفعيله.
                @endcan
            </p>
        </div>
    @endunless

    <div class="grid gap-5 items-start lg:grid-cols-[minmax(0,1fr)_20rem] xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="min-w-0 space-y-4">
            @include('content.voiceover._style')
            @include('content.voiceover._content')
            @include('content.voiceover._language')
            @include('content.voiceover._voice')
            @include('content.voiceover._actions')
        </div>

        @include('content.voiceover._history')
    </div>
</div>
@endsection
