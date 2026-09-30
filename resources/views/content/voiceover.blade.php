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
        {{-- مسار الخطوات: كل قسم خطوة، والخط يُملأ كلما اكتملت واحدة حتى زر التوليد --}}
        @php
            $steps = ['style' => 'نمط الإلقاء', 'content' => 'المحتوى', 'language' => 'اللغة واللهجة', 'voice' => 'إعدادات الصوت'];
        @endphp

        <ol class="vo-steps min-w-0 space-y-4" aria-label="خطوات التعليق الصوتي">
            @foreach ($steps as $step => $label)
                <li
                    class="vo-step" :data-state="stepState('{{ $step }}')"
                    @if ($loop->last) style="--next-dot: 1.5rem" @endif
                >
                    <span class="vo-step-dot" aria-hidden="true">
                        <x-icon name="check" stroke="3" class="w-3.5 h-3.5" x-show="stepState('{{ $step }}') === 'done'" />
                        <span x-show="stepState('{{ $step }}') !== 'done'">{{ $loop->iteration }}</span>
                    </span>
                    <span class="sr-only" x-text="`الخطوة {{ $loop->iteration }} ({{ $label }}): ${stepState('{{ $step }}') === 'done' ? 'مكتملة' : 'غير مكتملة'}`"></span>

                    @include('content.voiceover._'.$step)
                </li>
            @endforeach

            <li class="vo-step vo-step-final" :data-state="complete ? 'done' : 'todo'">
                <span class="vo-step-dot" aria-hidden="true">
                    <x-icon name="sparkles" class="w-3.5 h-3.5" />
                </span>

                @include('content.voiceover._actions')
            </li>
        </ol>

        @include('content.voiceover._history')
    </div>
</div>
@endsection
